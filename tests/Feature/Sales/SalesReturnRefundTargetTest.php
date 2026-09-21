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
use App\Modules\Sales\Models\SalesAdvance;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ke mana uang retur dikembalikan.
 *
 * Aturannya satu dan urutannya yang membuatnya benar: HAPUS TAGIHAN dulu sebesar piutang yang
 * masih terbuka, sisanya barulah uang yang sudah kita terima dan perlu dikembalikan ke suatu
 * tempat. Dulu tujuan dana tidak pernah ditanyakan — pelanggan marketplace SELALU mengkredit
 * Saldo Ditahan, dan itu membuat akunnya MINUS begitu pesanan sudah selesai (saldonya sudah
 * kosong, uangnya ada di dompet).
 *
 * Kasus yang melahirkan tes ini: pembeli Shopee menukar ukuran atas pesanan yang sudah selesai.
 * Tidak ada uang keluar sama sekali — yang dia terima adalah hak beli, dan biaya admin yang
 * sudah hangus ditanggung dia, bukan toko.
 */
class SalesReturnRefundTargetTest extends TestCase
{
    use RefreshDatabase;

    private int $arId;
    private int $advanceId;
    private int $holdId;
    private int $walletId;
    private int $feeId;
    private int $overpayId;
    private int $returId;
    private int $customerId;
    private SalesOrder $so;
    private Product $produk;

