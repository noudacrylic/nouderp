<?php

namespace Tests\Feature\POS;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Modules\POS\Services\FulfillmentReadinessService;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tab "Dikirim" untuk pesanan NON-marketplace.
 *
 * Dulu pesanan kurir langsung dianggap Selesai begitu resinya dicetak — padahal barangnya
 * masih di jalan. Sekarang ia berhenti di "Dikirim" sampai ada yang menandai paketnya sampai
 * (tombol "Sudah Sampai" di kartu, biasanya setelah membuka Lacak).
 *
 * Ambil di toko tetap lompat langsung ke Selesai: barangnya diserahkan saat diproses.
 */
class FulfillmentDikirimTest extends TestCase
{
    use RefreshDatabase;

    private function warehouseId(): int
    {
        return Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id;
    }

    /** SO yang sudah diproses: faktur posted + (opsional) Surat Jalan posted. */
    private function so(array $soAttrs = [], array $sjAttrs = null): SalesOrder
    {
        $cust = Customer::create([
            'code' => 'CUST-' . uniqid(), 'name' => 'Toko Budi', 'is_marketplace' => false, 'is_active' => true,
        ]);

        $so = SalesOrder::create(array_merge([
            'order_number'         => 'SO-KRM-' . uniqid(),
            'customer_id'          => $cust->id,
            'warehouse_id'         => $this->warehouseId(),
            'order_date'           => now()->toDateString(),
            'global_discount_type' => 'nominal',
            'status'               => 'confirmed',
            'grand_total'          => 100000,
            'paid_amount'          => 100000,
            'delivery_method'      => 'kurir',
            'measured_at'          => now(),
        ], $soAttrs));

        SalesInvoice::create([
            'invoice_number' => 'INV-KRM-' . uniqid(),
            'sales_order_id' => $so->id,
            'customer_id'    => $so->customer_id,
            'warehouse_id'   => $so->warehouse_id,
            'invoice_date'   => now()->toDateString(),
            'due_date'       => now()->addDays(7)->toDateString(),
            'status'         => 'posted',
            'grand_total'    => 100000,
        ]);

        if ($sjAttrs !== null) {
            SalesDelivery::create(array_merge([
                'delivery_number' => 'SJ-KRM-' . uniqid(),
                'sales_order_id'  => $so->id,
                'warehouse_id'    => $so->warehouse_id,
                'delivery_method' => 'kurir',
                'delivery_date'   => now()->toDateString(),
                'status'          => 'posted',
            ], $sjAttrs));
        }

        return $so->refresh();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    /** Service-nya memoized per-request; di tes yang mengubah data di tengah jalan harus dilupakan dulu. */
    private function bucketOf(SalesOrder $so): ?string
    {
        app()->forgetInstance(FulfillmentReadinessService::class);
        $svc = app(FulfillmentReadinessService::class);

        foreach (['perlu_diproses', 'telah_diproses', 'dikirim', 'selesai'] as $bucket) {
            if ($svc->bucket($bucket)->firstWhere('id', $so->id)) {
                return $bucket;
            }
        }

        return null;
    }

    public function test_resi_belum_dicetak_masih_di_telah_diproses(): void
    {
        $so = $this->so([], ['tracking_number' => 'JX123', 'shipping_courier_code' => 'jne']);

        $this->assertSame('telah_diproses', $this->bucketOf($so));
    }

    public function test_resi_dicetak_hari_ini_belum_pindah(): void
    {
        // Jeda H+1 disengaja: hari yang sama masih boleh cetak ulang tanpa kartunya berpindah.
        $so = $this->so([], [
            'tracking_number' => 'JX123', 'shipping_courier_code' => 'jne',
            'resi_printed_at' => now(),
        ]);

        $this->assertSame('telah_diproses', $this->bucketOf($so));
    }

    public function test_resi_sudah_dicetak_masuk_dikirim_bukan_selesai(): void
    {
        $so = $this->so([], [
            'tracking_number' => 'JX123', 'shipping_courier_code' => 'jne',
            'resi_printed_at' => now()->subDay(),
        ]);

        $this->assertSame('dikirim', $this->bucketOf($so));
    }

    public function test_ditandai_sampai_pindah_ke_selesai(): void
    {
        $so = $this->so([], [
            'tracking_number' => 'JX123', 'shipping_courier_code' => 'jne',
            'resi_printed_at' => now()->subDay(),
        ]);
        $sj = SalesDelivery::where('sales_order_id', $so->id)->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('pos.fulfillment.sampai', $sj->id))
            ->assertRedirect();

        $this->assertNotNull($sj->refresh()->delivered_at);
        $this->assertSame('selesai', $this->bucketOf($so));
    }

    public function test_penandaan_sampai_bisa_ditarik_kembali(): void
    {
        $so = $this->so([], [
            'tracking_number' => 'JX123', 'shipping_courier_code' => 'jne',
            'resi_printed_at' => now()->subDay(), 'delivered_at' => now(),
        ]);
        $sj = SalesDelivery::where('sales_order_id', $so->id)->firstOrFail();
        $this->assertSame('selesai', $this->bucketOf($so));

        $this->actingAs($this->admin())
            ->post(route('pos.fulfillment.batal-sampai', $sj->id))
            ->assertRedirect();

        $this->assertNull($sj->refresh()->delivered_at);
        $this->assertSame('dikirim', $this->bucketOf($so));
    }

    public function test_satu_paket_sampai_dari_dua_tetap_di_dikirim(): void
    {
        // Kiriman parsial: pesanan baru tuntas kalau SEMUA paketnya sampai.
        $so = $this->so([], [
            'tracking_number' => 'JX1', 'shipping_courier_code' => 'jne',
            'resi_printed_at' => now()->subDay(), 'delivered_at' => now(),
        ]);
        SalesDelivery::create([
            'delivery_number' => 'SJ-KRM-2-' . uniqid(),
            'sales_order_id'  => $so->id,
            'warehouse_id'    => $so->warehouse_id,
            'delivery_method' => 'kurir',
            'delivery_date'   => now()->toDateString(),
            'status'          => 'posted',
            'tracking_number' => 'JX2',
            'shipping_courier_code' => 'jne',
            'resi_printed_at' => now()->subDay(),
        ]);

        $this->assertSame('dikirim', $this->bucketOf($so));
    }

    public function test_ambil_di_toko_langsung_selesai(): void
    {
        $so = $this->so([
            'delivery_method' => 'ambil_toko',
            'pickup_status'   => 'picked_up',
            'picked_up_at'    => now(),
        ], ['delivery_method' => 'ambil_toko']);

        $this->assertSame('selesai', $this->bucketOf($so));
    }

    public function test_halaman_dikirim_terbuka(): void
    {
        $this->so([], [
            'tracking_number' => 'JX123', 'shipping_courier_code' => 'jne',
            'resi_printed_at' => now()->subDay(),
        ]);

        $this->actingAs($this->admin())
            ->get(route('pos.fulfillment.dikirim'))
            ->assertOk()
            ->assertSee('Sudah Sampai');
    }
}
