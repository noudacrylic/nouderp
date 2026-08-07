<?php

namespace Tests\Feature\Sales;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Modules\POS\Services\FulfillmentReadinessService;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pembayaran tempo = kesepakatan bahwa barang boleh dikirim sebelum dibayar. Karena itu
 * pesanan tempo melewati DUA gerbang sekaligus di Pemrosesan Pesanan: "Belum Bayar"
 * (belum ada uang masuk sama sekali) dan "Belum Lunas" (baru DP).
 *
 * Bedanya dengan tombol 🔓 Lepas di tab Belum Lunas: tempo itu kesepakatan yang ditetapkan
 * sejak SO dibuat, sedangkan Lepas itu pengecualian sekali jalan dari layar packing.
 * Keduanya sengaja berdampingan.
 */
class SalesOrderTempoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function so(array $attrs = []): SalesOrder
    {
        $cust = Customer::create([
            'code' => 'CUST-' . uniqid(), 'name' => 'Toko Langganan', 'is_marketplace' => false, 'is_active' => true,
        ]);

        return SalesOrder::create(array_merge([
            'order_number'         => 'SO-TP-' . uniqid(),
            'customer_id'          => $cust->id,
            'warehouse_id'         => Warehouse::firstOrCreate(['name' => 'Gudang Test'], ['is_sellable' => true])->id,
            'order_date'           => now()->toDateString(),
            'global_discount_type' => 'nominal',
            'status'               => 'confirmed',
            'delivery_method'      => 'ambil_toko',  // lewati gerbang ukur; yang diuji soal bayar
            'grand_total'          => 100000,
            'paid_amount'          => 0,
        ], $attrs));
    }

    private function bucketOf(SalesOrder $so): ?string
    {
        app()->forgetInstance(FulfillmentReadinessService::class);
        $svc = app(FulfillmentReadinessService::class);

        foreach (['belum_bayar', 'belum_siap', 'belum_lunas', 'perlu_ukur', 'perlu_diproses'] as $bucket) {
            if ($svc->bucket($bucket)->firstWhere('id', $so->id)) {
                return $bucket;
            }
        }

        return null;
    }

    public function test_tempo_tanpa_bayar_sama_sekali_langsung_siap_proses(): void
    {
        $so = $this->so(['is_tempo' => true, 'tempo_days' => 30, 'tempo_due_date' => now()->addDays(30)]);

        $this->assertSame('perlu_diproses', $this->bucketOf($so),
            'Tempo penuh harus melewati gerbang Belum Bayar.');
    }

    public function test_tempo_dengan_dp_sebagian_juga_lolos(): void
    {
        $so = $this->so(['paid_amount' => 40000, 'is_tempo' => true, 'tempo_days' => 14]);

        $this->assertSame('perlu_diproses', $this->bucketOf($so));
    }

    public function test_tanpa_tempo_tetap_tertahan_seperti_semula(): void
    {
        $this->assertSame('belum_bayar', $this->bucketOf($this->so()));
        $this->assertSame('belum_lunas', $this->bucketOf($this->so(['paid_amount' => 40000])));
    }

    public function test_proses_pesanan_tidak_menghadang_tempo(): void
    {
        $svc = app(\App\Modules\POS\Services\PosFulfillmentService::class);

        $biasa = $this->so(['paid_amount' => 40000]);
        try {
            $svc->createInvoiceFromSalesOrder($biasa);
            $this->fail('SO belum lunas non-tempo seharusnya ditolak.');
        } catch (\DomainException $e) {
            $this->assertMatchesRegularExpression('/belum lunas/i', $e->getMessage());
        }

        $tempo = $this->so(['paid_amount' => 40000, 'is_tempo' => true]);
        try {
            $svc->createInvoiceFromSalesOrder($tempo);
        } catch (\Throwable $e) {
            // Boleh gagal karena hal lain (mis. tidak ada item) — yang diuji: gerbang lunas
            // tidak lagi menghadang.
            $this->assertDoesNotMatchRegularExpression('/belum lunas/i', $e->getMessage());
        }
    }

    public function test_tempo_lewat_jatuh_tempo_ditandai(): void
    {
        $so = $this->so([
            'paid_amount' => 0, 'is_tempo' => true, 'tempo_days' => 30,
            'tempo_due_date' => now()->subDays(5)->toDateString(),
        ]);

        $this->assertTrue($so->isTempoOverdue());
        $this->assertSame(-5, $so->tempoDaysLeft());

        $row = app(FulfillmentReadinessService::class)->bucket('perlu_diproses')->firstWhere('id', $so->id);
        $this->assertTrue($row['tempo_overdue']);
    }

    public function test_tempo_yang_sudah_lunas_bukan_tunggakan(): void
    {
        $so = $this->so([
            'paid_amount' => 100000, 'is_tempo' => true,
            'tempo_due_date' => now()->subDays(5)->toDateString(),
        ]);

        $this->assertFalse($so->isTempoOverdue(), 'Sudah dibayar — lewat tanggal pun bukan tunggakan.');
    }

    // ───────────── Penyetelan dari halaman SO ─────────────

    public function test_tempo_bisa_dinyalakan_dari_halaman_so(): void
    {
        $so = $this->so();

        $this->actingAs($this->admin())
            ->post(route('sales.orders.tempo', $so->id), ['is_tempo' => 1, 'tempo_days' => 30])
            ->assertRedirect();

        $so->refresh();
        $this->assertTrue($so->is_tempo);
        $this->assertSame(30, (int) $so->tempo_days);
        // Jatuh tempo dihitung dari TANGGAL SO, bukan hari ini.
        $this->assertSame(
            \Carbon\Carbon::parse($so->order_date)->addDays(30)->toDateString(),
            $so->tempo_due_date->toDateString()
        );
    }

    public function test_tempo_bisa_dimatikan_lagi(): void
    {
        $so = $this->so(['is_tempo' => true, 'tempo_days' => 30, 'tempo_due_date' => now()->addDays(30)]);

        $this->actingAs($this->admin())
            ->post(route('sales.orders.tempo', $so->id), ['is_tempo' => 0])
            ->assertRedirect();

        $so->refresh();
        $this->assertFalse($so->is_tempo);
        $this->assertNull($so->tempo_due_date);
        $this->assertSame('belum_bayar', $this->bucketOf($so));
    }

    public function test_tempo_tanpa_termin_tetap_sah(): void
    {
        $so = $this->so();

        $this->actingAs($this->admin())
            ->post(route('sales.orders.tempo', $so->id), ['is_tempo' => 1, 'tempo_days' => ''])
            ->assertRedirect();

        $so->refresh();
        $this->assertTrue($so->is_tempo);
        $this->assertNull($so->tempo_due_date);
        $this->assertNull($so->tempoDaysLeft());
        $this->assertFalse($so->isTempoOverdue(), 'Tanpa tanggal, tak ada yang bisa dinyatakan lewat.');
        $this->assertSame('perlu_diproses', $this->bucketOf($so));
    }

    public function test_so_void_tidak_bisa_diubah_temponya(): void
    {
        $so = $this->so(['status' => 'void']);

        $this->actingAs($this->admin())
            ->post(route('sales.orders.tempo', $so->id), ['is_tempo' => 1, 'tempo_days' => 30])
            ->assertSessionHas('error');

        $this->assertFalse($so->refresh()->is_tempo);
    }

    /** Kesepakatan tempo ikut tersimpan lewat form Buat/Ubah SO, bukan hanya toggle di halaman lihat. */
    public function test_tempo_tersimpan_saat_so_disimpan_dari_form(): void
    {
        $this->actingAs($this->admin());

        $warehouseId = Warehouse::firstOrCreate(['name' => 'Gudang Test'], ['is_sellable' => true])->id;
        $customerId  = Customer::create(['code' => 'CUST-FORM', 'name' => 'Pembeli', 'is_active' => true])->id;
        $productId   = \App\Core\Inventory\Product::create([
            'sku' => 'PRD-TP-1', 'name' => 'Produk Tempo', 'sale_type' => 'ready',
            'base_unit' => 'pcs', 'base_price' => 100000, 'is_active' => true, 'is_sellable' => true,
        ])->id;

        $tanggal = now()->subDays(3)->toDateString();

        $this->post('/erp/sales/orders', [
            'customer_id'  => $customerId,
            'warehouse_id' => $warehouseId,
            'order_date'   => $tanggal,
            'items'        => [[
                'product_id' => $productId, 'qty' => 1, 'unit_price' => '100.000',
                'discount_type' => 'nominal', 'discount_value' => 0,
            ]],
            'is_tempo'   => 1,
            'tempo_days' => '30',
        ]);

        $so = SalesOrder::where('customer_id', $customerId)->latest('id')->first();

        $this->assertNotNull($so);
        $this->assertTrue($so->is_tempo);
        $this->assertSame(30, (int) $so->tempo_days);
        // Dihitung dari tanggal SO (3 hari lalu), bukan hari ini.
        $this->assertSame(
            \Carbon\Carbon::parse($tanggal)->addDays(30)->toDateString(),
            $so->tempo_due_date->toDateString()
        );
    }

    public function test_kotak_tempo_tampil_di_halaman_so(): void
    {
        $so = $this->so(['is_tempo' => true, 'tempo_days' => 30, 'tempo_due_date' => now()->addDays(30)]);

        $this->actingAs($this->admin())
            ->get(route('sales.orders.show', $so->id))
            ->assertOk()
            ->assertSee('Pembayaran Tempo')
            ->assertSee('30 hari');
    }
}
