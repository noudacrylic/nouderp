<?php

namespace Tests\Feature\Tasks;

use App\Core\Accounting\Account;
use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\Warehouse;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualSkill;
use App\Modules\Purchasing\Models\PengajuanBayarOwner;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaSkill;
use App\Modules\Tasks\MejaKerja\Skills\IsiSaldoSkill;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Alur beli: kartu Purchasing → PO/faktur → Bayar sendiri / Bayar owner → Selesaikan pesanan,
 * daftar hutang di Meja Owner, dan isi saldo ShopeePay.
 */
class AlurPembelianOwnerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $admin;
    private Supplier $supplier;
    private Warehouse $gudang;
    private Product $bahan;
    private MejaKerjaService $meja;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChartOfAccountSeeder::class);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        $this->owner    = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->admin    = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->supplier = Supplier::create(['code' => 'SUP-1', 'name' => 'Shopee', 'bank_name' => 'BCA', 'bank_account_no' => '123', 'bank_account_name' => 'Budi', 'is_active' => true]);
        $this->gudang   = Warehouse::create(['name' => 'Utama', 'is_sellable' => true, 'is_active' => true]);
        $this->bahan    = Product::create(['sku' => 'KRD', 'name' => 'Kardus', 'sale_type' => 'ready', 'base_unit' => 'pcs', 'base_price' => 0, 'min_stock' => 50, 'is_active' => true]);
        ProductStock::create(['product_id' => $this->bahan->id, 'warehouse_id' => $this->gudang->id, 'qty_on_hand' => 5]);

        MejaKerjaPeran::create(['peran' => 'owner', 'user_id' => $this->owner->id, 'urutan' => 1]);
        MejaKerjaPeran::create(['peran' => 'layanan', 'user_id' => $this->admin->id, 'urutan' => 1]);
        foreach (['1102' => 'Bank BCA', IsiSaldoSkill::AKUN => 'Shopee Pay'] as $kode => $nama) {
            Account::firstOrCreate(['code' => $kode], ['name' => $nama, 'type' => 'asset', 'normal_balance' => 'debit', 'is_active' => 1])
                ->update(['is_cash_account' => 1, 'is_active' => 1, 'account_category' => 'cash_equivalent']);
        }

        foreach (['purchasing-ready', 'selesaikan-pesanan'] as $s) {
            MejaKerjaSkill::updateOrCreate(['skill' => $s], ['peran' => 'layanan', 'bobot' => 6, 'sla_menit' => 240]);
            KaryawanVirtualSkill::updateOrCreate(['skill' => "admin.$s"], ['mode' => 'otomatis', 'mode_diubah_at' => now()->subDay()]);
        }
        $this->meja = app(MejaKerjaService::class);

        // Skill Purchasing hanya memantau barang yang pernah dibeli.
        $lama = $this->po();
        $lama->update(['created_at' => now()->subDays(40), 'po_date' => now()->subDays(40)]);
        PurchaseOrderItem::where('purchase_order_id', $lama->id)->update(['qty_invoiced' => 10]);
    }

    private function po(float $qty = 10, float $harga = 10000): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO/TES/' . uniqid(), 'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->gudang->id,
            'po_date' => today(), 'subtotal' => $qty * $harga, 'total' => $qty * $harga, 'status' => 'posted',
        ]);
        PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'product_id' => $this->bahan->id, 'qty' => $qty, 'qty_invoiced' => 0, 'unit' => 'pcs', 'price' => $harga, 'subtotal' => $qty * $harga, 'total' => $qty * $harga]);

        return $po;
    }

    private function kartuBeli(): Task
    {
        $this->meja->sinkron();

        return Task::where('skill', 'purchasing-ready')->firstOrFail();
    }

    private function rekening(string $kode = '1102'): int
    {
        return (int) Account::where('code', $kode)->value('id');
    }

    private function jawab(User $u, Task $t, string $jawaban)
    {
        return $this->actingAs($u)->post(route('tasks.meja.jawab', $t->id), ['jawaban' => $jawaban])->assertRedirect();
    }

    private function kartu(string $skill): Task
    {
        return Task::where('skill', $skill)->latest('id')->firstOrFail();
    }

    public function test_kartu_beli_punya_tombol_po_dan_faktur_lalu_tombol_bayar_saat_kembali_ke_meja(): void
    {
        $t = $this->kartuBeli();
        $aksi = collect($this->meja->skill('purchasing-ready')->aksi($t))->pluck('label');
        $this->assertTrue($aksi->contains('Buat PO') && $aksi->contains('Buat Faktur'));
        $this->actingAs($this->admin)->get(route('purchasing.invoices.create', ['product_id' => $this->bahan->id]))->assertOk()->assertSee('KRD');

        $this->travel(1)->minutes();
        $po = $this->po();

        // Kembali ke Meja Kerja: tombol langsung berganti tanpa menunggu sinkron.
        $this->actingAs($this->admin)->get(route('tasks.meja.index'))->assertOk()->assertSee('Bayar sendiri')->assertSee('Bayar owner')->assertDontSee('Buat Faktur');
        $this->assertSame($po->id, $t->fresh()->meta['po_id']);
        $this->assertSame('open', $t->fresh()->status);
    }

    public function test_bayar_sendiri_lalu_selesaikan_pesanan_membuat_faktur_stok_dan_lunas(): void
    {
        $t = $this->kartuBeli();
        $this->travel(1)->minutes();
        $po = $this->po();
        $this->meja->sinkron();

        $this->actingAs($this->admin)->get(route('purchasing.payments.create', ['supplier_id' => $this->supplier->id, 'po_id' => $po->id, 'jumlah' => 100000]))
            ->assertOk()->assertSee('name="purchase_order_id" value="' . $po->id . '"', false);
        $this->post(route('purchasing.payments.store'), [
            'supplier_id' => $this->supplier->id, 'purchase_order_id' => $po->id, 'payment_date' => today()->toDateString(),
            'bank_account_id' => $this->rekening(IsiSaldoSkill::AKUN), 'amount' => 100000, '_after_save' => 'post',
        ])->assertSessionHasNoErrors();
        $this->assertSame($po->id, SupplierPayment::latest('id')->value('purchase_order_id'));

        // Pembayaran selesai: kartu beli tutup, kartu Pending Selesaikan pesanan muncul.
        $this->get(route('tasks.meja.index'))->assertSee('Selesaikan pesanan');
        $this->assertSame('done', $t->fresh()->status);
        $selesaikan = $this->kartu('selesaikan-pesanan');
        $this->assertSame('pending', $selesaikan->status);
        $this->assertNull($selesaikan->tenggat_at);

        $this->post(route('tasks.meja.selesaikan', $selesaikan->id))->assertSessionHasNoErrors()->assertSessionHas('success');

        $faktur = PurchaseInvoice::where('purchase_order_id', $po->id)->firstOrFail();
        $this->assertSame('posted', $faktur->status);
        $this->assertEqualsWithDelta(0, (float) $faktur->outstanding_amount, 0.01, 'DP otomatis terpakai.');
        $this->assertEqualsWithDelta(10, (float) \App\Core\Inventory\StockLayer::where('product_id', $this->bahan->id)->sum('qty_in'), 0.01, 'Stok masuk sesuai sisa PO.');
        $this->assertSame('done', $selesaikan->fresh()->status);
    }

    public function test_po_buatan_menu_langsung_dapat_kartu_selesaikan_dan_faktur_manual_menutupnya(): void
    {
        $po = $this->po();
        $this->meja->sinkron();
        $t = $this->kartu('selesaikan-pesanan');
        $this->assertSame('pending', $t->status);
        $this->assertSame($po->id, $t->taskable_id);

        PurchaseOrderItem::where('purchase_order_id', $po->id)->update(['qty_invoiced' => 10]);
        $this->meja->sinkron();
        $this->assertSame('done', $t->fresh()->status);
        $this->assertSame(1, Task::where('skill', 'selesaikan-pesanan')->count());
    }

    public function test_selesaikan_gagal_tidak_meninggalkan_faktur_dan_kartu_tetap_pending(): void
    {
        $po = $this->po();
        $this->meja->sinkron();
        $t = $this->kartu('selesaikan-pesanan');
        $this->bahan->update(['sale_type' => 'non_stock', 'expense_account_id' => null]);

        $this->actingAs($this->admin)->post(route('tasks.meja.selesaikan', $t->id))->assertSessionHas('error');

        $this->assertSame(0, PurchaseInvoice::where('purchase_order_id', $po->id)->count());
        $this->assertSame('pending', $t->fresh()->status);
    }

    public function test_bayar_owner_faktur_tempo_jadi_hutang_dicicil_lalu_lunas(): void
    {
        $t = $this->kartuBeli();
        $this->travel(1)->minutes();
        $faktur = app(\App\Modules\Purchasing\Services\PurchaseInvoiceService::class)->createDraft([
            'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->gudang->id, 'invoice_date' => today()->toDateString(),
            'due_date' => today()->addDays(30)->toDateString(),
            'items' => [['product_id' => $this->bahan->id, 'qty' => 10, 'price' => 10000, 'unit' => 'pcs']],
        ]);
        app(\App\Modules\Purchasing\Services\PurchaseInvoicePostingService::class)->post($faktur);

        $this->actingAs($this->admin)->get(route('tasks.meja.index'))->assertSee('Bayar owner');
        $this->jawab($this->admin, $t->fresh(), 'bayar-owner');
        $this->assertSame('done', $t->fresh()->status, 'Tugas admin selesai.');

        $this->meja->sinkron();
        $kartu = $this->kartu('bayar-owner');
        $this->assertSame('owner', $kartu->peran);
        $this->actingAs($this->owner)->get(route('tasks.meja.index'))->assertSee($faktur->invoice_number)->assertSee('Bayar tempo');

        $this->jawab($this->owner, $kartu, 'tempo');
        $kartu->refresh();
        $this->assertSame('pending', $kartu->status);
        $this->actingAs($this->owner)->get(route('tasks.meja.index'))->assertSee('Hutang tempo')->assertSee($faktur->invoice_number)->assertDontSee('▶ Lanjutkan');

        // Cicilan: tetap Pending dengan sisa terbaru.
        $this->post(route('tasks.meja.bayar', $kartu->id), ['rekening' => $this->rekening(), 'nominal' => '40.000'])->assertSessionHas('success');
        $kartu->refresh();
        $this->assertSame('pending', $kartu->status);
        $this->meja->sinkron();
        $this->assertStringContainsString('sisa Rp60.000', $kartu->fresh()->title);
        $this->assertEqualsWithDelta(60000, (float) $faktur->fresh()->outstanding_amount, 0.01);

        // Lebih dari sisa ditolak.
        $this->post(route('tasks.meja.bayar', $kartu->id), ['rekening' => $this->rekening(), 'nominal' => '70.000'])->assertSessionHas('error');

        // Pelunasan: lewat kartu → selesai sendiri, admin dikabari.
        $this->post(route('tasks.meja.bayar', $kartu->id), ['rekening' => $this->rekening(), 'nominal' => '60.000'])->assertSessionHas('success');
        $this->assertSame('done', $kartu->fresh()->status);
        $this->assertDatabaseHas('erp_notifications', ['user_id' => $this->admin->id, 'judul' => 'Owner sudah membayar']);
    }

    public function test_po_dibayar_owner_dp_lalu_sisa_faktur_muncul_di_kartu_yang_sama(): void
    {
        $t = $this->kartuBeli();
        $this->travel(1)->minutes();
        $po = $this->po();
        $this->meja->sinkron();
        $this->jawab($this->admin, $t->fresh(), 'bayar-owner');
        $this->assertSame('done', $t->fresh()->status);

        $this->meja->sinkron();
        $kartu = $this->kartu('bayar-owner');
        $this->assertSame('pending', $this->kartu('selesaikan-pesanan')->status, 'Admin tinggal menunggu barang.');

        // DP 40rb → masuk Pending, tercatat sebagai DP tertaut PO.
        $this->actingAs($this->owner)->post(route('tasks.meja.bayar', $kartu->id), ['rekening' => $this->rekening(), 'nominal' => '40.000'])->assertSessionHas('success');
        $this->assertSame('pending', $kartu->fresh()->status);
        $this->assertSame($po->id, SupplierPayment::latest('id')->value('purchase_order_id'));

        // Barang datang: faktur memakai DP, sisa 60rb tampil di kartu yang sama tanpa aksi admin.
        $this->actingAs($this->admin)->post(route('tasks.meja.selesaikan', $this->kartu('selesaikan-pesanan')->id))->assertSessionHas('success');
        $this->meja->sinkron();
        $this->assertSame(1, Task::where('skill', 'bayar-owner')->count());
        $this->assertStringContainsString('sisa Rp60.000', $kartu->fresh()->title);

        $this->actingAs($this->owner)->post(route('tasks.meja.bayar', $kartu->id), ['rekening' => $this->rekening(), 'nominal' => '60.000'])->assertSessionHas('success');
        $this->assertSame('done', $kartu->fresh()->status);
    }

    public function test_hanya_owner_yang_membayar_dan_rekening_harus_rekening_owner(): void
    {
        $p = PengajuanBayarOwner::create(['purchase_order_id' => $this->po()->id, 'diajukan_oleh' => $this->admin->id]);
        $this->meja->sinkron();
        $kartu = Task::where('skill', 'bayar-owner')->where('taskable_id', $p->id)->firstOrFail();

        $this->actingAs($this->admin)->post(route('tasks.meja.bayar', $kartu->id), ['rekening' => $this->rekening(), 'nominal' => '1000'])->assertForbidden();
        $this->actingAs($this->owner)->post(route('tasks.meja.bayar', $kartu->id), ['rekening' => $this->rekening(IsiSaldoSkill::AKUN), 'nominal' => '1000'])->assertStatus(422);
    }

    public function test_isi_saldo_satu_kartu_di_bawah_batas_lalu_selesai_setelah_transfer(): void
    {
        $this->meja->sinkron();
        $this->meja->sinkron();
        $this->assertSame(1, Task::where('skill', 'isi-saldo')->count());
        $kartu = $this->kartu('isi-saldo');
        $this->assertSame('owner', $kartu->peran);
        $this->actingAs($this->owner)->get(route('tasks.meja.index'))->assertSee('Isi saldo')->assertSee('ShopeePay sisa Rp0')->assertSee('3.000.000');

        $this->actingAs($this->admin)->post(route('tasks.meja.isi-saldo', $kartu->id), ['rekening' => $this->rekening(), 'nominal' => '3.000.000'])->assertForbidden();
        $this->actingAs($this->owner)->post(route('tasks.meja.isi-saldo', $kartu->id), ['rekening' => $this->rekening(), 'nominal' => '3.000.000'])->assertSessionHas('success');

        $this->assertSame('done', $kartu->fresh()->status);
        $this->assertEqualsWithDelta(3000000, app(IsiSaldoSkill::class)->saldo(), 0.01);
        $this->meja->sinkron();
        $this->assertSame(1, Task::where('skill', 'isi-saldo')->count(), 'Saldo cukup, tak ada kartu baru.');
    }

    public function test_draft_penarikan_jadi_kartu_owner_tinggal_pilih_rekening_dan_posting(): void
    {
        Account::firstOrCreate(['code' => '1104'], ['name' => 'Saldo Penjualan Shopee', 'type' => 'asset', 'normal_balance' => 'debit', 'is_active' => 1])->update(['is_cash_account' => 1, 'is_active' => 1]);
        $bt = app(\App\Modules\Finance\Services\BankTransferService::class)->createDraft([
            'date' => today()->subDays(3)->toDateString(), 'from_account_id' => $this->rekening('1104'), 'to_account_id' => null,
            'amount' => 3000000, 'reference' => 'Penarikan Dana Shopee',
        ], tujuanBolehKosong: true);
        $lengkap = app(\App\Modules\Finance\Services\BankTransferService::class)->createDraft([
            'date' => today()->toDateString(), 'from_account_id' => $this->rekening('1104'), 'to_account_id' => $this->rekening(), 'amount' => 1000,
        ]);

        $this->meja->sinkron();
        $this->meja->sinkron();
        $kartu = Task::where('skill', 'penarikan-saldo')->sole();
        $this->assertSame($bt->id, $kartu->taskable_id, 'Hanya draft tanpa tujuan.');
        $this->assertSame('owner', $kartu->peran);
        $this->actingAs($this->owner)->get(route('tasks.meja.index'))
            ->assertSee('Penarikan saldo')->assertSee('Rp3.000.000 tanggal')->assertSee('Pilih rekening &amp; posting', false)->assertSee('1295 Shopee Pay');

        $this->actingAs($this->admin)->post(route('tasks.meja.penarikan', $kartu->id), ['rekening' => $this->rekening()])->assertForbidden();
        $this->actingAs($this->owner)->post(route('tasks.meja.penarikan', $kartu->id), ['rekening' => $this->rekening('1104')])->assertStatus(422);

        $this->post(route('tasks.meja.penarikan', $kartu->id), ['rekening' => $this->rekening(IsiSaldoSkill::AKUN)])->assertSessionHas('success');
        $bt->refresh();
        $this->assertSame('posted', $bt->status);
        $this->assertSame($this->rekening(IsiSaldoSkill::AKUN), (int) $bt->to_account_id);
        $this->assertSame('done', $kartu->fresh()->status);
        $this->assertSame('draft', $lengkap->fresh()->status);
    }

    public function test_kartu_penarikan_tutup_bila_draft_dihapus_dari_halaman_transfer(): void
    {
        Account::firstOrCreate(['code' => '1104'], ['name' => 'Saldo Penjualan Shopee', 'type' => 'asset', 'normal_balance' => 'debit', 'is_active' => 1])->update(['is_cash_account' => 1, 'is_active' => 1]);
        $bt = app(\App\Modules\Finance\Services\BankTransferService::class)->createDraft([
            'date' => today()->toDateString(), 'from_account_id' => $this->rekening('1104'), 'to_account_id' => null, 'amount' => 5000,
        ], tujuanBolehKosong: true);
        $this->meja->sinkron();
        $kartu = Task::where('skill', 'penarikan-saldo')->sole();

        $bt->delete();
        $this->meja->sinkron();

        $this->assertSame('cancelled', $kartu->fresh()->status);
    }

    public function test_hutang_lama_ditampilkan_dulu_lalu_masuk_pending_dan_aman_diulang(): void
    {
        $faktur = app(\App\Modules\Purchasing\Services\PurchaseInvoiceService::class)->createDraft([
            'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->gudang->id, 'invoice_date' => today()->toDateString(),
            'items' => [['product_id' => $this->bahan->id, 'qty' => 1, 'price' => 5000, 'unit' => 'pcs']],
        ]);
        app(\App\Modules\Purchasing\Services\PurchaseInvoicePostingService::class)->post($faktur);

        $this->artisan('pembelian:hutang-lama')->assertSuccessful();
        $this->assertSame(0, PengajuanBayarOwner::count(), 'Tanpa --simpan tidak mengubah apa pun.');

        $this->artisan('pembelian:hutang-lama --simpan')->assertSuccessful();
        $this->artisan('pembelian:hutang-lama --simpan')->assertSuccessful();
        $this->assertSame(1, PengajuanBayarOwner::count());

        $this->meja->sinkron();
        $this->assertSame('pending', $this->kartu('bayar-owner')->status);
    }
}
