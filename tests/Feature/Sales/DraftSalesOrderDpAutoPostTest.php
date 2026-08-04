<?php

namespace Tests\Feature\Sales;

use App\Core\Accounting\Account;
use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\StockReservation;
use App\Core\Inventory\Warehouse;
use App\Core\Period\AccountingPeriod;
use App\Models\Customer;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Sales\Services\CustomerPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kebijakan penjualan: Sales Order DRAFT tidak menahan stok — barang tetap dijual ke
 * siapa saja sampai ada uang muka. Link pembayaran boleh dikirim selagi draft, dan
 * begitu DP-nya masuk SO harus di-POST otomatis supaya stoknya baru dipesankan.
 *
 * Diuji lewat CustomerPaymentService::post() karena di situlah hook-nya dipasang —
 * satu titik untuk SEMUA jalur DP (link Midtrans, QRIS kasir, catat manual).
 */
class DraftSalesOrderDpAutoPostTest extends TestCase
{
    use RefreshDatabase;

    private int $customerId;
    private int $warehouseId;
    private int $productId;
    private int $cashAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        AccountingPeriod::firstOrCreate(
            ['year' => (int) now()->year, 'month' => (int) now()->month],
            [
                'start_date' => now()->startOfMonth()->toDateString(),
                'end_date'   => now()->endOfMonth()->toDateString(),
                'status'     => 'open',
            ]
        );

        $this->cashAccountId = Account::create([
            'code' => '1101', 'name' => 'Kas', 'type' => 'asset',
            'normal_balance' => 'debit', 'account_category' => 'cash', 'is_active' => true,
        ])->id;
        Account::create([
            'code' => '1120', 'name' => 'Piutang Usaha', 'type' => 'asset',
            'normal_balance' => 'debit', 'account_category' => 'receivable', 'is_active' => true,
        ]);
        Account::create([
            'code' => '2105', 'name' => 'Uang Muka Customer', 'type' => 'liability',
            'normal_balance' => 'credit', 'account_category' => 'payable', 'is_active' => true,
        ]);
        Account::create([
            'code' => '2106', 'name' => 'Kelebihan Bayar Customer', 'type' => 'liability',
            'normal_balance' => 'credit', 'account_category' => 'payable', 'is_active' => true,
        ]);

        $this->warehouseId = Warehouse::create([
            'name' => 'Gudang Jual', 'is_sellable' => true, 'is_active' => true,
        ])->id;

        $this->customerId = Customer::create([
            'code' => 'CUST-1', 'name' => 'Budi', 'is_active' => true,
        ])->id;

        $this->productId = Product::create([
            'sku' => 'TH-A5-M1-P', 'name' => 'Tent Holder A5', 'sale_type' => 'ready',
            'base_unit' => 'pcs', 'base_price' => 100000, 'is_active' => true, 'is_sellable' => true,
        ])->id;