    protected function setUp(): void
    {
        parent::setUp();

        AccountingPeriod::firstOrCreate(
            ['year' => (int) now()->year, 'month' => (int) now()->month],
            ['start_date' => now()->startOfMonth()->toDateString(),
             'end_date'   => now()->endOfMonth()->toDateString(), 'status' => 'open']
        );

        $akun = fn (string $code, string $name, string $type, string $normal) =>
            Account::firstOrCreate(['code' => $code], [
                'name' => $name, 'type' => $type, 'normal_balance' => $normal, 'is_active' => true,
            ])->id;

        $this->arId      = $akun('1120', 'Piutang Usaha', 'asset', 'debit');
        $this->advanceId = $akun('2105', 'Uang Muka Customer', 'liability', 'credit');
        $this->overpayId = $akun('2106', 'Kelebihan Bayar Customer', 'liability', 'credit');
        $this->holdId    = $akun('1202', 'Saldo Ditahan Shopee', 'asset', 'debit');
        $this->walletId  = $akun('1104', 'Saldo Penjualan Shopee', 'asset', 'debit');
        $this->feeId     = $akun('5101', 'Beban Admin Shopee', 'expense', 'debit');
        $akun('1130', 'Persediaan Barang', 'asset', 'debit');
        $akun('5001', 'Harga Pokok Penjualan', 'expense', 'debit');
        $akun('6105', 'Beban Kerugian Retur', 'expense', 'debit');
        $this->returId   = Account::where('code', '4004')->value('id');

        $this->customerId = Customer::create([
            'code' => 'CUST-MP', 'name' => 'Shopee', 'is_marketplace' => true, 'is_active' => true,
        ])->id;

        MarketplaceConfig::create([
            'customer_id'                => $this->customerId,
            'admin_fee_percent'          => 0, 'admin_fee_fixed' => 0, 'is_active' => true,
            'account_receivable_hold_id' => $this->holdId,
            'account_fee_id'             => $this->feeId,
            'account_wallet_id'          => $this->walletId,
        ]);

        $wh = Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id;

        $this->produk = Product::firstOrCreate(['sku' => 'TBKD-M3'], [
            'name' => 'Tempat Brosur M3', 'sale_type' => 'ready', 'base_unit' => 'pcs',
            'base_price' => 100000, 'is_active' => true, 'is_sellable' => true,
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

    /**
     * @param float $advanceApplied 0 = faktur belum cair; 100000 = pesanan sudah selesai
     */
    private function faktur(float $advanceApplied, float $fee = 0, ?int $customerId = null): SalesInvoice
    {
        $inv = SalesInvoice::create([
            'invoice_number'    => 'INV-1', 'sales_order_id' => $this->so->id,
            'customer_id'       => $customerId ?: $this->customerId,
            'warehouse_id'      => $this->so->warehouse_id,
            'invoice_date'      => now()->toDateString(),
            'subtotal'          => 100000, 'grand_total' => 100000,
            'advance_applied'   => $advanceApplied,
            'marketplace_fee'   => $fee,
            'fee_at_settlement' => true,
            'status'            => 'posted',
        ]);

        DB::table('sales_invoice_items')->insert([
            'sales_invoice_id' => $inv->id, 'product_id' => $this->produk->id,
            'description' => 'Tempat Brosur M3', 'item_type' => 'product',
            'qty' => 1, 'unit_price' => 100000, 'subtotal' => 100000,
            'cogs_unit' => 40000, 'cogs_total' => 40000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $inv->fresh();
    }

    private function retur(SalesInvoice $inv, array $extra = [], string $condition = 'damaged')
    {
        $itemId = DB::table('sales_invoice_items')->where('sales_invoice_id', $inv->id)->value('id');

        return app(SalesReturnService::class)->post(new SalesReturnDTO(
            customer_id: $inv->customer_id,
            items: [['invoice_item_id' => $itemId, 'qty' => 1, 'condition' => $condition]],
            date: now()->toDateString(),
            invoice_id: $inv->id,
            refund_target:      $extra['target'] ?? null,
            refund_account_id:  $extra['account'] ?? null,
            refund_customer_id: $extra['customer'] ?? null,
            refund_amount:      $extra['refund'] ?? null,
        ));
    }

    /** @return array<int,array{d:float,c:float}> */
    private function jurnal($return): array
    {
        $j = Journal::where('reference_type', 'sales_return')->where('reference_id', $return->id)->firstOrFail();
        $per = [];
        foreach (DB::table('journal_lines')->where('journal_id', $j->id)->get() as $l) {
            $id = (int) $l->account_id;
            $per[$id]['d'] = round(($per[$id]['d'] ?? 0) + (float) $l->debit, 2);
            $per[$id]['c'] = round(($per[$id]['c'] ?? 0) + (float) $l->credit, 2);
        }
        return $per;
    }

    public function test_retur_sebelum_dana_cair_menghapus_tagihan_dan_melepas_titipan(): void
    {
        $inv = $this->faktur(advanceApplied: 0);
        $svc = app(SalesReturnService::class);

        $this->assertSame('marketplace', $svc->jenisRetur($inv));
        $this->assertSame('hold', $svc->tujuanDanaBawaan($inv));

        $per = $this->jurnal($this->retur($inv));

        // Tagihan dihapus — tidak ada uang bergerak ke pembeli lewat sini.
        $this->assertEqualsWithDelta(100000, $per[$this->returId]['d'] ?? 0, 0.01);
        $this->assertEqualsWithDelta(100000, $per[$this->arId]['c'] ?? 0, 0.01);
        // Titipan pembeli dilepas balik dari saldo ditahan.
        $this->assertEqualsWithDelta(100000, $per[$this->advanceId]['d'] ?? 0, 0.01);
        $this->assertEqualsWithDelta(100000, $per[$this->holdId]['c'] ?? 0, 0.01);
        // Dompet & kredit pelanggan tidak tersentuh sama sekali.
        $this->assertArrayNotHasKey($this->walletId, $per);
        $this->assertArrayNotHasKey($this->overpayId, $per);
    }

    public function test_retur_setelah_pesanan_selesai_dipotong_dari_dompet(): void
    {
        // Pesanan selesai: faktur lunas, fee 15.850 sudah dibebankan.
        $inv = $this->faktur(advanceApplied: 100000, fee: 15850);
        $svc = app(SalesReturnService::class);

        $this->assertSame('biasa', $svc->jenisRetur($inv), 'Pesanan selesai ditangani seperti penjualan biasa.');
        $this->assertSame('wallet', $svc->tujuanDanaBawaan($inv));

        $per = $this->jurnal($this->retur($inv));

        $this->assertEqualsWithDelta(100000, $per[$this->returId]['d'] ?? 0, 0.01);
        // Bawaannya mengembalikan dana BERSIH; biaya admin yang hangus ditanggung pembeli.
        $this->assertEqualsWithDelta(84150, $per[$this->walletId]['c'] ?? 0, 0.01);
        $this->assertEqualsWithDelta(15850, $per[$this->feeId]['c'] ?? 0, 0.01);
        // Saldo ditahan sudah kosong — jangan disentuh lagi, itu yang dulu bikin minus.
        $this->assertArrayNotHasKey($this->holdId, $per);
        $this->assertArrayNotHasKey($this->arId, $per);
    }

    public function test_tukar_barang_jadi_kredit_pelanggan_lain(): void
    {
        // Kasus TBKD-13: pembeli menukar ukuran. Tidak ada uang keluar; kreditnya atas nama
        // ORANGNYA, bukan akun "Shopee" yang tertera di faktur.
        $inv    = $this->faktur(advanceApplied: 100000, fee: 15850);
        $pembeli = Customer::create(['code' => 'CUST-RINA', 'name' => 'Bu Rina', 'is_active' => true]);

        $return = $this->retur($inv, ['target' => 'credit', 'customer' => $pembeli->id], condition: 'good');
        $per    = $this->jurnal($return);

        $this->assertEqualsWithDelta(84150, $per[$this->overpayId]['c'] ?? 0, 0.01);
        $this->assertEqualsWithDelta(15850, $per[$this->feeId]['c'] ?? 0, 0.01);

        // Kreditnya benar-benar bisa dipakai, dan menempel ke pembeli — bukan ke Shopee.
        $this->assertEqualsWithDelta(84150, (float) CustomerOverpayment::where('customer_id', $pembeli->id)->sum('amount'), 0.01);
        $this->assertEqualsWithDelta(0, (float) CustomerOverpayment::where('customer_id', $this->customerId)->sum('amount'), 0.01);

        $this->assertSame('credit', $return->fresh()->refund_target);
        $this->assertEqualsWithDelta(84150, (float) $return->fresh()->refund_amount, 0.01);
    }

    public function test_pengembalian_penuh_membuat_toko_menanggung_biaya_admin(): void
    {
        $inv = $this->faktur(advanceApplied: 100000, fee: 15850);

        $per = $this->jurnal($this->retur($inv, ['refund' => 100000]));

        $this->assertEqualsWithDelta(100000, $per[$this->walletId]['c'] ?? 0, 0.01);
        // Tak ada yang dibalik: beban adminnya memang jadi tanggungan toko.
        $this->assertArrayNotHasKey($this->feeId, $per);
    }

    public function test_selisih_melebihi_biaya_admin_ditolak(): void
    {
        $inv = $this->faktur(advanceApplied: 100000, fee: 15850);

        $this->expectExceptionMessageMatches('/lebih besar daripada biaya admin/');
        $this->retur($inv, ['refund' => 50000]);
    }

    public function test_pengembalian_melebihi_dana_yang_diterima_ditolak(): void
    {
        $inv = $this->faktur(advanceApplied: 100000, fee: 15850);

        $this->expectExceptionMessageMatches('/melebihi dana yang pernah kita terima/');
        $this->retur($inv, ['refund' => 150000]);
    }

    /**
     * Dua keadaan "barang tidak kembali" yang jurnalnya berlawanan.
     *
     * Sebelum kondisi `hilang` ada, keadaan kedua tidak bisa diungkapkan sama sekali: CS
     * terpaksa memakai `tidak_kembali`, sehingga omzet yang sebenarnya batal tetap tercatat
     * sebagai penjualan dan modalnya mengendap di HPP.
     */
    public function test_paket_hilang_klaim_menang_tidak_membalik_apa_pun(): void
    {
        $inv = $this->faktur(advanceApplied: 100000, fee: 15850);

        $return = $this->retur($inv, [], condition: 'tidak_kembali');

        // Penjualannya sah & tuntas — tak ada jurnal sama sekali untuk retur ini.
        $this->assertSame(0, Journal::where('reference_type', 'sales_return')->where('reference_id', $return->id)->count());
        $this->assertEqualsWithDelta(0, (float) $return->fresh()->refund_amount, 0.01);
    }

    public function test_paket_hilang_dana_dikembalikan_jadi_kerugian_retur(): void
    {
        $inv = $this->faktur(advanceApplied: 100000, fee: 15850);
        $kerugianId = Account::where('code', '6105')->value('id');
        $hppId      = Account::where('code', '5001')->value('id');

        $per = $this->jurnal($this->retur($inv, [], condition: 'hilang'));

        // Penjualannya batal → nilai jual dibalik & uangnya dikembalikan.
        $this->assertEqualsWithDelta(100000, $per[$this->returId]['d'] ?? 0, 0.01);
        $this->assertEqualsWithDelta(84150, $per[$this->walletId]['c'] ?? 0, 0.01);
        // Modal barangnya bukan lagi HPP sebuah penjualan, melainkan kerugian.
        $this->assertEqualsWithDelta(40000, $per[$kerugianId]['d'] ?? 0, 0.01);
        $this->assertEqualsWithDelta(40000, $per[$hppId]['c'] ?? 0, 0.01);
    }

    public function test_form_retur_terbuka_dan_payload_faktur_membawa_keadaan_dana(): void
    {
        $this->faktur(advanceApplied: 0);
        $this->actingAs(\App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]));

        // Pilihan "Dari Sales Order" tak lagi ditawarkan untuk dokumen baru — dulu ia diam-diam
        // mengubah jurnal, keputusan akuntansi yang disamarkan jadi pertanyaan pemilihan dokumen.
        $html = $this->get(route('sales.returns.create'))->assertOk()->getContent();
        $this->assertStringContainsString('Penanganan Dana', $html);
        $this->assertStringNotContainsString('<option value="so">', $html);

        // Form menyimpulkan jenis retur dari payload ini — tanpa field-nya ia cuma menebak.
        $payload = $this->getJson(route('sales.ajax.returns.invoices', ['customer_id' => $this->customerId]))
            ->assertOk()->json();

        $this->assertSame('marketplace', $payload[0]['jenis']);
        $this->assertSame('hold', $payload[0]['tujuan_bawaan']);
        $this->assertEqualsWithDelta(100000, $payload[0]['sisa_tagihan'], 0.01);
    }

    public function test_retur_pelanggan_biasa_yang_belum_bayar_hanya_menghapus_tagihan(): void
    {
        $toko = Customer::create(['code' => 'CUST-TOKO', 'name' => 'Toko Sebelah', 'is_active' => true]);
        $inv  = $this->faktur(advanceApplied: 0, customerId: $toko->id);
        $inv->forceFill(['fee_at_settlement' => false])->save();

        $per = $this->jurnal($this->retur($inv->fresh()));

        $this->assertEqualsWithDelta(100000, $per[$this->arId]['c'] ?? 0, 0.01);
        // Belum pernah membayar → tidak ada kredit yang lahir.
        $this->assertArrayNotHasKey($this->overpayId, $per);
        $this->assertEqualsWithDelta(0, (float) CustomerOverpayment::where('customer_id', $toko->id)->sum('amount'), 0.01);
    }
}
