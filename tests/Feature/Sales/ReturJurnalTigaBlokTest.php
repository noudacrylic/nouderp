<?php

namespace Tests\Feature\Sales;

use App\Core\Accounting\Account;
use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Core\Journal\Journal;
use App\Core\Period\AccountingPeriod;
use App\DTO\SalesReturnDTO;
use App\Models\Customer;
use App\Models\CustomerOverpayment;
use App\Models\MarketplaceConfig;
use App\Models\SalesInvoice;
use App\Modules\Finance\Models\MarketplaceSettlementLine;
use App\Modules\Finance\Services\MarketplaceSettlementService;
use App\Modules\Sales\Models\SalesAdvance;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\MarketplaceEngineService;
use App\Modules\Sales\Services\SalesReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Retur jurnal tiga blok: a. HPP (dari kondisi barang) · b. Pembalikan · c. Penyelesaian.
 *
 * Ukuran keberhasilannya sama dengan MarketplaceSiklusPenuhTest: setelah pesanan tuntas lewat
 * retur, Piutang / Uang Muka / Saldo Ditahan pesanan itu NOL — dan potongan yang dicatat
 * (admin — termasuk Hemat Biaya Kirim — + pajak) persis membuat rekonsiliasi tak menemukan selisih.
 */
class ReturJurnalTigaBlokTest extends TestCase
{
    use RefreshDatabase;

    private int $arId;
    private int $advanceId;
    private int $holdId;
    private int $walletId;
    private int $adminId;
    private int $pajakId;
    private int $customerId;
    private SalesOrder $so;
    private Product $produk;

    protected function setUp(): void
    {
        parent::setUp();

        $b = now();
        AccountingPeriod::firstOrCreate(
            ['year' => (int) $b->year, 'month' => (int) $b->month],
            ['start_date' => $b->copy()->startOfMonth()->toDateString(), 'end_date' => $b->copy()->endOfMonth()->toDateString(), 'status' => 'open']
        );

        $akun = fn (string $code, string $name, string $type, string $normal) =>
            Account::firstOrCreate(['code' => $code], [
                'name' => $name, 'type' => $type, 'normal_balance' => $normal, 'is_active' => true,
            ])->id;

        $this->arId      = $akun('1120', 'Piutang Usaha', 'asset', 'debit');
        $this->advanceId = $akun('2105', 'Uang Muka Customer', 'liability', 'credit');
        $akun('2106', 'Kelebihan Bayar Customer', 'liability', 'credit');
        $this->holdId    = $akun('1202', 'Saldo Ditahan Shopee', 'asset', 'debit');
        $this->walletId  = $akun('1104', 'Saldo Penjualan Shopee', 'asset', 'debit');
        $this->adminId   = $akun('5101', 'Beban Admin Shopee', 'expense', 'debit');
        $akun('1130', 'Persediaan Barang', 'asset', 'debit');
        $akun('5001', 'Harga Pokok Penjualan', 'expense', 'debit');
        $akun('6105', 'Beban Kerugian Retur', 'expense', 'debit');
        $akun('4004', 'Retur Penjualan', 'revenue', 'debit');
        $akun('1101', 'Kas', 'asset', 'debit');
        // Dibuat migrasi retur_jurnal_tiga_blok.
        $this->pajakId = (int) Account::where('code', '5012')->value('id');

        $this->customerId = Customer::create([
            'code' => 'CUST-MP', 'name' => 'Shopee', 'is_marketplace' => true, 'is_active' => true,
        ])->id;

        MarketplaceConfig::create([
            'customer_id'                => $this->customerId,
            'admin_fee_percent'          => 10, 'admin_fee_fixed' => 0, 'tax_percent' => 0.5,
            'is_active'                  => true,
            'account_receivable_hold_id' => $this->holdId,
            'account_fee_id'             => $this->adminId,
            'account_wallet_id'          => $this->walletId,
            'account_tax_id'             => $this->pajakId,
        ]);

        $wh = Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id;
        $this->produk = Product::firstOrCreate(['sku' => 'TBKD-A6'], [
            'name' => 'Tempat Brosur A6', 'sale_type' => 'ready', 'base_unit' => 'pcs',
            'base_price' => 50000, 'is_active' => true, 'is_sellable' => true,
        ]);

        $this->so = SalesOrder::create([
            'order_number' => 'SO-MP-1', 'customer_id' => $this->customerId, 'warehouse_id' => $wh,
            'customer_po_number' => 'SP-2609AAAA1111',
            'order_date' => now()->toDateString(), 'status' => 'confirmed',
            'subtotal' => 100000, 'grand_total' => 100000, 'paid_amount' => 100000,
        ]);

        SalesAdvance::create([
            'advance_number' => 'ADV-1', 'sales_order_id' => $this->so->id,
            'customer_id' => $this->customerId, 'bank_account_id' => $this->holdId,
            'advance_date' => now()->toDateString(), 'amount' => 100000, 'status' => 'posted',
        ]);
    }

