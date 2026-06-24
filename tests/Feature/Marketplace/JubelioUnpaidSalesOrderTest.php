<?php

namespace Tests\Feature\Marketplace;

use App\Core\Inventory\Product;
use App\Core\Inventory\StockReservation;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Modules\Marketplace\Jubelio\Models\JubelioSetting;
use App\Modules\Marketplace\Jubelio\Services\JubelioClient;
use App\Modules\Marketplace\Jubelio\Services\JubelioOrderSyncService;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Pesanan marketplace BELUM DIBAYAR harus langsung jadi SO (reservasi stok ERP = Jubelio)
 * TANPA DP, lalu di-void otomatis dengan alasan "Belum dibayar" bila dibatalkan/kedaluwarsa
 * di marketplace. Tujuannya: stok "Dipesan" ERP sejajar dgn reservasi Jubelio supaya selisih
 * stok mudah dilacak.
 *
 * JubelioClient di-mock (binding container) agar deterministik tanpa HTTP.
 */
class JubelioUnpaidSalesOrderTest extends TestCase
{
    use RefreshDatabase;

    private const SO_ID = 777;

    private int $customerId;
    private int $warehouseId;

    protected function setUp(): void
    {
        parent::setUp();

        $warehouse = Warehouse::firstOrCreate(['name' => 'Gudang Test']);
        $this->warehouseId = $warehouse->id;

        $customer = Customer::create([
            'code' => 'CUST-MP', 'name' => 'Shopee Official', 'is_marketplace' => true, 'is_active' => true,
        ]);
        $this->customerId = $customer->id;

        // Produk dgn jubelio_item_id agar resolveProduct cocok langsung (tanpa panggil getItem).
        Product::create([
            'sku' => 'AKR-001', 'name' => 'Akrilik A4', 'sale_type' => 'ready', 'base_unit' => 'pcs',
            'jubelio_item_id' => 2001, 'base_price' => 25000, 'is_active' => true, 'is_sellable' => true,
        ]);

        $s = JubelioSetting::find(1) ?: new JubelioSetting();
        $s->forceFill([
            'id'                   => 1,
            'username'             => 'a@b.com',
            'password'             => 'secret',
            'is_active'            => true,
            'base_url'             => JubelioSetting::DEFAULT_BASE_URL,
            'default_location_id'  => 1,
            'default_customer_id'  => $this->customerId,
            'default_warehouse_id' => $this->warehouseId,
        ])->save();
    }

    /** Detail pesanan Jubelio (2 pcs AKR-001) dengan flag pembayaran/pembatalan yang dapat diatur. */
    private function orderDetail(array $overrides = []): array
    {
        return array_merge([
            'salesorder_id'    => self::SO_ID,
            'salesorder_no'    => 'SP-UNPAID-1',
            'store_name'       => 'Shopee',
            'customer_name'    => 'Budi Pembeli',
            'transaction_date' => now()->toDateString(),
            'grand_total'      => 50000,
            'shipping_cost'    => 0,
            'is_paid'          => false,
            'items'            => [
                ['item_id' => 2001, 'item_code' => 'AKR-001', 'qty' => 2, 'price' => 25000, 'amount' => 50000],
            ],
        ], $overrides);
    }

    /**
     * Bind JubelioClient palsu yang membalas getOrder() dgn $details berurutan (satu per
     * pemanggilan), lalu kembalikan service yang memakai client itu.
     */
    private function syncServiceReturning(array ...$details): JubelioOrderSyncService
    {
        $client = Mockery::mock(JubelioClient::class);
        $client->shouldReceive('isReady')->andReturn(true);
        $responses = array_map(fn ($d) => ['success' => true, 'status' => 200, 'data' => $d, 'error' => null], $details);
        $client->shouldReceive('getOrder')->andReturnValues($responses);
        $this->app->instance(JubelioClient::class, $client);

        return $this->app->make(JubelioOrderSyncService::class);
    }

    public function test_unpaid_order_creates_confirmed_so_with_reservation_but_no_dp(): void
    {
        $link = $this->syncServiceReturning($this->orderDetail())->syncOrderById(self::SO_ID);

        // SO dibuat & confirmed.
        $this->assertSame(1, SalesOrder::count());
        $so = SalesOrder::first();
        $this->assertSame('confirmed', $so->status);
        $this->assertNotNull($link->sales_order_id);
        $this->assertSame($so->id, (int) $link->sales_order_id);

        // Stok ter-reserve sebesar qty pesanan.
        $res = StockReservation::where('sales_order_id', $so->id)->where('status', 'active')->get();
        $this->assertCount(1, $res);
        $this->assertEqualsWithDelta(2.0, (float) $res->first()->qty, 0.001);

        // Belum dibayar → TIDAK ada DP & tidak ada pembayaran.
        $this->assertFalse((bool) $link->dp_posted);
        $this->assertNull($link->customer_payment_id);
        $this->assertSame(0, CustomerPayment::count());
    }

    public function test_unpaid_sync_is_idempotent_no_duplicate_so_or_reservation(): void
    {
        $svc = $this->syncServiceReturning($this->orderDetail(), $this->orderDetail());
        $svc->syncOrderById(self::SO_ID);
        $svc->syncOrderById(self::SO_ID);

        $this->assertSame(1, SalesOrder::count(), 'tidak boleh ada SO dobel');
        $this->assertSame(1, StockReservation::where('status', 'active')->count(), 'reservasi tidak boleh dobel');
    }

    public function test_canceled_unpaid_order_auto_voids_so_with_reason_belum_dibayar(): void
    {
        // 1) Belum dibayar → SO + reservasi.  2) Dibatalkan di marketplace → auto-void.
        $svc = $this->syncServiceReturning($this->orderDetail(), $this->orderDetail(['is_canceled' => true]));

        $link = $svc->syncOrderById(self::SO_ID);
        $soId = (int) $link->sales_order_id;
        $this->assertGreaterThan(0, $soId);

        $svc->syncOrderById(self::SO_ID);

        $so = SalesOrder::find($soId);
        $this->assertSame('void', $so->status, 'SO belum-bayar yang dibatalkan harus di-void otomatis');
        $this->assertSame(0, StockReservation::where('sales_order_id', $soId)->where('status', 'active')->count(),
            'reservasi stok harus dilepas saat void');

        $link->refresh();
        $this->assertSame('canceled', $link->last_status);
        $this->assertSame('Belum dibayar', $link->cancel_reason);
    }
}
