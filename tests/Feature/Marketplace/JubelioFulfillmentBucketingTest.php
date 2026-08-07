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

    public function test_processed_order_printed_today_stays_in_telah_diproses(): void
    {
        // Kita proses sendiri (awb_requested) + resi dicetak HARI INI → tetap di Telah Diproses,
        // walau status shipped Jubelio (sj_created) sudah menyala. Belum boleh ke Dikirim.
        $so = $this->marketplaceSo([
            'awb_requested' => true, 'tracking_no' => 'JX1', 'sj_created' => true,
            'resi_printed_at' => now(),
        ]);

        $svc = app(FulfillmentReadinessService::class);
        $this->assertNotNull($svc->bucket('telah_diproses')->firstWhere('id', $so->id));
        $this->assertNull($svc->bucket('dikirim')->firstWhere('id', $so->id));
    }

    public function test_processed_order_printed_yesterday_moves_to_dikirim(): void
    {
        $so = $this->marketplaceSo([
            'awb_requested' => true, 'tracking_no' => 'JX1', 'sj_created' => true,
            'resi_printed_at' => now()->subDay(),
        ]);

        $svc = app(FulfillmentReadinessService::class);
        $this->assertNull($svc->bucket('telah_diproses')->firstWhere('id', $so->id));
        $this->assertNotNull($svc->bucket('dikirim')->firstWhere('id', $so->id));
    }

    public function test_jubelio_direct_shipped_without_processing_goes_to_dikirim(): void
    {
        // Dikirim langsung lewat Jubelio (sj_created) tanpa proses WMS di ERP → Dikirim.
        $so = $this->marketplaceSo(['sj_created' => true]);

        $svc = app(FulfillmentReadinessService::class);
        $this->assertNotNull($svc->bucket('dikirim')->firstWhere('id', $so->id));
        $this->assertNull($svc->bucket('perlu_diproses')->firstWhere('id', $so->id));
    }

    /**
     * Belum dibayar di channel = "Belum Bayar", BUKAN "Belum Siap". "Belum Siap" khusus pesanan
     * yang uangnya sudah masuk tapi barangnya belum ada — dua keadaan yang berbeda tindakannya:
     * yang satu ditunggu/ditagih, yang satu dikejar produksi/stoknya.
     */
    public function test_unpaid_marketplace_order_is_belum_bayar(): void
    {
        $so = $this->marketplaceSo(['dp_posted' => false]);

        $svc = app(FulfillmentReadinessService::class);
        $row = $svc->bucket('belum_bayar')->firstWhere('id', $so->id);
        $this->assertNotNull($row);
        $this->assertSame('Menunggu pembayaran marketplace', $row['reason']);
        $this->assertNull($svc->bucket('belum_siap')->firstWhere('id', $so->id));
    }

    /** Pesanan marketplace yang bahkan belum jadi SO (belum dibayar) ikut ke "Belum Bayar". */
    public function test_marketplace_pending_link_is_belum_bayar_dan_halamannya_terbuka(): void
    {
        JubelioOrderLink::create([
            'jubelio_salesorder_id' => 888, 'jubelio_salesorder_no' => 'SP-BELUM-BAYAR',
            // last_status diisi seperti hasil sinkron Jubelio: baris ber-status NULL memang
            // tersaring keluar oleh mpPendingRows() (`!= 'canceled'` bernilai NULL untuk NULL).
            'last_status' => 'pending', 'store' => 'Shopee',
            'snap_customer' => 'Pembeli Shopee', 'snap_grand_total' => 45000,
        ]);

        $svc = app(FulfillmentReadinessService::class);
        $row = $svc->bucket('belum_bayar')->firstWhere('number', 'SP-BELUM-BAYAR');
        $this->assertNotNull($row);
        $this->assertSame('mp_pending', $row['kind']);
        $this->assertNull($svc->bucket('belum_siap')->firstWhere('number', 'SP-BELUM-BAYAR'));

        // Kartu "belum jadi SO" berbeda bentuk dari kartu SO — halamannya harus tetap terender.
        $admin = \App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin)
            ->get(route('pos.fulfillment.belum-bayar'))
            ->assertOk()
            ->assertSee('Belum jadi SO');
    }

    /**
     * Sudah dibayar di channel bukan berarti barangnya ada. Pesanan preorder yang OP-nya masih
     * berjalan harus tertahan di "Belum Siap" — kalau tidak, tim packing mengejar barang yang
     * belum jadi (dulu marketplace dilewatkan dari semua cek kesiapan).
     */
    public function test_marketplace_dengan_produksi_belum_selesai_masuk_belum_siap(): void
    {
        $so = $this->marketplaceSo();
        $produk = \App\Core\Inventory\Product::create([
            'sku' => 'PO-MP-1', 'name' => 'Box Charger Custom', 'sale_type' => 'preorder', 'lead_time_days' => 3,
        ]);
        \App\Modules\Sales\Models\SalesOrderItem::create([
            'sales_order_id' => $so->id, 'product_id' => $produk->id, 'qty' => 2,
            'conversion_to_base' => 1, 'unit_price' => 50000, 'net_unit_price' => 50000,
            'line_subtotal' => 100000, 'line_discount' => 0, 'line_total' => 100000,
        ]);
        \App\Modules\Production\Models\ProductionOrder::create([
            'order_number' => 'OP-MP-1', 'sales_order_id' => $so->id, 'type' => 'custom',
            'warehouse_id' => $this->warehouseId(), 'planned_qty' => 2, 'status' => 'in_progress',
            'production_date' => now()->toDateString(),
        ]);

        $svc = app(FulfillmentReadinessService::class);
        $row = $svc->bucket('belum_siap')->firstWhere('id', $so->id);
        $this->assertNotNull($row, 'produksi belum selesai harus menahan pesanan marketplace');
        $this->assertSame('Produksi belum selesai', $row['reason']);
        $this->assertNull($svc->bucket('perlu_diproses')->firstWhere('id', $so->id));
    }

    /** Stok fisik 0/minus juga menahan pesanan marketplace, bukan cuma pesanan toko. */
    public function test_marketplace_dengan_stok_kurang_masuk_belum_siap(): void
    {
        $so = $this->marketplaceSo();
        $produk = \App\Core\Inventory\Product::create([
            'sku' => 'RDY-MP-1', 'name' => 'Rak Bolpoin', 'sale_type' => 'ready',
        ]);
        \App\Modules\Sales\Models\SalesOrderItem::create([
            'sales_order_id' => $so->id, 'product_id' => $produk->id, 'qty' => 3,
            'conversion_to_base' => 1, 'unit_price' => 20000, 'net_unit_price' => 20000,
            'line_subtotal' => 60000, 'line_discount' => 0, 'line_total' => 60000,
        ]);
        // Stok gudang 0 → kurang 3.

        $svc = app(FulfillmentReadinessService::class);
        $row = $svc->bucket('belum_siap')->firstWhere('id', $so->id);
        $this->assertNotNull($row);
        $this->assertStringContainsString('RDY-MP-1', $row['reason']);
    }

    public function test_non_marketplace_order_unaffected(): void
    {
        $cust = Customer::create(['code' => 'CUST-TOKO', 'name' => 'Toko Budi', 'is_marketplace' => false, 'is_active' => true]);
        $so = SalesOrder::create([
            'order_number' => 'SO-TOKO-1', 'customer_id' => $cust->id, 'warehouse_id' => $this->warehouseId(),
            'order_date' => now()->toDateString(), 'global_discount_type' => 'nominal',
            'status' => 'confirmed', 'grand_total' => 50000, 'paid_amount' => 50000,
            // Yang diuji di sini bendera marketplace, bukan gerbang ukur — tanpa penanda ini
            // pesanan berhenti di "Perlu Ukur" dan tak pernah sampai ke bucket yang dicek.
            'measured_at' => now(),
        ]);

        $row = app(FulfillmentReadinessService::class)->bucket('perlu_diproses')->firstWhere('id', $so->id);
        $this->assertNotNull($row);
        $this->assertFalse($row['is_marketplace']);
        $this->assertNull($row['channel']);
    }
}
