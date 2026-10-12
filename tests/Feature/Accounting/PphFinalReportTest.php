<?php

namespace Tests\Feature\Accounting;

use App\Core\Accounting\Account;
use App\Core\Inventory\Warehouse;
use App\Core\Journal\Journal;
use App\Core\Period\AccountingPeriod;
use App\Models\Customer;
use App\Models\MarketplaceConfig;
use App\Models\SalesInvoice;
use App\Models\TaxOmzetAdjustment;
use App\Models\TaxPayment;
use App\Models\TaxAccrual;
use App\Modules\Finance\Services\PphFinalHutangService;
use App\Modules\Finance\Services\PphFinalReportService;
use App\Modules\Sales\Models\SalesAdvance;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\MarketplaceEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PPh Final UMKM 0,5% — marketplace memotong PPh 22 saat pesanan selesai, hanya atas pesanan
 * yang MASUK sejak tanggal mulai pungut (2 Okt 2026); sisanya disetor sendiri. Omzet Rp500 jt
 * pertama setahun bebas pajak. Pajak setor sendiri dibukukan ke 2114 Hutang PPh Final.
 */
class PphFinalReportTest extends TestCase
{
    use RefreshDatabase;

    private int $mpId;
    private int $umumId;
    private int $warehouseId;
    private int $holdId;
    private int $walletId;
    private int $taxId;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        AccountingPeriod::firstOrCreate(
            ['year' => (int) now()->year, 'month' => (int) now()->month],
            ['start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->endOfMonth()->toDateString(), 'status' => 'open']
        );

        $akun = fn (string $code, string $name, string $type, string $normal, string $cat) =>
            Account::firstOrCreate(['code' => $code], [
                'name' => $name, 'type' => $type, 'normal_balance' => $normal, 'account_category' => $cat, 'is_active' => true,
            ])->id;

        $akun('2105', 'Uang Muka Customer', 'liability', 'credit', 'payable');
        $this->holdId   = $akun('1202', 'Saldo Ditahan', 'asset', 'debit', 'cash');
        $this->walletId = $akun('1111', 'Wallet', 'asset', 'debit', 'cash');
        $feeId          = $akun('6101', 'Beban Admin', 'expense', 'debit', 'expense');
        $this->taxId    = $akun('5012', 'PPh Final UMKM 0,5%', 'expense', 'debit', 'expense');
        $akun('2114', 'Hutang PPh Final UMKM', 'liability', 'credit', 'payable');

        $this->mpId = Customer::create(['code' => 'MP', 'name' => 'Shopee', 'is_marketplace' => true, 'is_active' => true])->id;
        $this->umumId = Customer::create(['code' => 'UMUM', 'name' => 'Umum', 'is_active' => true])->id;

        MarketplaceConfig::create([
            'customer_id' => $this->mpId, 'admin_fee_percent' => 0, 'admin_fee_fixed' => 0, 'is_active' => true,
            'account_receivable_hold_id' => $this->holdId, 'account_fee_id' => $feeId, 'account_wallet_id' => $this->walletId,
            'account_tax_id' => $this->taxId, 'tax_percent' => 0.5, 'tax_start_date' => '2026-10-02',
        ]);

        $this->warehouseId = Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id;
    }

