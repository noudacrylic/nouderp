<?php

namespace Tests\Feature\Sales;

use App\Core\Accounting\Account;
use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Core\Journal\Journal;
use App\Core\Period\AccountingPeriod;
use App\DTO\SalesReturnDTO;
use App\Models\Customer;
use App\Models\MarketplaceConfig;
use App\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesAdvance;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Posting retur = penyelesaian faktur marketplace yang dananya BELUM CAIR.
 *
 * Pesanan yang berakhir retur tak pernah berstatus "selesai" di Jubelio, jadi pencairan
 * Saldo Ditahan → Saldo Penjualan tak pernah jalan: faktur menggantung "Belum Cair" dan
 * dana pengganti dari marketplace (paket hilang, banding menang) tak pernah tercatat masuk
 * dompet. Kini retur yang diposting menuntaskan fakturnya — status jadi "Retur" — dengan
 * fee 0 (potongan dana pengganti bukan biaya admin; selisihnya urusan rekonsiliasi).
 */
class ReturPenyelesaianFakturTest extends TestCase
{
    use RefreshDatabase;

    private int $arId;
    private int $advanceId;
    private int $holdId;
    private int $walletId;
    private int $customerId;
    private SalesOrder $so;
    private Product $produk;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([now(), now()->subMonthNoOverflow()] as $bulan) {
            AccountingPeriod::firstOrCreate(
                ['year' => (int) $bulan->year, 'month' => (int) $bulan->month],
                ['start_date' => $bulan->copy()->startOfMonth()->toDateString(),
                 'end_date'   => $bulan->copy()->endOfMonth()->toDateString(), 'status' => 'open']
            );
        }

        $akun = fn (string $code, string $name, string $type, string $normal) =>
            Account::firstOrCreate(['code' => $code], [
                'name' => $name, 'type' => $type, 'normal_balance' => $normal, 'is_active' => true,
            ])->id;

        $this->arId      = $akun('1120', 'Piutang Usaha', 'asset', 'debit');
        $this->advanceId = $akun('2105', 'Uang Muka Customer', 'liability', 'credit');
        $akun('2106', 'Kelebihan Bayar Customer', 'liability', 'credit');
        $this->holdId    = $akun('1202', 'Saldo Ditahan Shopee', 'asset', 'debit');
        $this->walletId  = $akun('1104', 'Saldo Penjualan Shopee', 'asset', 'debit');
        $feeId           = $akun('5101', 'Beban Admin Shopee', 'expense', 'debit');
        $akun('1130', 'Persediaan Barang', 'asset', 'debit');
        $akun('5001', 'Harga Pokok Penjualan', 'expense', 'debit');
        $akun('6105', 'Beban Kerugian Retur', 'expense', 'debit');

        $this->customerId = Customer::create([
            'code' => 'CUST-MP', 'name' => 'Shopee', 'is_marketplace' => true, 'is_active' => true,
        ])->id;

        MarketplaceConfig::create([
            'customer_id'                => $this->customerId,
            'admin_fee_percent'          => 0, 'admin_fee_fixed' => 0, 'is_active' => true,
            'account_receivable_hold_id' => $this->holdId,
            'account_fee_id'             => $feeId,
            'account_wallet_id'          => $this->walletId,
        ]);

        $wh = Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id;

        $this->produk = Product::firstOrCreate(['sku' => 'TBKD-A6'], [
            'name' => 'Tempat Brosur A6', 'sale_type' => 'ready', 'base_unit' => 'pcs',
            'base_price' => 50000, 'is_active' => true, 'is_sellable' => true,
        ]);

        $this->so = SalesOrder::create([
            'order_number' => 'SO-MP-1', 'customer_id' => $this->customerId, 'warehouse_id' => $wh,
            'order_date' => now()->toDateString(), 'status' => 'confirmed',
            'subtotal' => 100000, 'grand_total' => 100000, 'paid_amount' => 100000,
        ]);

