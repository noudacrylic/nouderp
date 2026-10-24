<?php

namespace Tests\Feature\KaryawanVirtual;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\StockReservation;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualJurnal;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualSkill;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\BomOutput;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Sales\Models\SalesAdvance;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Karyawan Virtual "Manager Produksi" membungkus auto-produksi yang sudah ada.
 * Yang dijaga: mode Otomatis berperilaku persis seperti sebelumnya, Bayangan
 * memeriksa tanpa membuat apa pun, Nonaktif diam; jurnal hanya berisi kerja
 * yang ada tindakannya; halaman ikut akses menu divisi; mode hanya diubah
 * super admin dan skill pre-order terkunci otomatis.
 */
class KaryawanVirtualTest extends TestCase
{
    use RefreshDatabase;

    private const STOK = 'produksi.op-stok-menipis';
    private const PREORDER = 'produksi.op-preorder';

    private function gudang(): Warehouse
    {
        return Warehouse::firstOrCreate(['name' => 'Gudang Utama'], ['is_sellable' => true, 'is_active' => true]);
    }

    /** Produk ready + BOM auto, stok tersedia = $stok, min stok 5. */
    private function produkReady(float $stok): Product
    {
        $p = Product::create([
            'sku' => 'RD-' . uniqid(), 'name' => 'Produk Ready', 'sale_type' => 'ready',
            'base_unit' => 'pcs', 'base_price' => 50000, 'is_active' => true, 'min_stock' => 5,
        ]);
        $bom = Bom::create([
            'bom_number' => 'BOM-' . uniqid(), 'name' => 'BOM ' . $p->sku,
            'auto_production' => true, 'typical_cycles' => 1,
        ]);
        BomOutput::create([
            'bom_id' => $bom->id, 'product_id' => $p->id,
            'qty_per_cycle' => 10, 'output_type' => 'main', 'percentage' => 100,
        ]);
        ProductStock::create(['product_id' => $p->id, 'warehouse_id' => $this->gudang()->id, 'qty_on_hand' => $stok]);

        return $p;
    }

    private function opCount(Product $p): int
    {
        return ProductionOrder::whereHas('outputs', fn ($q) => $q->where('product_id', $p->id))->count();
    }

    private function kv(): KaryawanVirtualService
    {
        return app(KaryawanVirtualService::class);
    }

    private function user(string $role = 'user', array $menus = []): User
    {
        $u = User::factory()->create(['role' => $role, 'is_active' => true]);
        foreach ($menus as $m) {
            $u->menuPermissions()->create(['menu_key' => $m]);
        }
        return $u->fresh();
    }

    // ───────────── Mode ─────────────

    public function test_otomatis_bawaan_membuat_op_dan_mencatat_jurnal(): void
    {
        $p = $this->produkReady(0);

        $this->artisan('production:run-auto')->assertSuccessful();

        $this->assertSame(1, $this->opCount($p), 'Mode bawaan Otomatis = perilaku lama: OP dibuat.');
        $j = KaryawanVirtualJurnal::where('skill', self::STOK)->sole();
        $this->assertSame('manager-produksi', $j->karyawan);
        $this->assertSame('otomatis', $j->mode);
        $this->assertSame('jadwal', $j->pemicu);
        $this->assertSame(1, $j->jumlah_tindakan);
        $this->assertSame('dibuat', $j->rincian[0]['status']);
        $op = ProductionOrder::whereHas('outputs', fn ($q) => $q->where('product_id', $p->id))->sole();
        $this->assertSame($op->order_number, $j->rincian[0]['dokumen']);
        $this->assertSame(route('production.orders.show', $op->id), $j->rincian[0]['tautan']);
    }

    public function test_bayangan_tidak_membuat_op_hanya_mencatat_akan_dibuat(): void
    {
        $p = $this->produkReady(0);
        $this->kv()->ubahMode(self::STOK, 'bayangan', null);

        $this->artisan('production:run-auto')->assertSuccessful();

        $this->assertSame(0, $this->opCount($p), 'Bayangan tidak boleh membuat OP.');
        $j = KaryawanVirtualJurnal::where('skill', self::STOK)->sole();
        $this->assertSame('bayangan', $j->mode);
        $this->assertSame('akan_dibuat', $j->rincian[0]['status']);
        $this->assertStringContainsString('Akan membuat order produksi', $j->rincian[0]['alasan']);
    }