    /**
     * Faktur + (untuk marketplace) SO, DP ke hold, tanggal pesanan masuk & selesai di Jubelio.
     * $pesanan kosong = sama dengan tanggal faktur.
     */
    private function faktur(int $customerId, string $tanggal, float $nilai, ?string $selesai = null, array $extra = [], ?string $pesanan = null): SalesInvoice
    {
        $pesanan ??= $tanggal;
        $n = ++$this->seq;
        $soId = null;
        if ($customerId === $this->mpId) {
            $so = SalesOrder::create([
                'order_number' => "SO-$n", 'customer_id' => $customerId, 'warehouse_id' => $this->warehouseId,
                'order_date' => $pesanan, 'status' => 'confirmed', 'subtotal' => $nilai, 'grand_total' => $nilai, 'paid_amount' => $nilai,
            ]);
            SalesAdvance::create([
                'advance_number' => "ADV-$n", 'sales_order_id' => $so->id, 'customer_id' => $customerId,
                'bank_account_id' => $this->holdId, 'advance_date' => $tanggal, 'amount' => $nilai, 'status' => 'posted',
            ]);
            DB::table('jubelio_order_links')->insert([
                'jubelio_salesorder_id' => 1000 + $n, 'sales_order_id' => $so->id,
                'last_status' => $selesai ? 'completed' : 'shipped', 'mp_completed_at' => $selesai,
                'snap_order_date' => $pesanan,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $soId = $so->id;
        }

        return SalesInvoice::create(array_merge([
            'invoice_number' => "INV-$n", 'sales_order_id' => $soId, 'customer_id' => $customerId,
            'warehouse_id' => $this->warehouseId, 'invoice_date' => $tanggal, 'subtotal' => $nilai,
            'grand_total' => $nilai, 'fee_at_settlement' => true, 'status' => 'posted',
        ], $extra));
    }

    private function pajakSettlement(SalesInvoice $inv): float
    {
        $j = Journal::where('reference_type', 'sales_invoice_settlement')->where('reference_id', $inv->id)->firstOrFail();

        return (float) DB::table('journal_lines')->where('journal_id', $j->id)->where('account_id', $this->taxId)->sum('debit');
    }

    public function test_settlement_hanya_memotong_pajak_untuk_pesanan_masuk_sejak_tanggal_pungut(): void
    {
        $tgl = now()->toDateString();
        $sebelum = $this->faktur($this->mpId, $tgl, 78000, '2026-10-05 21:00:00', [], '2026-10-01');
        $sesudah = $this->faktur($this->mpId, $tgl, 78000, '2026-10-05 08:00:00', [], '2026-10-02');

        app(MarketplaceEngineService::class)->handle($sebelum, 0);
        app(MarketplaceEngineService::class)->handle($sesudah, 0);

        $this->assertSame(0.0, $this->pajakSettlement($sebelum), 'masuk 1 Okt, selesai 5 Okt → tidak dipotong marketplace');
        $this->assertEqualsWithDelta(390.0, $this->pajakSettlement($sesudah), 0.01, 'masuk 2 Okt → 0,5% × 78.000');
    }

    public function test_laporan_memisah_dipungut_menunggu_dan_setor_sendiri(): void
    {
        // Jatah bebas Rp500 jt sudah habis oleh omzet di luar ERP awal tahun.
        TaxOmzetAdjustment::create(['tax_type' => TaxPayment::PPH_FINAL_UMKM, 'period' => '2026-01-01', 'amount' => 600_000_000]);

        $this->faktur($this->mpId, '2026-10-03', 200_000, '2026-10-06 10:00:00');                   // dipungut
        $this->faktur($this->mpId, '2026-10-04', 100_000);                                          // menunggu
        $this->faktur($this->umumId, '2026-10-05', 300_000, null, ['delivery_method' => 'ambil_toko']); // kasir
        $this->faktur($this->umumId, '2026-10-05', 50_000, null, ['global_discount_type' => 'nominal', 'global_discount_value' => 10_000]);  // ERP, omzet 40rb

        $d = app(PphFinalReportService::class)->rincianMasa(2026, 10);
        $r = $d['ringkas'];

        $this->assertEqualsWithDelta(640_000, $r['omzet_bruto'], 0.01);
        $this->assertEqualsWithDelta(300_000, $r['omzet_dipungut'], 0.01, 'dipungut + menunggu');
        $this->assertEqualsWithDelta(340_000, $r['omzet_sendiri'], 0.01, 'kasir 300rb + ERP 40rb');
        $this->assertEqualsWithDelta(1_700, $r['harus_setor'], 0.01, '0,5% × 340.000');
        $this->assertEqualsWithDelta(1_000, $r['pajak_dipungut'], 0.01, 'taksiran 0,5% × 200.000');
        $this->assertEqualsWithDelta(500, $r['pajak_menunggu'], 0.01);
        $this->assertSame(['kasir', 'erp'], array_values(array_intersect(['kasir', 'erp'], array_keys($d['kanal']))));
    }

    public function test_pesanan_masuk_sebelum_tanggal_pungut_disetor_sendiri_walau_selesai_sesudahnya(): void
    {
        TaxOmzetAdjustment::create(['tax_type' => TaxPayment::PPH_FINAL_UMKM, 'period' => '2026-01-01', 'amount' => 600_000_000]);

        // Masuk 30 Sep, faktur 1 Okt, selesai 5 Okt → setor sendiri.
        $this->faktur($this->mpId, '2026-10-01', 100_000, '2026-10-05 10:00:00', [], '2026-09-30');
        // Masuk 2 Okt, selesai 6 Okt → dipotong marketplace.
        $this->faktur($this->mpId, '2026-10-02', 200_000, '2026-10-06 10:00:00');

        $okt = app(PphFinalReportService::class)->rincianMasa(2026, 10)['ringkas'];
        $this->assertEqualsWithDelta(100_000, $okt['omzet_sendiri'], 0.01);
        $this->assertEqualsWithDelta(200_000, $okt['omzet_dipungut'], 0.01);
        $this->assertEqualsWithDelta(500, $okt['harus_setor'], 0.01);
    }

    public function test_faktur_bulan_lalu_yang_selesai_bulan_ini_tampil_di_transisi(): void
    {
        $this->faktur($this->mpId, '2026-10-20', 200_000, '2026-11-02 10:00:00');

        $nov = app(PphFinalReportService::class)->rincianMasa(2026, 11);
        $this->assertCount(1, $nov['transisi']);
        $this->assertEqualsWithDelta(200_000, $nov['transisi'][0]->omzet, 0.01);
    }

    public function test_pesanan_diretur_sebelum_selesai_tidak_dianggap_menunggu_pungutan(): void
    {
        $inv = $this->faktur($this->mpId, '2026-08-10', 80_000);
        DB::table('jubelio_order_links')->where('sales_order_id', $inv->sales_order_id)->update(['last_status' => 'returned']);

        $f = app(PphFinalReportService::class)->rincianMasa(2026, 8)['faktur']->firstWhere('id', $inv->id);

        $this->assertSame('sendiri', $f->status);
        $this->assertSame(0.0, $f->pajak, 'tak ada taksiran pajak untuk pesanan retur');
    }

    public function test_pajak_dipungut_dicatat_di_bulan_pesanan_selesai(): void
    {
        $this->faktur($this->mpId, '2026-10-25', 200_000, '2026-11-02 10:00:00');

        $r = app(PphFinalReportService::class)->rekapTahun(2026)['bulan'];

        $this->assertSame(0.0, $r[10]['pungut_bulan'], 'Oktober belum ada pungutan');
        $this->assertEqualsWithDelta(1_000, $r[11]['pungut_bulan'], 0.01, 'dipungut saat selesai di November');
    }

    public function test_jatah_bebas_500_juta_dipakai_berurutan(): void
    {
        TaxOmzetAdjustment::create(['tax_type' => TaxPayment::PPH_FINAL_UMKM, 'period' => '2026-01-01', 'amount' => 499_900_000]);
        $this->faktur($this->umumId, '2026-02-10', 300_000);

        $r = app(PphFinalReportService::class)->rekapTahun(2026)['bulan'];

        $this->assertEqualsWithDelta(0, $r[1]['harus_setor'], 0.01, 'Januari masih di bawah Rp500 jt');
        $this->assertEqualsWithDelta(100_000, $r[2]['omzet_bebas'], 0.01, 'sisa jatah 100rb');
        $this->assertEqualsWithDelta(1_000, $r[2]['harus_setor'], 0.01, '0,5% × (300rb − 100rb)');
    }

    public function test_setoran_masa_menandai_faktur_lunas_berurutan_tanggal(): void
    {
        TaxOmzetAdjustment::create(['tax_type' => TaxPayment::PPH_FINAL_UMKM, 'period' => '2026-01-01', 'amount' => 600_000_000]);
        $a = $this->faktur($this->umumId, '2026-09-03', 200_000);   // pajak 1.000
        $b = $this->faktur($this->umumId, '2026-09-10', 300_000);   // pajak 1.500
        $c = $this->faktur($this->umumId, '2026-09-20', 100_000);   // pajak 500
        $mp = $this->faktur($this->mpId, '2026-09-21', 100_000, '2026-09-25 10:00:00'); // masuk Sep → setor sendiri, 500
        TaxPayment::create(['tax_type' => TaxPayment::PPH_FINAL_UMKM, 'period' => '2026-09-01', 'paid_at' => '2026-10-10', 'amount' => 1_800]);

        $d = app(PphFinalReportService::class)->rincianMasa(2026, 9);
        $f = $d['faktur']->keyBy('id');

        $this->assertEqualsWithDelta(1_000, $f[$a->id]->pajak_setor, 0.01);
        $this->assertSame('lunas', $f[$a->id]->bayar);
        $this->assertSame('sebagian', $f[$b->id]->bayar, 'sisa setoran 800 < 1.500');
        $this->assertSame('belum', $f[$c->id]->bayar);
        $this->assertSame('belum', $f[$mp->id]->bayar, 'pesanan marketplace sebelum tanggal pungut ikut setor sendiri');
        $this->assertSame(1, $d['bayar']['lunas']['faktur']);
        $this->assertEqualsWithDelta(1_000, $d['bayar']['belum']['pajak'], 0.01);
    }

    public function test_retur_tidak_menandai_faktur_lunas_tapi_setoran_penuh_melunasi_semua(): void
    {
        TaxOmzetAdjustment::create(['tax_type' => TaxPayment::PPH_FINAL_UMKM, 'period' => '2026-01-01', 'amount' => 600_000_000]);
        $a = $this->faktur($this->umumId, '2026-09-03', 200_000);
        $b = $this->faktur($this->umumId, '2026-09-10', 300_000);
        DB::table('sales_returns')->insert([
            'return_number' => 'RT-1', 'invoice_id' => $a->id, 'customer_id' => $this->umumId,
            'return_date' => '2026-09-15', 'status' => 'posted', 'reversed_amount' => 100_000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $svc = app(PphFinalReportService::class);
        $f = $svc->rincianMasa(2026, 9)['faktur']->keyBy('id');
        $this->assertSame('belum', $f[$a->id]->bayar, 'belum ada setoran sama sekali');

        // Harus setor = 0,5% × (500rb − 100rb) = 2.000 → setoran 2.000 melunasi kedua faktur.
        TaxPayment::create(['tax_type' => TaxPayment::PPH_FINAL_UMKM, 'period' => '2026-09-01', 'paid_at' => '2026-10-10', 'amount' => 2_000]);
        $f = app(PphFinalReportService::class)->rincianMasa(2026, 9)['faktur']->keyBy('id');
        $this->assertSame('lunas', $f[$a->id]->bayar);
        $this->assertSame('lunas', $f[$b->id]->bayar);
    }

    public function test_hutang_dibukukan_ke_2114_lalu_setoran_melunasinya(): void
    {
        TaxOmzetAdjustment::create(['tax_type' => TaxPayment::PPH_FINAL_UMKM, 'period' => '2026-01-01', 'amount' => 600_000_000]);
        $this->faktur($this->umumId, '2026-10-03', 400_000);   // pajak 2.000

        $hutang = app(PphFinalHutangService::class);
        $masa = \Carbon\Carbon::create(2026, 10, 1);

        $this->assertEqualsWithDelta(2_000, $hutang->bukukan($masa), 0.01);
        $this->assertEqualsWithDelta(2_000, $hutang->saldoHutang(), 0.01, 'Cr 2114');
        $this->assertSame(0.0, $hutang->bukukan($masa), 'sudah sesuai → tak ada jurnal baru');

        // Faktur susulan di masa yang sama → hanya selisihnya yang dijurnal.
        $this->faktur($this->umumId, '2026-10-20', 100_000);
        $this->assertEqualsWithDelta(500, $hutang->bukukan($masa), 0.01);
        $this->assertEqualsWithDelta(2_500, TaxAccrual::pphFinal()->value('amount'), 0.01);

        // Setoran lewat layar: Pengeluaran Dr 2114 / Cr kas → hutang lunas.
        $this->actingAs(\App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->post(route('accounting.reports.pajak.setoran.store'), [
                'period' => '2026-10', 'paid_at' => now()->toDateString(), 'amount' => '2.500',
                'cash_account_id' => $this->walletId,
            ])->assertSessionHas('success');

        $this->assertEqualsWithDelta(0, $hutang->saldoHutang(), 0.01);
        $this->assertTrue((bool) TaxPayment::first()->via_hutang);
        $beban = (float) DB::table('journal_lines')->where('account_id', $this->taxId)->sum(DB::raw('debit - credit'));
        $this->assertEqualsWithDelta(2_500, $beban, 0.01, 'beban 5012 hanya sekali (dari hutang), bukan dari setoran');
    }

    public function test_setoran_lama_langsung_ke_5012_tidak_dibukukan_lagi_sebagai_hutang(): void
    {
        TaxOmzetAdjustment::create(['tax_type' => TaxPayment::PPH_FINAL_UMKM, 'period' => '2026-01-01', 'amount' => 600_000_000]);
        $this->faktur($this->umumId, '2026-08-03', 400_000);   // pajak 2.000
        TaxPayment::create(['tax_type' => TaxPayment::PPH_FINAL_UMKM, 'period' => '2026-08-01', 'paid_at' => '2026-09-10', 'amount' => 1_500]);

        $st = app(PphFinalHutangService::class)->status(\Carbon\Carbon::create(2026, 8, 1));

        $this->assertEqualsWithDelta(500, $st['target'], 0.01, 'hanya kekurangan yang jadi hutang');
    }

    public function test_status_pajak_satu_faktur_dan_berubah_setelah_setoran(): void
    {
        TaxOmzetAdjustment::create(['tax_type' => TaxPayment::PPH_FINAL_UMKM, 'period' => '2026-01-01', 'amount' => 600_000_000]);
        $inv = $this->faktur($this->umumId, '2026-09-03', 200_000);
        $svc = app(PphFinalReportService::class);

        $st = $svc->statusFaktur($inv->id);
        $this->assertSame('sendiri', $st['status']);
        $this->assertEqualsWithDelta(1_000, $st['pajak'], 0.01);
        $this->assertSame('belum', $st['bayar']);
        $this->assertSame('2026-09', $st['masa']->format('Y-m'));

        TaxPayment::create(['tax_type' => TaxPayment::PPH_FINAL_UMKM, 'period' => '2026-09-01', 'paid_at' => '2026-10-10', 'amount' => 1_000]);
        $this->assertSame('lunas', $svc->statusFaktur($inv->id)['bayar'], 'cache ikut berganti setelah setoran');
    }

    public function test_uang_muka_tanpa_faktur_tercatat_agar_tak_terlewat(): void
    {
        $so = SalesOrder::create([
            'order_number' => 'SO-B2B', 'customer_id' => $this->umumId, 'warehouse_id' => $this->warehouseId,
            'order_date' => '2026-09-02', 'status' => 'confirmed', 'subtotal' => 21_300_000, 'grand_total' => 21_300_000,
        ]);
        SalesAdvance::create([
            'advance_number' => 'ADV-B2B', 'sales_order_id' => $so->id, 'customer_id' => $this->umumId,
            'bank_account_id' => $this->walletId, 'advance_date' => '2026-09-15', 'amount' => 10_650_000, 'status' => 'posted',
        ]);
        $this->faktur($this->mpId, '2026-10-03', 100_000);   // marketplace bertaut SO + DP tapi sudah difaktur

        $daftar = app(PphFinalReportService::class)->uangMukaBelumDifaktur();

        $this->assertSame(['SO-B2B'], $daftar->pluck('order_number')->all());
        $this->assertEqualsWithDelta(10_650_000, $daftar[0]->dp, 0.01);
    }
}