        SalesAdvance::create([
            'advance_number' => 'ADV-1', 'sales_order_id' => $this->so->id,
            'customer_id' => $this->customerId, 'bank_account_id' => $this->holdId,
            'advance_date' => now()->toDateString(), 'amount' => 100000, 'status' => 'posted',
        ]);
    }

    /** Faktur gaya baru: 2 pcs @50.000, piutang terbuka, dana masih ditahan. */
    private function faktur(bool $sudahCair = false): SalesInvoice
    {
        $inv = SalesInvoice::create([
            'invoice_number'        => 'SP-1', 'sales_order_id' => $this->so->id,
            'customer_id'           => $this->customerId,
            'warehouse_id'          => $this->so->warehouse_id,
            'invoice_date'          => now()->toDateString(),
            'subtotal'              => 100000, 'grand_total' => 100000,
            'advance_applied'       => $sudahCair ? 100000 : 0,
            'marketplace_processed' => $sudahCair,
            'fee_at_settlement'     => true,
            'status'                => 'posted',
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

    /** @param array<array{0:string,1:float}> $baris pasangan [kondisi, qty] */
    private function retur(SalesInvoice $inv, array $baris, ?string $tanggal = null): SalesReturn
    {
        $itemId = DB::table('sales_invoice_items')->where('sales_invoice_id', $inv->id)->value('id');

        return app(SalesReturnService::class)->post(new SalesReturnDTO(
            customer_id: $inv->customer_id,
            items: array_map(fn ($b) => ['invoice_item_id' => $itemId, 'qty' => $b[1], 'condition' => $b[0]], $baris),
            date: $tanggal ?? now()->toDateString(),
            invoice_id: $inv->id,
        ));
    }

    /** @return array<int,array{d:float,c:float}> */
    private function barisPencairan(SalesInvoice $inv): array
    {
        $j = Journal::where('reference_type', 'sales_invoice_settlement')
            ->where('reference_id', $inv->id)->where('status', '!=', 'void')->first();
        if (!$j) {
            return [];
        }
        $per = [];
        foreach (DB::table('journal_lines')->where('journal_id', $j->id)->get() as $l) {
            $id = (int) $l->account_id;
            $per[$id]['d'] = round(($per[$id]['d'] ?? 0) + (float) $l->debit, 2);
            $per[$id]['c'] = round(($per[$id]['c'] ?? 0) + (float) $l->credit, 2);
        }
        return $per;
    }

    public function test_banding_menang_mencairkan_saldo_ditahan_ke_saldo_penjualan(): void
    {
        $inv   = $this->faktur();
        $retur = $this->retur($inv, [['tidak_kembali', 2]]);

        $per = $this->barisPencairan($inv);
        $this->assertEqualsWithDelta(100000, $per[$this->advanceId]['d'] ?? 0, 0.01, 'Dr Uang Muka — faktur tuntas');
        $this->assertEqualsWithDelta(100000, $per[$this->arId]['c'] ?? 0, 0.01, 'Cr Piutang');
        $this->assertEqualsWithDelta(100000, $per[$this->walletId]['d'] ?? 0, 0.01, 'Dr Saldo Penjualan, fee 0');
        $this->assertEqualsWithDelta(100000, $per[$this->holdId]['c'] ?? 0, 0.01, 'Cr Saldo Ditahan');

        $inv->refresh();
        $this->assertTrue((bool) $inv->marketplace_processed);
        $this->assertEqualsWithDelta(0, (float) $inv->marketplace_fee, 0.01);
        $this->assertSame('retur', $inv->paymentState()['key']);
        $this->assertNotNull($retur->fresh()->settlement_journal_id);
    }

    public function test_semua_barang_kembali_menghapus_piutang_tanpa_pencairan(): void
    {
        $inv   = $this->faktur();
        $retur = $this->retur($inv, [['damaged', 2]]);

        $this->assertSame([], $this->barisPencairan($inv), 'seluruh titipan kembali ke pembeli — tak ada yang cair');

        $inv->refresh();
        $this->assertEqualsWithDelta(100000, (float) $inv->returned_amount, 0.01);
        $this->assertEqualsWithDelta(0, $inv->remaining_amount, 0.01);
        $this->assertSame('retur', $inv->paymentState()['key']);
        $this->assertTrue((bool) $inv->marketplace_processed, 'status "selesai" Jubelio tak boleh mencairkan lagi');
        $this->assertEqualsWithDelta(100000, (float) $retur->fresh()->ar_credited, 0.01);
    }

    public function test_campuran_hanya_sisa_yang_dicairkan(): void
    {
        $inv = $this->faktur();
        $this->retur($inv, [['damaged', 1], ['tidak_kembali', 1]]);

        // 50.000 kembali ke pembeli lewat jurnal retur; 50.000 sisanya cair ke dompet.
        $per = $this->barisPencairan($inv);
        $this->assertEqualsWithDelta(50000, $per[$this->advanceId]['d'] ?? 0, 0.01);
        $this->assertEqualsWithDelta(50000, $per[$this->arId]['c'] ?? 0, 0.01);
        $this->assertEqualsWithDelta(50000, $per[$this->walletId]['d'] ?? 0, 0.01);
        $this->assertEqualsWithDelta(50000, $per[$this->holdId]['c'] ?? 0, 0.01);

        $inv->refresh();
        $this->assertEqualsWithDelta(0, $inv->remaining_amount, 0.01);
        $this->assertSame('retur', $inv->paymentState()['key']);
    }

    public function test_tanggal_pencairan_tidak_sebelum_tanggal_faktur(): void
    {
        $inv = $this->faktur();
        $this->retur($inv, [['tidak_kembali', 2]], now()->subMonthNoOverflow()->toDateString());

        $j = Journal::where('reference_type', 'sales_invoice_settlement')->where('reference_id', $inv->id)->firstOrFail();
        $this->assertSame(now()->toDateString(), \Carbon\Carbon::parse($j->date)->toDateString());
    }

    public function test_faktur_yang_sudah_cair_tidak_dicairkan_lagi(): void
    {
        $inv = $this->faktur(sudahCair: true);
        $this->retur($inv, [['tidak_kembali', 2]]);

        $this->assertSame([], $this->barisPencairan($inv));
    }

    /**
     * Retur selesai SETELAH dana pesanan dilepas (retur 312): Shopee memotong refund dari
     * Saldo Penjual ("Pengembalian Barang/Dana setelah Dana Dilepaskan") dan membayar
     * kompensasi terpisah. Banding menang pun, bawaan blok b harus membalik penjualan penuh
     * dan memotong Saldo Penjualan PERSIS potongan Shopee — sisa biaya kembali ke Beban Admin —
     * agar sisa dana pesanan = Penghasilan Akhir Shopee.
     */
    public function test_refund_setelah_dana_dilepas_mengikuti_potongan_shopee(): void
    {
        $returId = Account::firstOrCreate(['code' => '4004'], [
            'name' => 'Retur Penjualan', 'type' => 'revenue', 'normal_balance' => 'debit', 'is_active' => true,
        ])->id;
        $feeId = Account::where('code', '5101')->value('id');

        $inv = $this->faktur(sudahCair: true);
        $inv->forceFill(['marketplace_fee' => 14000])->save();

        $j = Journal::create([
            'journal_number' => 'JRN-CAIR-1', 'date' => now()->toDateString(),
            'reference_type' => 'sales_invoice_settlement', 'reference_id' => $inv->id,
            'description' => 'cair', 'status' => 'posted',
            'period_id' => AccountingPeriod::where('year', (int) now()->year)->where('month', (int) now()->month)->value('id'),
        ]);
        foreach ([[$this->walletId, 86000, 0], [$feeId, 14000, 0], [$this->holdId, 0, 100000]] as [$a, $d, $c]) {
            DB::table('journal_lines')->insert(['journal_id' => $j->id, 'account_id' => $a, 'debit' => $d, 'credit' => $c,
                'created_at' => now(), 'updated_at' => now()]);
        }

        DB::table('shopee_orders')->insert(['env' => 'live', 'shop_id' => 1, 'order_sn' => 'SN312', 'status' => 'COMPLETED',
            'sales_order_id' => $this->so->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['ADJUSTMENT_FOR_RR_AFTER_ESCROW_VERIFIED', 'penyesuaian_pesanan', -88000],
                  ['RETURN_COMPENSATION_SERVICE_ADD', 'kompensasi', 40000]] as $i => [$tipe, $kat, $jml]) {
            DB::table('shopee_wallet_transactions')->insert(['sumber' => 'api', 'kunci' => 'k' . $i, 'waktu' => now(),
                'tipe' => $tipe, 'deskripsi' => $tipe, 'order_sn' => 'SN312', 'jumlah' => $jml, 'kategori' => $kat,
                'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('marketplace_order_fees')->insert(['sales_order_id' => $this->so->id, 'kotor' => 100000,
            'saldo_masuk' => 86000, 'saldo_akhir' => 38000, 'sumber' => 'shopee', 'final' => true,
            'created_at' => now(), 'updated_at' => now()]);

        $itemId = DB::table('sales_invoice_items')->where('sales_invoice_id', $inv->id)->value('id');
        $b = app(SalesReturnService::class)->jurnalBawaan($inv->fresh(),
            [['invoice_item_id' => $itemId, 'qty' => 2, 'condition' => 'tidak_kembali']], 'diajukan_konsumen', 'menang', 'wallet');

        // Satu penyesuaian bersih: −88.000 + 40.000 = −48.000; dibalik = 100.000 − 40.000.
        $this->assertEqualsWithDelta(60000, $b['dibalik'], 0.01);
        $per = fn (int $akun, string $sisi) => collect($b['pembalikan'])->where('account_id', $akun)->sum($sisi);
        $this->assertEqualsWithDelta(60000, $per($returId, 'debit'), 0.01);
        $this->assertEqualsWithDelta(48000, $per($this->walletId, 'credit'), 0.01, 'Saldo Penjualan = penyesuaian bersih Shopee');
        $this->assertEqualsWithDelta(12000, $per($feeId, 'credit'), 0.01, 'biaya yang Shopee kembalikan');

        $p = $b['konteks']['pencairan'];
        $this->assertEqualsWithDelta(-48000, $p['penyesuaian_retur_bersih'], 0.01);
        $this->assertEqualsWithDelta(2000, $p['biaya_tak_kembali'], 0.01, '88.000 = dana masuk 86.000 + 2.000');
        $this->assertSame([], $p['penyesuaian_luar'], 'kompensasi masuk retur, bukan dicatat terpisah');
        $this->assertEqualsWithDelta(38000, $p['penghasilan_akhir_shopee'], 0.01);
        $this->assertEqualsWithDelta(38000, $p['dana_masuk'] + $p['retur_lalu'] - $per($this->walletId, 'credit'), 0.01);

        // Posting dengan blok b bawaan → Saldo Penjualan pesanan ini = Penghasilan Akhir Shopee.
        $retur = app(SalesReturnService::class)->post(new SalesReturnDTO(
            customer_id: $inv->customer_id,
            items: [['invoice_item_id' => $itemId, 'qty' => 2, 'condition' => 'tidak_kembali']],
            date: now()->toDateString(), invoice_id: $inv->id,
            return_type: 'diajukan_konsumen', refund_target: 'wallet', appeal_result: 'menang',
            journal_reversal: $b['pembalikan'], return_case: 'K8',
        ));
        $dompet = (float) DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.status', 'posted')->where('l.account_id', $this->walletId)->selectRaw('SUM(l.debit - l.credit) v')->value('v');
        $this->assertSame('posted', $retur->fresh()->status);
        $this->assertEqualsWithDelta(38000, $dompet, 0.01);
        $this->assertEqualsWithDelta(0, app(SalesReturnService::class)->jurnalBawaan($inv->fresh(),
            [['invoice_item_id' => $itemId, 'qty' => 2, 'condition' => 'tidak_kembali']], 'diajukan_konsumen', 'menang', 'wallet')
            ['konteks']['pencairan']['penyesuaian_retur_bersih'] ?? 0, 0.01, 'sudah dipotong retur ini');
    }

    public function test_void_retur_membatalkan_penyelesaian(): void
    {
        $inv   = $this->faktur();
        $retur = $this->retur($inv, [['damaged', 1], ['tidak_kembali', 1]]);
        $jId   = $retur->fresh()->settlement_journal_id;

        $this->actingAs(\App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->post(route('sales.returns.void', $retur->id))
            ->assertSessionHasNoErrors()->assertSessionMissing('error');

        $this->assertSame('void', Journal::find($jId)->status);

        $inv->refresh();
        $this->assertEqualsWithDelta(0, (float) $inv->advance_applied, 0.01);
        $this->assertEqualsWithDelta(0, (float) $inv->returned_amount, 0.01);
        $this->assertFalse((bool) $inv->marketplace_processed);
        $this->assertSame('belum_cair', $inv->paymentState()['key']);
    }
}
