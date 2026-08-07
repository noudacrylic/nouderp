<?php

namespace Tests\Feature\Shipping;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Models\ShippingSetting;
use App\Modules\POS\Services\FulfillmentReadinessService;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Status kurir dari Jubelio Shipment yang menggerakkan kartu Pemrosesan Pesanan sendiri:
 *   PICKED_UP → paket di tangan kurir  → tab "Dikirim" (tak perlu menebak dari cetak resi)
 *   DELIVERED → sampai di pembeli      → tab "Selesai" (tanpa ada yang menekan tombol)
 *
 * Tombol manual "Sudah Sampai" tetap ada untuk paket yang status kurirnya tak kunjung datang.
 */
class JubelioDeliveredWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'rahasia-webhook';

    private function setting(): void
    {
        ShippingSetting::for('jubelio_shipment')->update(['webhook_token' => self::SECRET]);
    }

    private function delivery(array $sjAttrs = []): SalesDelivery
    {
        $cust = Customer::create([
            'code' => 'CUST-' . uniqid(), 'name' => 'Toko Budi', 'is_marketplace' => false, 'is_active' => true,
        ]);
        $wh = Warehouse::firstOrCreate(['name' => 'Gudang Test']);

        $so = SalesOrder::create([
            'order_number'         => 'SO-WH-' . uniqid(),
            'customer_id'          => $cust->id,
            'warehouse_id'         => $wh->id,
            'order_date'           => now()->toDateString(),
            'global_discount_type' => 'nominal',
            'status'               => 'confirmed',
            'grand_total'          => 100000,
            'paid_amount'          => 100000,
            'delivery_method'      => 'kurir',
            'measured_at'          => now(),
        ]);

        SalesInvoice::create([
            'invoice_number' => 'INV-WH-' . uniqid(),
            'sales_order_id' => $so->id,
            'customer_id'    => $so->customer_id,
            'warehouse_id'   => $so->warehouse_id,
            'invoice_date'   => now()->toDateString(),
            'due_date'       => now()->addDays(7)->toDateString(),
            'status'         => 'posted',
            'grand_total'    => 100000,
        ]);

        return SalesDelivery::create(array_merge([
            'delivery_number'       => 'SJ-WH-' . uniqid(),
            'sales_order_id'        => $so->id,
            'warehouse_id'          => $so->warehouse_id,
            'delivery_method'       => 'kurir',
            'delivery_date'         => now()->toDateString(),
            'status'                => 'posted',
            'tracking_number'       => 'JX-' . uniqid(),
            'provider_order_id'     => 'SHIP-' . uniqid(),
            'shipping_courier_code' => 'jne',
            'shipping_provider'     => 'jubelio_shipment',
        ], $sjAttrs));
    }

    /** Kirim webhook dengan tanda tangan yang benar (secret dipakai dua kali, lihat provider). */
    private function webhook(SalesDelivery $sj, string $status)
    {
        $payload = json_encode([
            'awb'           => $sj->tracking_number,
            'shipment_id'   => $sj->provider_order_id,
            'latest_status' => $status,
        ]);

        return $this->call(
            'POST',
            '/jubelio-shipment/webhook',
            [], [], [],
            ['HTTP_X-JUBELIO-SIGNATURE' => hash_hmac('sha256', $payload . self::SECRET, self::SECRET),
             'CONTENT_TYPE'             => 'application/json'],
            $payload
        );
    }

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

    public function test_status_delivered_menandai_sampai_dan_memindahkan_ke_selesai(): void
    {
        $this->setting();
        $sj = $this->delivery(['resi_printed_at' => now()->subDay()]);

        $this->webhook($sj, 'DELIVERED')->assertOk()->assertJsonPath('delivered', true);

        $this->assertNotNull($sj->refresh()->delivered_at);
        $this->assertNull($sj->delivered_by, 'ditandai sistem, bukan orang');
        $this->assertSame('selesai', $this->bucketOf($sj->order));
    }

    public function test_status_picked_up_memindahkan_ke_dikirim_tanpa_cetak_resi(): void
    {
        $this->setting();
        // Resi belum dicetak: dengan tebakan lama kartunya masih nyangkut di "Telah Diproses".
        $sj = $this->delivery();
        $this->assertSame('telah_diproses', $this->bucketOf($sj->order));

        $this->webhook($sj, 'PICKED_UP')->assertOk();

        $this->assertSame('picked_up', $sj->refresh()->shipping_status);
        $this->assertSame('dikirim', $this->bucketOf($sj->order));
    }

    public function test_webhook_delivered_berulang_tidak_menggeser_waktu_sampai(): void
    {
        $this->setting();
        $sj = $this->delivery();

        $this->webhook($sj, 'DELIVERED')->assertOk();
        $pertama = $sj->refresh()->delivered_at;

        $this->travel(2)->hours();
        $this->webhook($sj, 'DELIVERED')->assertOk()->assertJsonPath('auto_selesai', false);

        $this->assertEquals($pertama, $sj->refresh()->delivered_at);
    }

    public function test_tanda_tangan_salah_ditolak(): void
    {
        $this->setting();
        $sj = $this->delivery();

        $this->postJson('/jubelio-shipment/webhook', [
            'awb' => $sj->tracking_number, 'latest_status' => 'DELIVERED',
        ], ['x-jubelio-signature' => 'ngawur'])->assertStatus(403);

        $this->assertNull($sj->refresh()->delivered_at);
    }
}