    /** Faktur gaya baru: 2 pcs @50.000 (HPP 40.000), piutang terbuka, dana masih ditahan. */
    private function faktur(bool $sudahCair = false): SalesInvoice
    {
        $inv = SalesInvoice::create([
            'invoice_number' => 'SP-2609AAAA1111', 'sales_order_id' => $this->so->id,
            'customer_id' => $this->customerId, 'warehouse_id' => $this->so->warehouse_id,
            'invoice_date' => now()->toDateString(), 'subtotal' => 100000, 'grand_total' => 100000,
            'advance_applied' => $sudahCair ? 100000 : 0, 'marketplace_processed' => $sudahCair,
            'fee_at_settlement' => true, 'status' => 'posted',
        ]);

        DB::table('sales_invoice_items')->insert([
            'sales_invoice_id' => $inv->id, 'product_id' => $this->produk->id,
            'description' => 'Tempat Brosur A6', 'item_type' => 'product',
            'qty' => 2, 'unit_price' => 50000, 'subtotal' => 100000,
            'cogs_unit' => 20000, 'cogs_total' => 40000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $inv->fresh();
    }

    private function items(SalesInvoice $inv, string $kondisi, float $qty = 2): array
    {
        $itemId = DB::table('sales_invoice_items')->where('sales_invoice_id', $inv->id)->value('id');

        return [['invoice_item_id' => $itemId, 'qty' => $qty, 'condition' => $kondisi]];
    }

    /** Baris bawaan → peta account_id => [d, c] (baris nol ikut, supaya terlihat ada). */
    private function peta(?array $rows): array
    {
        $out = [];
        foreach ($rows ?? [] as $r) {
            $out[$r['account_id']] = ['d' => (float) $r['debit'], 'c' => (float) $r['credit']];
        }

        return $out;
    }

    /** Ubah nominal satu akun di blok (untuk meniru isian admin). */
    private function setBaris(array $rows, int $akun, float $debit, float $credit): array
    {
        foreach ($rows as &$r) {
            if ((int) $r['account_id'] === $akun) {
                $r['debit'] = $debit;
                $r['credit'] = $credit;
            }
        }

        return $rows;
    }

    private function posting(SalesInvoice $inv, array $items, string $jenis, ?string $banding, array $pembalikan, ?array $penyelesaian): SalesReturn
    {
        return app(SalesReturnService::class)->post(new SalesReturnDTO(
            customer_id: $inv->customer_id, items: $items, date: now()->toDateString(), invoice_id: $inv->id,
            return_type: $jenis, appeal_result: $banding,
            journal_reversal: SalesReturnService::barisTersimpan($pembalikan),
            journal_settlement: SalesReturnService::barisTersimpan($penyelesaian),
        ));
    }

    private function saldo(int $akun): float
    {
        return round((float) DB::table('journal_lines as jl')->join('journals as j', 'j.id', '=', 'jl.journal_id')
            ->where('j.status', '!=', 'void')->where('jl.account_id', $akun)
            ->selectRaw('COALESCE(SUM(jl.debit) - SUM(jl.credit), 0) v')->value('v'), 2);
    }

    private function assertPesananTuntas(SalesInvoice $inv): void
    {
        $inv->refresh();
        $svc = app(SalesReturnService::class);
        $this->assertEqualsWithDelta(0, $inv->remaining_amount, 0.01, 'Piutang faktur nol');
        $this->assertEqualsWithDelta(0, $svc->sisaDitahan($inv), 0.01, 'Saldo Ditahan pesanan nol');
        $this->assertEqualsWithDelta(0, app(MarketplaceEngineService::class)->uangMukaTersedia($inv), 0.01, 'Uang Muka pesanan nol');
        $this->assertTrue((bool) $inv->marketplace_processed);
    }

    // ───────────────────────────── Paket hilang ─────────────────────────────

    public function test_paket_hilang_bawaan_tanpa_pembalikan_dan_penyelesaian_lengkap(): void
    {
        $inv = $this->faktur();
        $b = app(SalesReturnService::class)->jurnalBawaan($inv, $this->items($inv, 'tidak_kembali'), 'paket_hilang', null);

        $this->assertSame([], $b['pembalikan'], 'paket hilang: penjualan sah, tidak dibalik');

        $p = $this->peta($b['penyelesaian']);
        $this->assertEqualsWithDelta(100000, $p[$this->advanceId]['d'], 0.01, 'Dr Uang Muka');
        $this->assertEqualsWithDelta(100000, $p[$this->arId]['c'], 0.01, 'Cr Piutang');
        $this->assertEqualsWithDelta(100000, $p[$this->holdId]['c'], 0.01, 'Cr Saldo Ditahan');
        $this->assertEqualsWithDelta(0, $p[$this->adminId]['d'], 0.01, 'kompensasi paket hilang dibayar kotor — admin 0, barisnya disiapkan utk Hemat Biaya Kirim');
        $this->assertEqualsWithDelta(500, $p[$this->pajakId]['d'], 0.01, 'pajak 0,5% dari kotor');
        $this->assertEqualsWithDelta(99500, $p[$this->walletId]['d'], 0.01, 'Saldo Penjualan = penyeimbang');
    }

    public function test_paket_hilang_posting_menuntaskan_pesanan_dan_mencatat_total_potongan(): void
    {
        $inv = $this->faktur();
        $b = app(SalesReturnService::class)->jurnalBawaan($inv, $this->items($inv, 'tidak_kembali'), 'paket_hilang', null);

        $set = $this->setBaris($b['penyelesaian'], $this->adminId, 350, 0);
        $set = $this->setBaris($set, $this->walletId, 99150, 0);
        $retur = $this->posting($inv, $this->items($inv, 'tidak_kembali'), 'paket_hilang', null, $b['pembalikan'], $set);

        $this->assertPesananTuntas($inv);
        $this->assertEqualsWithDelta(850, (float) $inv->fresh()->marketplace_fee, 0.01, 'potongan tercatat = Hemat Biaya Kirim + pajak');
        $this->assertEqualsWithDelta(350, $this->saldo($this->adminId), 0.01);
        $this->assertEqualsWithDelta(500, $this->saldo($this->pajakId), 0.01);
        $this->assertEqualsWithDelta(99150, $this->saldo($this->walletId), 0.01);
        $this->assertEqualsWithDelta(0, (float) $retur->fresh()->reversed_amount, 0.01);
        // Tanpa pembalikan; barang tak kembali → modalnya jadi Kerugian Retur (= rusak).
        $this->assertEqualsWithDelta(40000, $this->saldo((int) Account::where('code', '6105')->value('id')), 0.01);
        $this->assertEqualsWithDelta(0, $this->saldo((int) Account::where('code', '4004')->value('id')), 0.01, 'omzet tidak dibalik');
    }

    // ───────────────────────────── Gagal kirim ─────────────────────────────

    public function test_gagal_kirim_kalah_membalik_dan_premi_membuat_saldo_penjualan_minus(): void
    {
        $inv = $this->faktur();
        $items = $this->items($inv, 'damaged');
        $b = app(SalesReturnService::class)->jurnalBawaan($inv, $items, 'gagal_kirim', 'kalah');

        $r = $this->peta($b['pembalikan']);
        $retur4004 = (int) Account::where('code', '4004')->value('id');
        $this->assertEqualsWithDelta(100000, $r[$retur4004]['d'], 0.01, 'Dr Retur Penjualan');
        $this->assertEqualsWithDelta(100000, $r[$this->arId]['c'], 0.01, 'Cr Piutang');
        $this->assertEqualsWithDelta(100000, $r[$this->advanceId]['d'], 0.01, 'Dr Uang Muka');
        $this->assertEqualsWithDelta(100000, $r[$this->holdId]['c'], 0.01, 'Cr Saldo Ditahan');

        $set = $this->setBaris($b['penyelesaian'], $this->adminId, 350, 0);
        $set = $this->setBaris($set, $this->walletId, 0, 350);
        $this->posting($inv, $items, 'gagal_kirim', 'kalah', $b['pembalikan'], $set);

        $this->assertPesananTuntas($inv);
        $this->assertEqualsWithDelta(-350, $this->saldo($this->walletId), 0.01, 'premi tetap dibebankan — Saldo Penjualan minus');
        $this->assertEqualsWithDelta(350, (float) $inv->fresh()->marketplace_fee, 0.01);
        $this->assertEqualsWithDelta(40000, $this->saldo((int) Account::where('code', '6105')->value('id')), 0.01, 'HPP barang rusak → kerugian');
    }

    public function test_penjualan_dibalik_ditentukan_kasus_bukan_kondisi_barang(): void
    {
        $inv = $this->faktur();
        $svc = app(SalesReturnService::class);

        // Paket hilang: kondisi apa pun, penjualan tidak dibalik — kecuali klaim ditolak.
        $this->assertSame(0.0, $svc->jurnalBawaan($inv, $this->items($inv, 'damaged'), 'paket_hilang', null)['dibalik']);
        $this->assertEqualsWithDelta(100000, $svc->jurnalBawaan($inv, $this->items($inv, 'tidak_kembali'), 'paket_hilang', 'kalah')['dibalik'], 0.01);
        // Diajukan konsumen: barang tak kembali tetap membalik penjualan (refund penuh).
        $this->assertEqualsWithDelta(100000, $svc->jurnalBawaan($inv, $this->items($inv, 'tidak_kembali'), 'diajukan_konsumen', null)['dibalik'], 0.01);
    }

    public function test_gagal_kirim_banding_menang_tidak_membalik_penjualan(): void
    {
        $inv = $this->faktur();
        $b = app(SalesReturnService::class)->jurnalBawaan($inv, $this->items($inv, 'damaged'), 'gagal_kirim', 'menang');

        $this->assertSame([], $b['pembalikan']);
        $p = $this->peta($b['penyelesaian']);
        $this->assertEqualsWithDelta(100000, $p[$this->holdId]['c'], 0.01);
        $this->assertEqualsWithDelta(99500, $p[$this->walletId]['d'], 0.01, 'menang = kompensasi kotor, tanpa admin');
    }

    // ────────────────────────── Diajukan konsumen ──────────────────────────

    public function test_refund_sebagian_barang_tetap_di_pembeli_sisanya_dicairkan(): void
    {
        $inv = $this->faktur();
        $items = $this->items($inv, 'tidak_kembali');
        $b = app(SalesReturnService::class)->jurnalBawaan($inv, $items, 'diajukan_konsumen', null, null, null, 30000);

        $p = $this->peta($b['penyelesaian']);
        $this->assertEqualsWithDelta(70000, $p[$this->holdId]['c'], 0.01, 'sisa titipan dicairkan');
        $this->assertEqualsWithDelta(70000, $p[$this->arId]['c'], 0.01);
        $this->assertEqualsWithDelta(350, $p[$this->pajakId]['d'], 0.01);
        $this->assertEqualsWithDelta(62650, $p[$this->walletId]['d'], 0.01, '70.000 − admin 7.000 − pajak 350');

        $retur = $this->posting($inv, $items, 'diajukan_konsumen', null, $b['pembalikan'], $b['penyelesaian']);

        $this->assertPesananTuntas($inv);
        $this->assertEqualsWithDelta(30000, (float) $retur->fresh()->reversed_amount, 0.01);
        $this->assertEqualsWithDelta(7350, (float) $inv->fresh()->marketplace_fee, 0.01, 'admin + pajak atas sisa pesanan');
        $this->assertEqualsWithDelta(40000, $this->saldo((int) Account::where('code', '6105')->value('id')), 0.01, 'Tidak Kembali = Rusak');
    }

    public function test_pelanggan_biasa_retur_jadi_kredit_pelanggan(): void
    {
        $biasa = Customer::create(['code' => 'CUST-B', 'name' => 'Toko Budi', 'is_active' => true]);
        $inv = SalesInvoice::create([
            'invoice_number' => 'SI-1', 'customer_id' => $biasa->id, 'warehouse_id' => $this->so->warehouse_id,
            'invoice_date' => now()->toDateString(), 'subtotal' => 100000, 'grand_total' => 100000,
            'paid_amount' => 100000, 'status' => 'posted',
        ]);
        DB::table('sales_invoice_items')->insert([
            'sales_invoice_id' => $inv->id, 'product_id' => $this->produk->id, 'description' => 'x',
            'item_type' => 'product', 'qty' => 2, 'unit_price' => 50000, 'subtotal' => 100000,
            'cogs_unit' => 20000, 'cogs_total' => 40000, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $inv = $inv->fresh();

        $b = app(SalesReturnService::class)->jurnalBawaan($inv, $this->items($inv, 'damaged'), 'diajukan_konsumen', null);
        $this->assertNull($b['penyelesaian'], 'bukan marketplace → tak ada blok Penyelesaian');
        $this->assertArrayHasKey('bank', $b['tujuan']);

        $retur = $this->posting($inv, $this->items($inv, 'damaged'), 'diajukan_konsumen', null, $b['pembalikan'], null);

        $this->assertEqualsWithDelta(100000, (float) CustomerOverpayment::where('reference', $retur->return_number)->sum('amount'), 0.01);
        $this->assertSame('credit', $retur->fresh()->refund_target);
    }

    // ──────────────────────────────── Penjaga ────────────────────────────────

    public function test_penyelesaian_ditolak_untuk_faktur_yang_sudah_cair(): void
    {
        $inv = $this->faktur(sudahCair: true);
        $this->expectExceptionMessage('Blok Penyelesaian hanya untuk faktur marketplace');
        $this->posting($inv, $this->items($inv, 'tidak_kembali'), 'paket_hilang', null, [], [
            ['account_id' => $this->walletId, 'debit' => 1000, 'credit' => 0, 'memo' => null],
            ['account_id' => $this->holdId, 'debit' => 0, 'credit' => 1000, 'memo' => null],
        ]);
    }

    public function test_saldo_ditahan_melebihi_titipan_ditolak(): void
    {
        $inv = $this->faktur();
        $this->expectExceptionMessage('Saldo Ditahan yang dilepas');
        $this->posting($inv, $this->items($inv, 'tidak_kembali'), 'paket_hilang', null, [], [
            ['account_id' => $this->walletId, 'debit' => 150000, 'credit' => 0, 'memo' => null],
            ['account_id' => $this->holdId, 'debit' => 0, 'credit' => 150000, 'memo' => null],
        ]);
    }

    public function test_void_mengembalikan_faktur_ke_belum_cair_dan_potongannya(): void
    {
        $inv = $this->faktur();
        $b = app(SalesReturnService::class)->jurnalBawaan($inv, $this->items($inv, 'tidak_kembali'), 'paket_hilang', null);
        $retur = $this->posting($inv, $this->items($inv, 'tidak_kembali'), 'paket_hilang', null, $b['pembalikan'], $b['penyelesaian']);

        $this->actingAs(\App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->post(route('sales.returns.void', $retur->id))
            ->assertSessionMissing('error');

        $inv->refresh();
        $this->assertFalse((bool) $inv->marketplace_processed);
        $this->assertEqualsWithDelta(0, (float) $inv->marketplace_fee, 0.01);
        $this->assertEqualsWithDelta(0, (float) $inv->advance_applied, 0.01);
        $this->assertSame('belum_cair', $inv->paymentState()['key']);
    }

    // ───────────────────────────── Rekonsiliasi ─────────────────────────────

    public function test_rekonsiliasi_menunggu_retur_lalu_cocok_tanpa_selisih(): void
    {
        $this->actingAs(\App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        $inv = $this->faktur();
        $config = MarketplaceConfig::where('customer_id', $this->customerId)->first();

        // Laporan Shopee datang LEBIH DULU daripada retur diselesaikan di ERP (tanggal geser).
        $ms = app(MarketplaceSettlementService::class)->createDraft($config, [[
            'order_ref' => '2609AAAA1111', 'settlement_date' => now()->toDateString(),
            'net_amount' => 99150, 'gross_amount' => 0, 'raw_row' => null,
        ]], []);

        $line = MarketplaceSettlementLine::where('marketplace_settlement_id', $ms->id)->firstOrFail();
        $this->assertFalse((bool) $line->is_matched, 'belum cair di ERP → ditahan, tidak dibukukan');
        $this->assertSame($inv->id, (int) $line->sales_invoice_id);
        $this->assertEqualsWithDelta(0, (float) $line->fee_diff, 0.01);

        $b = app(SalesReturnService::class)->jurnalBawaan($inv, $this->items($inv, 'tidak_kembali'), 'paket_hilang', null);
        $set = $this->setBaris($b['penyelesaian'], $this->adminId, 350, 0);
        $set = $this->setBaris($set, $this->walletId, 99150, 0);
        $this->posting($inv, $this->items($inv, 'tidak_kembali'), 'paket_hilang', null, [], $set);

        $line->refresh();
        $this->assertTrue((bool) $line->is_matched, 'retur selesai → baris dicocokkan otomatis');
        $this->assertEqualsWithDelta(850, (float) $line->fee_actual, 0.01, 'potongan aktual = kotor − cair');
        $this->assertEqualsWithDelta(850, (float) $line->fee_prebooked, 0.01, 'potongan tercatat retur (admin + pajak)');
        $this->assertEqualsWithDelta(0, (float) $line->fee_diff, 0.01, 'tak ada selisih yang dibukukan dua kali');
    }

    // ──────────────────────────────── Form ────────────────────────────────

    public function test_form_menyelesaikan_retur_dengan_angka_berformat_indonesia(): void
    {
        $this->actingAs(\App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        $inv = $this->faktur();
        $items = $this->items($inv, 'tidak_kembali');

        $bawaan = $this->postJson(route('sales.ajax.returns.jurnal_bawaan'), [
            'invoice_id' => $inv->id, 'items' => $items, 'return_type' => 'paket_hilang',
        ])->assertOk()->json();
        $this->assertSame([], $bawaan['pembalikan']);

        $rows = collect($bawaan['penyelesaian'])->map(fn ($r) => [
            'account_id' => $r['account_id'],
            'debit'  => $r['debit'] > 0 ? number_format($r['debit'], 0, ',', '.') : '',
            'credit' => $r['credit'] > 0 ? number_format($r['credit'], 0, ',', '.') : '',
        ])->all();

        $this->post(route('sales.returns.store'), [
            'status' => 'posted', 'customer_id' => $inv->customer_id, 'invoice_id' => $inv->id,
            'return_date' => now()->toDateString(), 'return_type' => 'paket_hilang', 'return_case' => 'PH1',
            'items' => $items, 'tiga_blok' => 1,
            'jurnal_ada' => ['pembalikan' => 1, 'penyelesaian' => 1],
            'jurnal' => ['penyelesaian' => $rows],
        ])->assertSessionMissing('error');

        $this->assertPesananTuntas($inv);
        $this->assertEqualsWithDelta(99500, $this->saldo($this->walletId), 0.01);
    }

    public function test_kasus_menentukan_hasil_banding_dan_wajib_saat_selesai(): void
    {
        $this->actingAs(\App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        $inv = $this->faktur();
        $isian = [
            'status' => 'posted', 'customer_id' => $inv->customer_id, 'invoice_id' => $inv->id,
            'return_date' => now()->toDateString(), 'return_type' => 'gagal_kirim',
            'items' => $this->items($inv, 'damaged'), 'tiga_blok' => 1,
        ];

        $this->post(route('sales.returns.store'), $isian)->assertSessionHas('error', 'Pilih Kasus dulu sebelum menyelesaikan retur.');

        // Draft boleh tanpa kasus; kasus "Banding menang" → hasil banding tersimpan menang.
        $this->post(route('sales.returns.store'), array_merge($isian, ['status' => 'draft', 'return_case' => 'GK2']))
            ->assertSessionMissing('error');
        $retur = SalesReturn::where('invoice_id', $inv->id)->firstOrFail();
        $this->assertSame('GK2', $retur->return_case);
        $this->assertSame('menang', $retur->appeal_result);
        $this->assertSame('Banding menang', $retur->caseLabel());

        // Kasus milik jenis lain tidak disimpan.
        $this->post(route('sales.returns.store'), array_merge($isian, ['status' => 'draft', 'return_id' => $retur->id, 'return_case' => 'K4']));
        $this->assertNull($retur->fresh()->return_case);
    }

    public function test_draft_tidak_membekukan_blok_yang_belum_diubah(): void
    {
        $this->actingAs(\App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        $inv = $this->faktur();

        // Form draft: blok tak diubah → input `jurnal_ada` & barisnya tak dikirim (disabled).
        $this->post(route('sales.returns.store'), [
            'status' => 'draft', 'customer_id' => $inv->customer_id, 'invoice_id' => $inv->id,
            'return_date' => now()->toDateString(), 'return_type' => 'gagal_kirim',
            'items' => $this->items($inv, 'damaged'), 'tiga_blok' => 1,
        ])->assertSessionMissing('error');

        $retur = SalesReturn::where('invoice_id', $inv->id)->firstOrFail();
        $this->assertNull($retur->journal_reversal);
        $this->assertNull($retur->journal_settlement);

        // Diposting dari daftar → bawaan dihitung saat itu juga.
        $this->post(route('sales.returns.post', $retur->id))->assertSessionMissing('error');
        $this->assertSame('posted', $retur->fresh()->status);
        $this->assertEqualsWithDelta(100000, (float) $retur->fresh()->reversed_amount, 0.01);
        $this->assertPesananTuntas($inv);
    }

    public function test_halaman_edit_menampilkan_panduan_dan_tombol_banding_di_luar_form_retur(): void
    {
        $this->actingAs(\App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        $inv = $this->faktur();
        $retur = app(SalesReturnService::class)->saveDraft(new SalesReturnDTO(
            customer_id: $inv->customer_id, items: $this->items($inv, 'damaged'), date: now()->toDateString(),
            invoice_id: $inv->id, return_type: 'gagal_kirim',
        ));

        $html = $this->get(route('sales.returns.edit', $retur->id))->assertOk()->getContent();

        $this->assertStringContainsString('Panduan Jurnal Retur', $html);
        $this->assertStringContainsString('form="tahapForm"', $html);
        // Form Banding tak boleh bersarang di dalam form retur.
        $retur_ = substr($html, strpos($html, 'id="returnForm"'));
        $this->assertStringNotContainsString('<form', substr($retur_, 0, strpos($retur_, '</form>')));
    }

    // ─────────────────────── Pencairan pesanan normal ───────────────────────

    public function test_pencairan_normal_memisah_pajak_dari_biaya_admin(): void
    {
        $inv = $this->faktur();
        app(MarketplaceEngineService::class)->handle($inv, 12000);

        $this->assertEqualsWithDelta(500, $this->saldo($this->pajakId), 0.01, 'pajak 0,5% ke akunnya sendiri');
        $this->assertEqualsWithDelta(87500, $this->saldo($this->walletId), 0.01);
        $this->assertEqualsWithDelta(12500, (float) $inv->fresh()->marketplace_fee, 0.01, 'potongan tercatat = admin + pajak');
    }

    public function test_potongan_dari_jubelio_sudah_memuat_pajak(): void
    {
        $inv = $this->faktur();
        app(MarketplaceEngineService::class)->handle($inv, 12000, null, null, true);

        $this->assertEqualsWithDelta(500, $this->saldo($this->pajakId), 0.01);
        $this->assertEqualsWithDelta(88000, $this->saldo($this->walletId), 0.01, 'total potongan tetap 12.000');
        $this->assertEqualsWithDelta(12000, (float) $inv->fresh()->marketplace_fee, 0.01);
    }
}