    public function test_nonaktif_tidak_memeriksa_apa_pun(): void
    {
        $p = $this->produkReady(0);
        $this->kv()->ubahMode(self::STOK, 'nonaktif', null);

        $this->artisan('production:run-auto')->assertSuccessful();

        $this->assertSame(0, $this->opCount($p));
        $this->assertSame(0, KaryawanVirtualJurnal::where('skill', self::STOK)->count());
        $this->assertNull(KaryawanVirtualSkill::where('skill', self::STOK)->value('terakhir_jalan_at'));
    }

    public function test_jadwal_tanpa_tindakan_tidak_menulis_jurnal_tapi_menyimpan_alasan(): void
    {
        $p = $this->produkReady(50); // aman, di atas min 5

        $this->artisan('production:run-auto')->assertSuccessful();

        $this->assertSame(0, KaryawanVirtualJurnal::count(), 'Pemeriksaan rutin tanpa tindakan tidak boleh memenuhi jurnal.');
        $state = KaryawanVirtualSkill::where('skill', self::STOK)->sole();
        $this->assertNotNull($state->terakhir_jalan_at);
        $this->assertSame('1 BOM diperiksa: 1 dilewati.', $state->ringkasan_terakhir);
        $this->assertSame('dilewati', $state->rincian_terakhir[0]['status']);
        $this->assertStringContainsString($p->sku, $state->rincian_terakhir[0]['alasan']);
    }

    public function test_mode_preorder_terkunci_otomatis(): void
    {
        $this->expectException(\DomainException::class);
        $this->kv()->ubahMode(self::PREORDER, 'nonaktif', null);
    }

    // ───────────── Pemicu ─────────────

    public function test_tombol_jalankan_auto_produksi_ikut_mode_dan_tercatat_manual(): void
    {
        $p     = $this->produkReady(0);
        $admin = $this->user('super_admin');
        $this->kv()->ubahMode(self::STOK, 'bayangan', $admin->id);

        $this->actingAs($admin)->post(route('production.boms.run-auto'))
            ->assertSessionHas('info', fn ($m) => str_contains($m, 'BAYANGAN'));

        $this->assertSame(0, $this->opCount($p));
        $j = KaryawanVirtualJurnal::where('skill', self::STOK)->sole();
        $this->assertSame('manual', $j->pemicu);
        $this->assertSame($admin->id, $j->user_id);
    }

    public function test_dp_diposting_tercatat_sebagai_peristiwa(): void
    {
        $p = Product::create([
            'sku' => 'PO-' . uniqid(), 'name' => 'Produk Preorder', 'sale_type' => 'preorder',
            'made_to_order' => false, 'base_unit' => 'pcs', 'base_price' => 100000, 'is_active' => true,
        ]);
        $bom = Bom::create(['bom_number' => 'BOM-' . uniqid(), 'name' => 'BOM PO', 'auto_production' => true, 'typical_cycles' => 1]);
        BomOutput::create(['bom_id' => $bom->id, 'product_id' => $p->id, 'qty_per_cycle' => 1, 'output_type' => 'main', 'percentage' => 100]);
        $cust = Customer::create(['code' => 'C-' . uniqid(), 'name' => 'Toko Budi', 'is_marketplace' => false, 'is_active' => true]);
        $so = SalesOrder::create([
            'order_number' => 'SO-KV-1', 'customer_id' => $cust->id, 'warehouse_id' => $this->gudang()->id,
            'order_date' => now()->toDateString(), 'status' => 'confirmed', 'grand_total' => 200000, 'paid_amount' => 100000,
        ]);
        SalesOrderItem::create([
            'sales_order_id' => $so->id, 'product_id' => $p->id, 'qty' => 2, 'conversion_to_base' => 1,
            'unit_price' => 100000, 'net_unit_price' => 100000, 'line_subtotal' => 200000, 'line_discount' => 0, 'line_total' => 200000,
        ]);
        StockReservation::create(['product_id' => $p->id, 'warehouse_id' => $so->warehouse_id, 'sales_order_id' => $so->id, 'qty' => 2, 'status' => 'active']);

        // DP diposting → SalesAdvanceObserver membuat OP pre-order lalu mencatat ke jurnal.
        SalesAdvance::create([
            'advance_number' => 'DP-KV-1', 'sales_order_id' => $so->id, 'customer_id' => $cust->id,
            'bank_account_id' => 1, 'advance_date' => now()->toDateString(), 'amount' => 100000, 'status' => 'posted',
        ]);

        $this->assertSame(1, $this->opCount($p));
        $j = KaryawanVirtualJurnal::where('skill', self::PREORDER)->sole();
        $this->assertSame('peristiwa', $j->pemicu);
        $this->assertSame('DP diposting untuk SO-KV-1', $j->keterangan_pemicu);
        $this->assertStringStartsWith('SO-KV-1 · ' . $p->sku, $j->rincian[0]['judul']);
        // Peristiwa satu pesanan bukan pemeriksaan menyeluruh → hasil terakhir tidak ditimpa.
        $this->assertNull(KaryawanVirtualSkill::where('skill', self::PREORDER)->value('terakhir_jalan_at'));
    }

