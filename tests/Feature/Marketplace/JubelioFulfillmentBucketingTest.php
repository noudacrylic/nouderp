<?php

namespace Tests\Feature\Marketplace;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink;
use App\Modules\POS\Services\FulfillmentReadinessService;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SO marketplace (punya link Jubelio) harus muncul di "Pemrosesan Pesanan" dengan bucket
 * ditentukan rantai WMS: DP masuk → perlu_diproses; resi terbit → telah_diproses. SO toko
 * biasa tetap berperilaku seperti semula (tak terpengaruh perubahan).
 */
class JubelioFulfillmentBucketingTest extends TestCase
{
    use RefreshDatabase;

    private function warehouseId(): int
    {
        return Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id;
    }

    private function marketplaceSo(array $linkAttrs = []): SalesOrder
    {
        $cust = Customer::create([
            'code' => 'CUST-MP', 'name' => 'Shopee Official', 'is_marketplace' => true, 'is_active' => true,
        ]);
        $so = SalesOrder::create([
            'order_number' => 'SO-MP-1', 'customer_id' => $cust->id, 'warehouse_id' => $this->warehouseId(),
            'order_date' => now()->toDateString(), 'global_discount_type' => 'nominal',
            'status' => 'confirmed', 'grand_total' => 100000, 'paid_amount' => 100000,
        ]);
        JubelioOrderLink::create(array_merge([
            'jubelio_salesorder_id' => 777, 'jubelio_salesorder_no' => 'TP-1',
            'sales_order_id' => $so->id, 'store' => 'Shopee', 'dp_posted' => true,
        ], $linkAttrs));

        return $so;
    }

    public function test_paid_marketplace_order_lands_in_perlu_diproses(): void
    {
        $so = $this->marketplaceSo();

        $rows = app(FulfillmentReadinessService::class)->bucket('perlu_diproses');
        $row = $rows->firstWhere('id', $so->id);

        $this->assertNotNull($row, 'SO marketplace harus muncul di perlu_diproses');
        $this->assertTrue($row['is_marketplace']);
        $this->assertSame('Shopee', $row['channel']);
    }

    public function test_order_with_tracking_moves_to_telah_diproses(): void
    {
        $so = $this->marketplaceSo([
            'j_ready_to_pick' => true, 'j_picklist_done' => true, 'j_packed' => true,
            'j_invoice_done' => true, 'awb_requested' => true,
            'tracking_no' => 'JX123', 'shipper' => 'JNE', 'wms_completed_at' => now(),
        ]);

        $svc = app(FulfillmentReadinessService::class);
        $this->assertNull($svc->bucket('perlu_diproses')->firstWhere('id', $so->id));
        $row = $svc->bucket('telah_diproses')->firstWhere('id', $so->id);
        $this->assertNotNull($row);
        $this->assertSame('JX123', $row['tracking_no']);
    }

    public function test_unpaid_marketplace_order_is_belum_siap(): void
    {
        $so = $this->marketplaceSo(['dp_posted' => false]);

        $row = app(FulfillmentReadinessService::class)->bucket('belum_siap')->firstWhere('id', $so->id);
        $this->assertNotNull($row);
        $this->assertSame('Menunggu pembayaran marketplace', $row['reason']);
    }

    public function test_non_marketplace_order_unaffected(): void
    {
        $cust = Customer::create(['code' => 'CUST-TOKO', 'name' => 'Toko Budi', 'is_marketplace' => false, 'is_active' => true]);
        $so = SalesOrder::create([
            'order_number' => 'SO-TOKO-1', 'customer_id' => $cust->id, 'warehouse_id' => $this->warehouseId(),
            'order_date' => now()->toDateString(), 'global_discount_type' => 'nominal',
            'status' => 'confirmed', 'grand_total' => 50000, 'paid_amount' => 50000,
        ]);

        $row = app(FulfillmentReadinessService::class)->bucket('perlu_diproses')->firstWhere('id', $so->id);
        $this->assertNotNull($row);
        $this->assertFalse($row['is_marketplace']);
        $this->assertNull($row['channel']);
    }
}
