<?php

namespace Tests\Feature\Sales;

use App\Core\Accounting\Account;
use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\StockReservation;
use App\Core\Inventory\Warehouse;
use App\Core\Journal\Journal;
use App\Core\Journal\JournalLine;
use App\Core\Period\AccountingPeriod;
use App\Models\Customer;
use App\Models\CustomerOverpayment;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Modules\Sales\Models\SalesAdvance;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Sales\Services\CustomerPaymentService;
use App\Modules\Sales\Services\SalesOrderReplacementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ganti Pesanan: pelanggan sudah DP lalu minta ganti ukuran. SO baru dibuat, DP DIPINDAH
 * (bukan void pembayaran), SO lama di-void. Baris kas pembayaran tidak boleh berubah —
 * itulah yang dicocokkan dgn rekening koran / laporan Midtrans.
 */
class GantiPesananTest extends TestCase
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
            'sku' => 'TH-A5', 'name' => 'Tent Holder', 'sale_type' => 'ready',
            'base_unit' => 'pcs', 'base_price' => 100000, 'is_active' => true, 'is_sellable' => true,
        ])->id;

        ProductStock::create([
            'product_id' => $this->productId, 'warehouse_id' => $this->warehouseId, 'qty_on_hand' => 40,
        ]);
    }

    private function order(string $number, float $qty, float $total, ?int $customerId = null): SalesOrder
    {
        $so = SalesOrder::create([
            'order_number' => $number,
            'customer_id'  => $customerId ?? $this->customerId,
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

    /** SO lama + DP (DP otomatis mem-post SO). */
    private function paidOrder(float $total, float $dp): array
    {
        $so = $this->order('SO-LAMA', 5, $total);
        $service = app(CustomerPaymentService::class);
        $payment = $service->create([
            'customer_id'     => $this->customerId,
            'date'            => now()->subDays(3)->toDateString(),
            'cash_account_id' => $this->cashAccountId,
            'amount'          => $dp,
            'payment_type'    => 'advance',
            'sales_order_id'  => $so->id,
        ]);
        $service->post($payment->id, null, [], [$so->id], false);

        return [$so->fresh(), $payment->fresh()];
    }

    private function cashLines(CustomerPayment $payment)
    {
        return JournalLine::whereHas('journal', fn($q) => $q->where('reference_type', 'customer_payment')
                ->where('reference_id', $payment->id)->where('status', 'posted'))
            ->where('account_id', $this->cashAccountId)
            ->get(['debit', 'credit'])->toArray();
    }

    public function test_ganti_ke_pesanan_lebih_besar_memindah_dp_dan_void_so_lama(): void
    {
        [$old, $payment] = $this->paidOrder(500000, 250000);
        $cashBefore = $this->cashLines($payment);
        $new = $this->order('SO-BARU', 7, 700000);

        $res = app(SalesOrderReplacementService::class)->replace($old, $new);

        $old->refresh();
        $new->refresh();
        $this->assertSame('void', $old->status);
        $this->assertSame('confirmed', $new->status);
        $this->assertEqualsWithDelta(250000, $res['moved'], 0.01);
        $this->assertEqualsWithDelta(0, $res['to_balance'], 0.01);
        $this->assertEqualsWithDelta(250000, (float) $new->paid_amount, 0.01, 'Sisa tagihan SO baru = 450.000');
        $this->assertEqualsWithDelta(0, (float) $old->paid_amount, 0.01);

        $this->assertSame('posted', $payment->fresh()->status, 'Pembayaran asli TIDAK boleh di-void');
        $this->assertSame($cashBefore, $this->cashLines($payment), 'Baris kas pembayaran tidak boleh berubah');

        $this->assertSame([$new->id], CustomerPaymentAllocation::where('customer_payment_id', $payment->id)
            ->pluck('sales_order_id')->all());
        $this->assertSame($new->id, SalesAdvance::where('advance_number', 'ADV-' . $payment->payment_number)->value('sales_order_id'));

        $this->assertSame(0, StockReservation::where('sales_order_id', $old->id)->where('status', 'active')->count());
        $this->assertGreaterThan(0, StockReservation::where('sales_order_id', $new->id)->where('status', 'active')->count());
        $this->assertStringContainsString('SO-LAMA', $new->notes);
    }

    public function test_ganti_ke_pesanan_lebih_kecil_kelebihan_jadi_saldo_pelanggan(): void
    {
        [$old, $payment] = $this->paidOrder(500000, 500000);
        $cashBefore = $this->cashLines($payment);
        $new = $this->order('SO-BARU', 3, 300000);

        $res = app(SalesOrderReplacementService::class)->replace($old, $new);

        $this->assertEqualsWithDelta(300000, $res['moved'], 0.01);
        $this->assertEqualsWithDelta(200000, $res['to_balance'], 0.01);
        $this->assertEqualsWithDelta(300000, (float) $new->fresh()->paid_amount, 0.01, 'SO baru lunas');
        $this->assertEqualsWithDelta(200000, (float) CustomerOverpayment::where('customer_id', $this->customerId)->sum('amount'), 0.01);
        $this->assertEqualsWithDelta(300000, (float) SalesAdvance::where('advance_number', 'ADV-' . $payment->payment_number)->value('amount'), 0.01);

        // Reklas Dr 2105 / Cr 2106, berreferensi pembayaran asal (ikut terbalik bila pembayaran di-void).
        $reclass = Journal::where('reference_type', 'customer_payment')->where('reference_id', $payment->id)
            ->where('status', 'posted')->get();
        $this->assertCount(2, $reclass);
        $lines = JournalLine::where('journal_id', $reclass->last()->id)->get();
        $acc = fn($code) => Account::where('code', $code)->value('id');
        $this->assertEqualsWithDelta(200000, (float) $lines->firstWhere('account_id', $acc('2105'))->debit, 0.01);
        $this->assertEqualsWithDelta(200000, (float) $lines->firstWhere('account_id', $acc('2106'))->credit, 0.01);

        $this->assertSame($cashBefore, $this->cashLines($payment), 'Baris kas pembayaran tidak boleh berubah');
    }

    public function test_pelanggan_berbeda_ditolak_tanpa_mengubah_apa_pun(): void
    {
        [$old, $payment] = $this->paidOrder(500000, 250000);
        $other = Customer::create(['code' => 'CUST-2', 'name' => 'Ani', 'is_active' => true])->id;
        $new = $this->order('SO-BARU', 5, 500000, $other);

        try {
            app(SalesOrderReplacementService::class)->replace($old, $new);
            $this->fail('Harus ditolak');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Pelanggan', $e->getMessage());
        }

        $this->assertSame('confirmed', $old->fresh()->status);
        $this->assertSame('draft', $new->fresh()->status);
        $this->assertEqualsWithDelta(250000, (float) $old->fresh()->paid_amount, 0.01);
    }

    public function test_so_tanpa_dp_tidak_bisa_diganti(): void
    {
        $so = $this->order('SO-TANPA-DP', 5, 500000);
        $so->update(['status' => 'confirmed']);

        $this->assertNotNull(app(SalesOrderReplacementService::class)->blocker($so));
    }
}
