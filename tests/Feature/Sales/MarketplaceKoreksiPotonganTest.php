<?php

namespace Tests\Feature\Sales;

use App\Core\Accounting\Account;
use App\Core\Inventory\Warehouse;
use App\Core\Journal\Journal;
use App\Core\Period\AccountingPeriod;
use App\Models\Customer;
use App\Models\MarketplaceConfig;
use App\Models\SalesInvoice;
use App\Modules\Finance\Models\MarketplaceSettlement;
use App\Modules\Finance\Models\MarketplaceSettlementLine;
use App\Modules\Finance\Services\MarketplaceSettlementService;
use App\Modules\Sales\Models\SalesAdvance;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\MarketplaceEngineService;
use App\Modules\Sales\Services\MarketplaceFeeBreakdownService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Potongan marketplace yang menyusul SETELAH pesanan dicairkan.
 *
 * Kasus nyata (pesanan Shopee 261006UWCQ098E, 21 Okt 2026): saat selesai, escrow dari Jubelio
 * belum memuat komisi affiliate Rp4.329. Pencairan menjurnal wallet Rp65.479, padahal Shopee
 * hanya membayar Rp61.150. Dua jam kemudian rincian final tiba — tanpa koreksi, Saldo Penjualan
 * ERP terus lebih besar dari Saldo Penjual asli.
 */
class MarketplaceKoreksiPotonganTest extends TestCase
{
    use RefreshDatabase;

    private int $customerId;
    private int $holdId;
    private int $walletId;
    private int $feeId;
    private SalesOrder $so;