        ProductStock::create([
            'product_id' => $this->productId, 'warehouse_id' => $this->warehouseId, 'qty_on_hand' => 40,
        ]);
    }

    private function makeDraftOrder(float $qty = 5, float $total = 500000): SalesOrder
    {
        $so = SalesOrder::create([
            'order_number' => 'SO-DRAFT-' . $qty,
            'customer_id'  => $this->customerId,
            'warehouse_id' => $this->warehouseId,
            'order_date'   => now()->toDateString(),
            'subtotal'     => $total,
            'grand_total'  => $total,
            'paid_amount'  => 0,
            'status'       => 'draft',
        ]);

        SalesOrderItem::create([
            'sales_order_id'     => $so->id,
            'product_id'         => $this->productId,
            'qty'                => $qty,
            'unit_price'         => $total / $qty,
            'net_unit_price'     => $total / $qty,
            'discount_per_unit'  => 0,
            'line_subtotal'      => $total,
            'line_discount'      => 0,
            'line_total'         => $total,
            'conversion_to_base' => 1,
        ]);

        return $so;
    }

    private function payAdvance(SalesOrder $so, float $amount): void
    {
        $service = app(CustomerPaymentService::class);

        $payment = $service->create([
            'customer_id'     => $this->customerId,
            'date'            => now()->toDateString(),
            'cash_account_id' => $this->cashAccountId,
            'amount'          => $amount,
            'payment_type'    => 'advance',
            'sales_order_id'  => $so->id,
        ]);

        $service->post($payment->id, null, [], [$so->id], false);
    }

    public function test_draft_belum_menahan_stok(): void
    {
        $this->makeDraftOrder();

        $this->assertSame(
            0,
            StockReservation::where('product_id', $this->productId)->where('status', 'active')->count(),
            'SO draft tidak boleh membuat reservasi — stok masih bebas dijual'
        );
    }

    public function test_dp_masuk_memposting_so_dan_memesan_stok(): void
    {
        $so = $this->makeDraftOrder(5, 500000);

        $this->payAdvance($so, 250000);

        $so->refresh();
        $this->assertSame('confirmed', $so->status, 'SO draft harus di-post begitu DP diterima');
        $this->assertEqualsWithDelta(250000.0, (float) $so->paid_amount, 0.01);

        $reserved = (float) StockReservation::where('product_id', $this->productId)
            ->where('status', 'active')
            ->sum('qty');
        $this->assertEqualsWithDelta(5.0, $reserved, 0.001, 'stok baru dipesankan setelah DP masuk');
    }

    public function test_so_yang_sudah_confirmed_tidak_dobel_reservasi(): void
    {
        $so = $this->makeDraftOrder(5, 500000);

        $this->payAdvance($so, 250000);
        $this->payAdvance($so->refresh(), 250000); // pelunasan menyusul

        $reserved = (float) StockReservation::where('product_id', $this->productId)
            ->where('status', 'active')
            ->sum('qty');
        $this->assertEqualsWithDelta(
            5.0,
            $reserved,
            0.001,
            'pembayaran kedua tidak boleh menambah reservasi lagi'
        );
    }

    /**
     * Produk preorder/custom baru diproduksi setelah ada uang — dan DP saja sudah cukup,
     * tidak harus lunas. Rantainya: SO draft di-post → SalesAdvance 'posted' →
     * SalesAdvanceObserver → PreorderAutoProductionService membuat OP.
     */
    public function test_dp_pada_produk_preorder_memicu_order_produksi(): void
    {
        $preorderId = Product::create([
            'sku' => 'CUSTOM-A', 'name' => 'Akrilik Custom', 'sale_type' => 'preorder',
            'base_unit' => 'pcs', 'base_price' => 200000, 'is_active' => true, 'is_sellable' => true,
        ])->id;

        // BOM auto dengan output utama 1 unit per siklus (syarat auto-produksi preorder).
        $bom = \App\Modules\Production\Models\Bom::create([
            'bom_number' => 'BOM-CUSTOM-A', 'name' => 'BOM Akrilik Custom',
            'auto_production' => true, 'typical_cycles' => 1,
        ]);
        \App\Modules\Production\Models\BomOutput::create([
            'bom_id' => $bom->id, 'product_id' => $preorderId,
            'qty_per_cycle' => 1, 'output_type' => 'main', 'percentage' => 100,
        ]);

        $so = SalesOrder::create([
            'order_number' => 'SO-PREORDER-1',
            'customer_id'  => $this->customerId,
            'warehouse_id' => $this->warehouseId,
            'order_date'   => now()->toDateString(),
            'subtotal'     => 600000,
            'grand_total'  => 600000,
            'paid_amount'  => 0,
            'status'       => 'draft',
        ]);
        SalesOrderItem::create([
            'sales_order_id'     => $so->id,
            'product_id'         => $preorderId,
            'qty'                => 3,
            'unit_price'         => 200000,
            'net_unit_price'     => 200000,
            'discount_per_unit'  => 0,
            'line_subtotal'      => 600000,
            'line_discount'      => 0,
            'line_total'         => 600000,
            'conversion_to_base' => 1,
        ]);

        $this->assertSame(
            0,
            \App\Modules\Production\Models\ProductionOrder::where('sales_order_id', $so->id)->count(),
            'selagi draft & belum dibayar, produksi belum boleh jalan'
        );

        // DP 30% saja — belum lunas.
        $this->payAdvance($so, 180000);

        $this->assertSame('confirmed', $so->refresh()->status);

        $ops = \App\Modules\Production\Models\ProductionOrder::where('sales_order_id', $so->id)->get();
        $this->assertCount(1, $ops, 'DP (belum lunas) sudah cukup untuk memicu produksi preorder');
        $this->assertEqualsWithDelta(3.0, (float) $ops->first()->planned_cycles, 0.001);
    }

    public function test_pembayaran_diterima_walau_stok_keburu_habis(): void
    {
        // Pesanan besar (90) melebihi stok (40) — uang tetap diterima, reservasi tetap
        // dibuat, dan "Tersedia" jadi minus. Admin diberi tahu lewat Telegram.
        $so = $this->makeDraftOrder(90, 9000000);

        $this->payAdvance($so, 4500000);

        $so->refresh();
        $this->assertSame('confirmed', $so->status, 'uang sudah masuk — pesanan tidak boleh ditolak');
        $this->assertEqualsWithDelta(
            90.0,
            (float) StockReservation::where('product_id', $this->productId)->where('status', 'active')->sum('qty'),
            0.001
        );
    }
}
