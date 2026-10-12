<?php

namespace Tests\Feature\Sales;

use App\Core\Accounting\Account;
use App\Core\Inventory\Warehouse;
use App\Core\Period\AccountingPeriod;
use App\Models\Customer;
use App\Models\MarketplaceConfig;
use App\Models\MarketplaceOrderFee;
use App\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\MarketplaceFeeBreakdownService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rincian potongan marketplace dari Jubelio. Angka diambil dari pesanan Shopee/TikTok asli
 * (12 Okt 2026), hanya kolom biayanya.
 */
class MarketplaceFeeBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private MarketplaceConfig $cfg;
    private int $mpId;
    private int $warehouseId;

    /** SP-26100967SPMC3N — selesai, escrow lengkap. */
    private const SHOPEE_SELESAI = [
        'sub_total' => 155992, 'shipping_cost' => 0, 'grand_total' => 132123, 'service_fee' => 22619,
        'order_processing_fee' => 1250, 'escrow_amount' => 126751,
        'escrow_list' => [
            'service_fee' => 7020, 'escrow_amount' => 126751, 'commission_fee' => 15599, 'withholding_tax' => 780,
            'buyer_transaction_fee' => 1000, 'credit_card_transaction_fee' => 1000, 'order_ams_commission_fee' => 4242,
            'seller_order_processing_fee' => 1250, 'shipping_seller_protection_fee_amount' => 350,
            'voucher_from_shopee' => 3120, 'shopee_shipping_rebate' => 9000, 'actual_shipping_fee' => 9000,
        ],
    ];

    /** SP-26100707D5HAE4 — dikirim, belum selesai: hanya angka global. */
    private const SHOPEE_DIKIRIM = [
        'sub_total' => 55999, 'shipping_cost' => 0, 'grand_total' => 47189, 'service_fee' => 7560,
        'order_processing_fee' => 1250, 'escrow_amount' => 46909,
    ];

    /** TikTok — Jubelio tidak membawa potongan sama sekali. */
    private const TIKTOK = [
        'sub_total' => 44999, 'shipping_cost' => 0, 'grand_total' => 44999, 'service_fee' => 0,
        'order_processing_fee' => 0, 'escrow_list' => ['version' => 'v2', 'order_id' => '586453055104517886'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        AccountingPeriod::firstOrCreate(
            ['year' => (int) now()->year, 'month' => (int) now()->month],
            ['start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->endOfMonth()->toDateString(), 'status' => 'open']
        );
        $akun = fn (string $code, string $name, string $type, string $normal) =>
            Account::firstOrCreate(['code' => $code], ['name' => $name, 'type' => $type, 'normal_balance' => $normal, 'is_active' => true])->id;

        $this->mpId = Customer::create(['code' => 'MP', 'name' => 'Shopee', 'is_marketplace' => true, 'is_active' => true])->id;
        $this->cfg = MarketplaceConfig::create([
            'customer_id' => $this->mpId, 'admin_fee_percent' => 10, 'admin_fee_fixed' => 1250, 'is_active' => true,
            'account_receivable_hold_id' => $akun('1202', 'Saldo Ditahan', 'asset', 'debit'),
            'account_fee_id' => $akun('6101', 'Beban Admin', 'expense', 'debit'),
            'account_wallet_id' => $akun('1111', 'Saldo Penjualan Shopee', 'asset', 'debit'),
            'account_tax_id' => $akun('5012', 'PPh Final', 'expense', 'debit'),
            'tax_percent' => 0.5, 'tax_start_date' => '2026-10-02',
        ]);
        $this->warehouseId = Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id;
    }

    private function so(float $nilai, string $tanggal = '2026-10-07'): SalesOrder
    {
        return SalesOrder::create([
            'order_number' => 'SO-' . uniqid(), 'customer_id' => $this->mpId, 'warehouse_id' => $this->warehouseId,
            'order_date' => $tanggal, 'status' => 'confirmed', 'subtotal' => $nilai, 'grand_total' => $nilai,
        ]);
    }

    private function svc(): MarketplaceFeeBreakdownService
    {
        return app(MarketplaceFeeBreakdownService::class);
    }

    public function test_escrow_shopee_selesai_dirinci_admin_affiliate_pajak_dan_saldo(): void
    {
        $b = $this->svc()->dariJubelio(self::SHOPEE_SELESAI, 155992, true, $this->cfg, '2026-10-09');

        $this->assertEqualsWithDelta(15599 + 7020 + 1250 + 350, $b['admin'], 0.01);
        $this->assertSame(['Komisi', 'Biaya layanan', 'Biaya proses pesanan', 'Premi perlindungan pengiriman'], array_keys($b['admin_rincian']));
        $this->assertEqualsWithDelta(4242, $b['affiliate'], 0.01);
        $this->assertEqualsWithDelta(780, $b['pajak'], 0.01);
        $this->assertEqualsWithDelta(126751, $b['saldo_masuk'], 0.01);
        $this->assertEqualsWithDelta(0, $b['lain'], 0.01, 'kotor − semua potongan = saldo masuk');
        $this->assertTrue($b['final']);
    }

    public function test_pesanan_belum_selesai_memakai_angka_global_jubelio_sebagai_estimasi(): void
    {
        $b = $this->svc()->dariJubelio(self::SHOPEE_DIKIRIM, 55999, false, $this->cfg, '2026-10-07');

        $this->assertEqualsWithDelta(8810, $b['admin'], 0.01, 'layanan 7.560 + proses 1.250');
        $this->assertEqualsWithDelta(280, $b['pajak'], 0.01, 'taksiran 0,5% — pesanan masuk sesudah 2 Okt');
        $this->assertEqualsWithDelta(46909, $b['saldo_masuk'], 0.01);
        $this->assertEqualsWithDelta(0, $b['lain'], 0.01);
        $this->assertFalse($b['final']);
    }

    public function test_tanpa_data_jubelio_diestimasi_dari_setingan(): void
    {
        $this->assertNull($this->svc()->dariJubelio(self::TIKTOK, 44999, true, $this->cfg, '2026-10-07'));

        $b = $this->svc()->taksiran($this->cfg, 44999, '2026-10-07');
        $this->assertEqualsWithDelta(round(44999 * 0.10) + 1250, $b['admin'], 0.01);
        $this->assertEqualsWithDelta(225, $b['pajak'], 0.01);
        $this->assertSame('setingan', $b['sumber']);

        $sebelum = $this->svc()->taksiran($this->cfg, 44999, '2026-09-30');
        $this->assertSame(0.0, $sebelum['pajak'], 'pesanan masuk sebelum 2 Okt tidak dipotong pajak');
    }

    public function test_settlement_tiktok_gabungan_dipecah_jadi_pajak_taksiran_dan_biaya_lain(): void
    {
        // TP-586214236484043970 — escrow TikTok muncul ±10 hari setelah selesai.
        $d = self::TIKTOK;
        $d['escrow_list'] += ['revenue_amount' => 94999, 'settlement_amount' => 72888, 'fee_and_tax_amount' => -19081, 'shipping_cost_amount' => -3030];
        $b = $this->svc()->dariJubelio($d, 94999, true, $this->cfg, '2026-10-05');

        $this->assertEqualsWithDelta(475, $b['pajak'], 0.01, 'taksiran 0,5% × 94.999');
        $this->assertEqualsWithDelta(19081 - 475 + 3030, $b['admin'], 0.01);
        $this->assertEqualsWithDelta(72888, $b['saldo_masuk'], 0.01);
        $this->assertEqualsWithDelta(0, $b['lain'], 0.01);
        $this->assertTrue($b['final']);
        $this->assertArrayNotHasKey('pajak_asli', $b, 'pajak TikTok taksiran — tak dicatat sebagai pajak asli');
    }

    public function test_rincian_final_tidak_ditimpa_estimasi_dan_mengisi_pajak_asli_faktur(): void
    {
        $so = $this->so(155992);
        $inv = SalesInvoice::create([
            'invoice_number' => 'INV-1', 'sales_order_id' => $so->id, 'customer_id' => $this->mpId, 'warehouse_id' => $this->warehouseId,
            'invoice_date' => '2026-10-08', 'subtotal' => 155992, 'grand_total' => 155992, 'status' => 'posted',
        ]);

        $this->svc()->simpan($so, $this->svc()->dariJubelio(self::SHOPEE_SELESAI, 155992, true, $this->cfg, '2026-10-09'));
        $this->svc()->simpan($so, $this->svc()->dariJubelio(self::SHOPEE_DIKIRIM, 155992, false, $this->cfg, '2026-10-09'));

        $row = MarketplaceOrderFee::where('sales_order_id', $so->id)->first();
        $this->assertTrue($row->final);
        $this->assertEqualsWithDelta(126751, $row->saldo_masuk, 0.01);
        $this->assertEqualsWithDelta(780, (float) $inv->fresh()->mp_tax_withheld, 0.01);
        $this->assertSame('jubelio', $inv->fresh()->mp_tax_source);
    }

    public function test_kartu_tampil_di_halaman_so_dan_biaya_admin_keluar_dari_ringkasan(): void
    {
        $so = $this->so(55999);
        $so->update(['marketplace_fee' => 8810]);
        $this->svc()->simpan($so, $this->svc()->dariJubelio(self::SHOPEE_DIKIRIM, 55999, false, $this->cfg, '2026-10-07'));

        $html = $this->actingAs(\App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->get(route('sales.orders.show', $so->id))->assertOk()->getContent();

        $this->assertStringContainsString('Potongan Marketplace', $html);
        $this->assertStringContainsString('Estimasi · dari Jubelio', $html);
        $this->assertStringContainsString('46.909', $html);
        $this->assertStringNotContainsString('Biaya Admin Marketplace', $html);
    }

    public function test_kartu_juga_tampil_di_halaman_faktur(): void
    {
        $so = $this->so(155992);
        $inv = SalesInvoice::create([
            'invoice_number' => 'INV-K1', 'sales_order_id' => $so->id, 'customer_id' => $this->mpId, 'warehouse_id' => $this->warehouseId,
            'invoice_date' => '2026-10-08', 'subtotal' => 155992, 'grand_total' => 155992, 'status' => 'posted',
        ]);
        $this->svc()->simpan($so, $this->svc()->dariJubelio(self::SHOPEE_SELESAI, 155992, true, $this->cfg, '2026-10-09'));

        $html = $this->actingAs(\App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->get(route('sales.invoices.show', $inv->id))->assertOk()->getContent();

        $this->assertStringContainsString('Potongan Marketplace', $html);
        $this->assertStringContainsString('Final · dari Jubelio', $html);
        $this->assertStringContainsString('Biaya affiliate', $html);
        $this->assertStringContainsString('126.751', $html);
    }
}