    protected function setUp(): void
    {
        parent::setUp();

        AccountingPeriod::firstOrCreate(
            ['year' => (int) now()->year, 'month' => (int) now()->month],
            ['start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->endOfMonth()->toDateString(), 'status' => 'open']
        );

        $akun = fn (string $code, string $name, string $type, string $normal, string $cat) =>
            Account::firstOrCreate(['code' => $code], [
                'name' => $name, 'type' => $type,
                'normal_balance' => $normal, 'account_category' => $cat, 'is_active' => true,
            ])->id;

        $akun('2105', 'Uang Muka Customer', 'liability', 'credit', 'payable');
        $this->holdId   = $akun('1202', 'Saldo Ditahan Shopee', 'asset', 'debit', 'cash');
        $this->walletId = $akun('1104', 'Saldo Penjualan Shopee', 'asset', 'debit', 'cash');
        $this->feeId    = $akun('5101', 'Beban Admin Marketplace', 'expense', 'debit', 'expense');

        $this->customerId = Customer::create([
            'code' => 'CUST-SP', 'name' => 'Shopee', 'is_marketplace' => true, 'is_active' => true,
        ])->id;

        MarketplaceConfig::create([
            'customer_id'                => $this->customerId,
            'admin_fee_percent'          => 0,
            'admin_fee_fixed'            => 0,
            'is_active'                  => true,
            'account_receivable_hold_id' => $this->holdId,
            'account_fee_id'             => $this->feeId,
            'account_wallet_id'          => $this->walletId,
        ]);

        $this->so = SalesOrder::create([
            'order_number' => 'SO-SP-1',
            'customer_id'  => $this->customerId,
            'warehouse_id' => Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id,
            'order_date'   => now()->toDateString(),
            'status'       => 'confirmed',
            'subtotal'     => 77999,
            'grand_total'  => 77999,
            'paid_amount'  => 77999,
        ]);

        SalesAdvance::create([
            'advance_number'  => 'ADV-SP-1',
            'sales_order_id'  => $this->so->id,
            'customer_id'     => $this->customerId,
            'bank_account_id' => $this->holdId,
            'advance_date'    => now()->toDateString(),
            'amount'          => 77999,
            'status'          => 'posted',
        ]);
    }

    private function fakturCair(bool $gayaBaru = true): SalesInvoice
    {
        $inv = SalesInvoice::create([
            'invoice_number'    => 'INV-SP-1',
            'sales_order_id'    => $this->so->id,
            'customer_id'       => $this->customerId,
            'warehouse_id'      => $this->so->warehouse_id,
            'invoice_date'      => now()->toDateString(),
            'subtotal'          => 77999,
            'grand_total'       => $gayaBaru ? 77999 : 65869,
            'marketplace_fee'   => $gayaBaru ? 0 : 12130,
            'fee_at_settlement' => $gayaBaru,
            'status'            => 'posted',
        ]);

        // Dicairkan dengan rincian yang belum memuat affiliate: potongan 12.130.
        app(MarketplaceEngineService::class)->handle($inv, 12130);

        return $inv->fresh();
    }

    /** Rincian escrow final: admin 12.130 + affiliate 4.329 → saldo masuk 61.540. */
    private function simpanRincianFinal(float $affiliate = 4329, bool $final = true, string $sumber = 'jubelio'): void
    {
        $saldo = 77999 - 12130 - $affiliate;
        app(MarketplaceFeeBreakdownService::class)->simpan($this->so, [
            'kotor' => 77999, 'admin' => 12130, 'admin_rincian' => ['Komisi' => 7410, 'Biaya layanan' => 3120, 'Biaya proses pesanan' => 1250, 'Premi perlindungan pengiriman' => 350],
            'affiliate' => $affiliate, 'pajak' => 0, 'lain' => 0, 'saldo_masuk' => $saldo,
            'sumber' => $sumber, 'final' => $final, 'pajak_asli' => true,
        ]);
    }

    private function saldoWallet(): float
    {
        return round((float) DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.status', '!=', 'void')->where('l.account_id', $this->walletId)
            ->selectRaw('COALESCE(SUM(l.debit - l.credit), 0) v')->value('v'), 2);
    }

    private function jumlahKoreksi(SalesInvoice $inv): int
    {
        return Journal::where('reference_type', MarketplaceEngineService::REF_KOREKSI)
            ->where('reference_id', $inv->id)->where('status', '!=', 'void')->count();
    }

    public function test_rincian_final_menyusul_menurunkan_wallet_ke_saldo_masuk_aktual(): void
    {
        $inv = $this->fakturCair();
        $this->assertEqualsWithDelta(65869.0, $this->saldoWallet(), 0.01, 'sebelum koreksi: affiliate belum dipotong');

        $this->simpanRincianFinal();

        $this->assertEqualsWithDelta(61540.0, $this->saldoWallet(), 0.01, 'wallet = saldo masuk Shopee');
        $this->assertSame(1, $this->jumlahKoreksi($inv));
        $this->assertEqualsWithDelta(4329.0, (float) $inv->fresh()->mp_fee_koreksi, 0.01);

        $beban = (float) DB::table('journal_lines')->where('account_id', $this->feeId)->sum(DB::raw('debit - credit'));
        $this->assertEqualsWithDelta(12130.0 + 4329.0, $beban, 0.01, 'affiliate dibebankan sebagai potongan marketplace');
    }

    public function test_sinkron_berulang_tidak_mengoreksi_dua_kali(): void
    {
        $inv = $this->fakturCair();

        $this->simpanRincianFinal();
        $this->simpanRincianFinal();
        $this->simpanRincianFinal();

        $this->assertSame(1, $this->jumlahKoreksi($inv));
        $this->assertEqualsWithDelta(61540.0, $this->saldoWallet(), 0.01);
    }

    public function test_faktur_gaya_lama_ikut_dikoreksi(): void
    {
        // Faktur lama: dana masuk = grand_total bersih 65.869; premi & affiliate tak pernah dipotong.
        $inv = $this->fakturCair(false);
        $this->assertEqualsWithDelta(65869.0, $this->saldoWallet(), 0.01);

        $this->simpanRincianFinal();

        $this->assertEqualsWithDelta(61540.0, $this->saldoWallet(), 0.01);
        $this->assertEqualsWithDelta(4329.0, (float) $inv->fresh()->mp_fee_koreksi, 0.01);
        $this->assertEqualsWithDelta(12130.0, (float) $inv->fresh()->marketplace_fee, 0.01,
            'marketplace_fee faktur lama tak disentuh — dipakai rekonsiliasi untuk nilai kotor');
    }

    public function test_rincian_belum_final_tidak_dikoreksi(): void
    {
        $inv = $this->fakturCair();

        $this->simpanRincianFinal(4329, false);

        $this->assertSame(0, $this->jumlahKoreksi($inv));
        $this->assertEqualsWithDelta(65869.0, $this->saldoWallet(), 0.01);
    }

    public function test_faktur_yang_sudah_masuk_rekonsiliasi_dilewati(): void
    {
        $inv = $this->fakturCair();
        $config = MarketplaceConfig::where('customer_id', $this->customerId)->first();
        $ms = MarketplaceSettlement::create([
            'number' => 'MS-T-1', 'date' => now()->toDateString(),
            'marketplace_config_id' => $config->id, 'status' => 'posted',
        ]);
        MarketplaceSettlementLine::create([
            'marketplace_settlement_id' => $ms->id, 'order_ref' => 'X', 'net_amount' => 61540,
            'sales_invoice_id' => $inv->id, 'is_matched' => true,
        ]);

        $this->simpanRincianFinal();

        $this->assertSame(0, $this->jumlahKoreksi($inv), 'selisihnya sudah dibukukan rekonsiliasi');
    }

    public function test_rekonsiliasi_menganggap_koreksi_sudah_dibukukan(): void
    {
        foreach ([true, false] as $gayaBaru) {
            DB::table('journal_lines')->delete();
            DB::table('journals')->delete();
            SalesInvoice::query()->delete();

            $inv = $this->fakturCair($gayaBaru);
            $this->simpanRincianFinal();

            $svc = app(MarketplaceSettlementService::class);
            $resolve = (new \ReflectionClass($svc))->getMethod('resolveGross');
            $resolve->setAccessible(true);
            [$gross, $prebooked] = $resolve->invoke($svc, $inv->fresh(), ['net_amount' => 61540]);

            $feeDiff = round(($gross - 61540) - $prebooked, 2);
            $this->assertEqualsWithDelta(77999.0, $gross, 0.01, 'nilai kotor tak berubah oleh koreksi');
            $this->assertEqualsWithDelta(0.0, $feeDiff, 0.01,
                ($gayaBaru ? 'gaya baru' : 'gaya lama') . ': unggah rekonsiliasi tak membukukan selisih yang sama lagi');
        }
    }
}