    // ───────────── Halaman & akses ─────────────

    public function test_halaman_ikut_akses_menu_produksi(): void
    {
        $kepalaProduksi = $this->user('user', ['production.orders']);
        $staffGudang    = $this->user('user', ['inventory.stocks']);

        $this->actingAs($kepalaProduksi)->get(route('karyawan-virtual.index'))
            ->assertOk()->assertSee('Manager Produksi');
        $this->actingAs($kepalaProduksi)->get(route('karyawan-virtual.show', 'manager-produksi'))
            ->assertOk()->assertSee('Buat OP saat stok menipis')->assertSee('Jurnal Kerja');
        $this->actingAs($kepalaProduksi)->get(route('production.orders.index'))
            ->assertSee(route('karyawan-virtual.index'), false); // ada di sidebar

        $this->actingAs($staffGudang)->get(route('karyawan-virtual.index'))->assertForbidden();
        $this->actingAs($staffGudang)->get(route('karyawan-virtual.show', 'manager-produksi'))->assertForbidden();
        $this->actingAs($staffGudang)->get(route('inventory.stocks.index'))
            ->assertDontSee(route('karyawan-virtual.index'), false);
    }

    public function test_hanya_super_admin_yang_mengubah_mode(): void
    {
        $kepalaProduksi = $this->user('user', ['production.orders']);
        $admin          = $this->user('admin');
        $super          = $this->user('super_admin');
        $url = route('karyawan-virtual.mode', ['manager-produksi', self::STOK]);

        $this->actingAs($kepalaProduksi)->post($url, ['mode' => 'nonaktif'])->assertForbidden();
        $this->actingAs($admin)->post($url, ['mode' => 'nonaktif'])->assertForbidden();
        $this->assertSame('otomatis', $this->kv()->mode(self::STOK));

        $this->actingAs($super)->post($url, ['mode' => 'bayangan'])->assertSessionHas('success');
        $this->assertSame('bayangan', $this->kv()->mode(self::STOK));
        $this->assertSame($super->id, KaryawanVirtualSkill::where('skill', self::STOK)->value('mode_diubah_oleh'));

        $this->actingAs($super)->post(route('karyawan-virtual.mode', ['manager-produksi', self::PREORDER]), ['mode' => 'nonaktif'])
            ->assertSessionHas('error');
    }

    public function test_jalankan_sekarang_dari_halaman(): void
    {
        $p    = $this->produkReady(0);
        $user = $this->user('user', ['production.orders']);
        $this->kv()->ubahMode(self::STOK, 'bayangan', null);

        $this->actingAs($user)->post(route('karyawan-virtual.jalankan', ['manager-produksi', self::STOK]))
            ->assertSessionHas('success', fn ($m) => str_contains($m, '1 akan dibuat') && str_contains($m, 'Bayangan'));

        $this->assertSame(0, $this->opCount($p));
        $this->actingAs($user)->get(route('karyawan-virtual.show', 'manager-produksi'))
            ->assertOk()->assertSee('1 akan dibuat (bayangan)')->assertSee($p->sku)->assertSee('👻', false);
    }
}
