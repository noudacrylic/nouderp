<?php

namespace Tests\Feature\Sales;

use App\Core\Accounting\Account;
use App\Core\Inventory\FifoService;
use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Core\Period\AccountingPeriod;
use App\DTO\SalesReturnDTO;
use App\Models\Customer;
use App\Models\MarketplaceConfig;
use App\Models\SalesInvoice;
use App\Modules\Marketplace\Jubelio\Services\JubelioOrderSyncService;
use App\Modules\Sales\Models\SalesAdvance;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Sales\Services\MarketplaceEngineService;
use App\Modules\Sales\Services\SalesDeliveryService;
use App\Modules\Sales\Services\SalesReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Siklus penuh satu pesanan marketplace, dari uang masuk sampai buku tutup.
 *
 * Tes-tes lain menguji potongannya masing-masing: faktur terbit saat pengiriman, pelunasan saat
 * pesanan selesai, tujuan dana retur. Yang TIDAK terjaga oleh tes-tes itu adalah apakah semua
 * potongan itu masih tersambung: tiap potongan bisa saja benar sendiri-sendiri sementara ada
 * saldo yang tak pernah ditutup siapa pun. Justru begitulah kebocoran sebelumnya lahir — saldo
 * ditahan dan uang muka menggantung bertahun-tahun tanpa ada satu pun tes yang merah.
 *
 * Ukurannya karena itu bukan "jurnalnya benar", melainkan: SETELAH PESANAN TUNTAS, SEMUA AKUN
 * PERANTARA HARUS NOL. Uang Muka nol, Saldo Ditahan nol, Piutang nol. Yang tersisa hanya yang
 * memang harus tersisa: dompet, beban admin, pendapatan, HPP, persediaan.
 */
class MarketplaceSiklusPenuhTest extends TestCase
{
    use RefreshDatabase;

    private const HARGA = 100000.0;
    private const MODAL = 40000.0;
    private const FEE   = 15850.0;

    private array $akun = [];
    private int $customerId;
    private int $warehouseId;
    private Product $produk;

    protected function setUp(): void
    {
        parent::setUp();

        AccountingPeriod::firstOrCreate(
            ['year' => (int) now()->year, 'month' => (int) now()->month],
            ['start_date' => now()->startOfMonth()->toDateString(),
             'end_date'   => now()->endOfMonth()->toDateString(), 'status' => 'open']
        );

        foreach ([
            ['1120', 'Piutang Usaha', 'asset', 'debit'],
            ['1130', 'Persediaan Barang', 'asset', 'debit'],
            ['1203', 'Titipan Ongkir', 'asset', 'debit'],
            ['2105', 'Uang Muka Customer', 'liability', 'credit'],
            ['2106', 'Kelebihan Bayar Customer', 'liability', 'credit'],
            ['1202', 'Saldo Ditahan Shopee', 'asset', 'debit'],
            ['1104', 'Saldo Penjualan Shopee', 'asset', 'debit'],
            ['5101', 'Beban Admin Shopee', 'expense', 'debit'],
            ['4001', 'Penjualan Produk', 'revenue', 'credit'],
            ['4002', 'Pendapatan Tambahan', 'revenue', 'credit'],
            ['4003', 'Diskon Penjualan', 'revenue', 'debit'],
            ['2103', 'PPN Keluaran', 'liability', 'credit'],
            ['1109', 'PPh 23 Dibayar Dimuka', 'asset', 'debit'],
            ['5001', 'Harga Pokok Penjualan', 'expense', 'debit'],
            ['6105', 'Beban Kerugian Retur', 'expense', 'debit'],
        ] as [$code, $name, $type, $normal]) {
            $this->akun[$code] = Account::firstOrCreate(['code' => $code], [
                'name' => $name, 'type' => $type, 'normal_balance' => $normal, 'is_active' => true,
            ])->id;
        }
        $this->akun['4004'] = Account::where('code', '4004')->value('id'); // dari migrasi

        $this->customerId = Customer::create([
            'code' => 'CUST-MP', 'name' => 'Shopee', 'is_marketplace' => true, 'is_active' => true,
        ])->id;

        MarketplaceConfig::create([
            'customer_id'                => $this->customerId,
            'admin_fee_percent'          => 0, 'admin_fee_fixed' => 0, 'is_active' => true,
            'account_receivable_hold_id' => $this->akun['1202'],
            'account_fee_id'             => $this->akun['5101'],
            'account_wallet_id'          => $this->akun['1104'],
        ]);

        $this->warehouseId = Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id;

        $this->produk = Product::firstOrCreate(['sku' => 'TBKD-M3'], [
            'name' => 'Tempat Brosur M3', 'sale_type' => 'ready', 'base_unit' => 'pcs',
            'base_price' => self::HARGA, 'is_active' => true, 'is_sellable' => true,
        ]);

        // Stok tersedia beserta harga pokoknya — tanpa ini Surat Jalan tak punya HPP.
        app(FifoService::class)->stockIn(
            $this->produk->id, $this->warehouseId, 'purchase', 'AWAL', 5, self::MODAL
        );
    }

    /** Pesanan dibayar di channel: Dr Saldo Ditahan (kotor) / Cr Uang Muka. */
    private function pesananDibayar(): SalesOrder
    {
        $so = SalesOrder::create([
            'order_number' => 'SO-MP-1', 'customer_id' => $this->customerId,
            'warehouse_id' => $this->warehouseId, 'order_date' => now()->toDateString(),
            'status' => 'confirmed', 'subtotal' => self::HARGA,
            'grand_total' => self::HARGA, 'paid_amount' => self::HARGA,
        ]);

        SalesOrderItem::create([
            'sales_order_id' => $so->id, 'product_id' => $this->produk->id,
            'description' => $this->produk->name, 'item_type' => 'product',
            'qty' => 1, 'unit_price' => self::HARGA, 'net_unit_price' => self::HARGA,
            'discount_type' => 'nominal', 'line_discount' => 0,
            'line_subtotal' => self::HARGA, 'line_total' => self::HARGA,
        ]);

        SalesAdvance::create([
            'advance_number' => 'ADV-1', 'sales_order_id' => $so->id,
            'customer_id' => $this->customerId, 'bank_account_id' => $this->akun['1202'],
            'advance_date' => now()->toDateString(), 'amount' => self::HARGA, 'status' => 'posted',
        ]);

        // DP marketplace masuk akun Hold, bukan kas — uangnya belum jadi milik kita.
        $this->jurnalManual('advance_dp', $so->id, [
            [$this->akun['1202'], self::HARGA, 0],
            [$this->akun['2105'], 0, self::HARGA],
        ]);

        return $so;
    }

    private function jurnalManual(string $refType, int $refId, array $lines): void
    {
        app(\App\Core\Journal\JournalPostingService::class)->post(
            new \App\DTO\JournalEntryDTO(
                date: now()->toDateString(),
                reference_type: $refType,
                reference_id: $refId,
                description: 'uji ' . $refType,
                lines: array_map(
                    fn ($l) => new \App\DTO\JournalLineDTO($l[0], $l[1], $l[2]),
                    $lines
                ),
            )
        );
    }

    /** Barang keluar gudang → Surat Jalan posted → faktur terbit mengikutinya. */
    private function kirim(SalesOrder $so): SalesInvoice
    {
        // Ambil ulang dari DB: instance hasil create() sudah memegang relasi `items` dalam
        // keadaan KOSONG (observer menyentuhnya saat SO dibuat), dan `loadMissing()` di dalam
        // createFromOrder mempertahankan yang kosong itu — SJ-nya jadi tak pernah terbentuk.
        $so = SalesOrder::with('items.product')->findOrFail($so->id);

        $svc = app(SalesDeliveryService::class);
        $delivery = $svc->createFromOrder($so, 'kurir');
        $this->assertNotNull($delivery, 'Surat Jalan gagal dibuat — stok tidak cukup?');
        $svc->post($delivery->id);

        $invoice = app(JubelioOrderSyncService::class)->terbitkanFakturPengiriman($so->fresh(), 0);
        $this->assertNotNull($invoice, 'Faktur pengiriman gagal terbit.');

        return $invoice;
    }

    /** Saldo akhir sebuah akun: debit − kredit dari SEMUA jurnal non-void. */
    private function saldo(string $code): float
    {
        return round((float) DB::table('journal_lines as jl')
            ->join('journals as j', 'j.id', '=', 'jl.journal_id')
            ->where('j.status', '!=', 'void')
            ->where('jl.account_id', $this->akun[$code])
            ->selectRaw('SUM(jl.debit) - SUM(jl.credit) v')->value('v'), 2);
    }

    private function assertPerantaraNol(string $konteks): void
    {
        foreach (['2105' => 'Uang Muka', '1202' => 'Saldo Ditahan', '1120' => 'Piutang'] as $code => $nama) {
            $this->assertEqualsWithDelta(
                0, $this->saldo($code), 0.01,
                "$konteks: $nama ($code) harus nol setelah pesanan tuntas, sisa " . $this->saldo($code)
            );
        }
    }

    public function test_pesanan_selesai_normal_menutup_semua_akun_perantara(): void
    {
        $so      = $this->pesananDibayar();
        $invoice = $this->kirim($so);

        // Saat barang keluar: omzet & HPP diakui, tapi dananya BELUM cair.
        $this->assertSame('belum_cair', $invoice->fresh()->paymentState()['key']);
        $this->assertEqualsWithDelta(self::HARGA, $this->saldo('1120'), 0.01, 'Piutang harus terbuka saat kirim');
        $this->assertEqualsWithDelta(-self::HARGA, $this->saldo('4001'), 0.01);
        $this->assertEqualsWithDelta(self::MODAL, $this->saldo('5001'), 0.01);

        // Pesanan selesai di marketplace.
        app(MarketplaceEngineService::class)->handle($invoice->fresh(), self::FEE);

        $this->assertSame('lunas', $invoice->fresh()->paymentState()['key']);
        $this->assertPerantaraNol('Pesanan selesai normal');

        // Yang tersisa memang harus tersisa.
        $this->assertEqualsWithDelta(self::HARGA - self::FEE, $this->saldo('1104'), 0.01, 'Dompet = dana bersih');
        $this->assertEqualsWithDelta(self::FEE, $this->saldo('5101'), 0.01);
        $this->assertEqualsWithDelta(-self::HARGA, $this->saldo('4001'), 0.01);
        $this->assertEqualsWithDelta(self::MODAL, $this->saldo('5001'), 0.01);
        $this->assertEqualsWithDelta(-self::MODAL, $this->saldo('1130'), 0.01, 'Persediaan berkurang sebesar modal');
    }

    public function test_retur_sebelum_dana_cair_menutup_semua_akun_perantara(): void
    {
        $so      = $this->pesananDibayar();
        $invoice = $this->kirim($so);

        $itemId = DB::table('sales_invoice_items')->where('sales_invoice_id', $invoice->id)->value('id');
        app(SalesReturnService::class)->post(new SalesReturnDTO(
            customer_id: $this->customerId,
            items: [['invoice_item_id' => $itemId, 'qty' => 1, 'condition' => 'good']],
            date: now()->toDateString(),
            invoice_id: $invoice->id,
        ));

        $this->assertPerantaraNol('Retur sebelum dana cair');

        // Barang kembali utuh → persediaan pulih, omzet & HPP saling meniadakan.
        $this->assertEqualsWithDelta(0, $this->saldo('1130'), 0.01, 'Persediaan harus pulih');
        $this->assertEqualsWithDelta(0, $this->saldo('5001'), 0.01, 'HPP harus terbalik penuh');
        $this->assertEqualsWithDelta(-self::HARGA, $this->saldo('4001'), 0.01);
        $this->assertEqualsWithDelta(self::HARGA, $this->saldo('4004'), 0.01, 'Omzet dibatalkan lewat 4004');
        // Tidak ada uang yang pernah cair, jadi dompet tak tersentuh sama sekali.
        $this->assertEqualsWithDelta(0, $this->saldo('1104'), 0.01);
        $this->assertEqualsWithDelta(0, $this->saldo('5101'), 0.01);
    }

    public function test_retur_setelah_selesai_menutup_semua_akun_perantara(): void
    {
        $so      = $this->pesananDibayar();
        $invoice = $this->kirim($so);

        app(MarketplaceEngineService::class)->handle($invoice->fresh(), self::FEE);

        $itemId = DB::table('sales_invoice_items')->where('sales_invoice_id', $invoice->id)->value('id');
        app(SalesReturnService::class)->post(new SalesReturnDTO(
            customer_id: $this->customerId,
            items: [['invoice_item_id' => $itemId, 'qty' => 1, 'condition' => 'good']],
            date: now()->toDateString(),
            invoice_id: $invoice->id,
        ));

        $this->assertPerantaraNol('Retur setelah pesanan selesai');

        // Dana bersih dikembalikan dari dompet; biaya admin yang hangus ditanggung pembeli,
        // jadi beban adminnya terbalik penuh dan toko tidak menanggung apa pun.
        $this->assertEqualsWithDelta(0, $this->saldo('1104'), 0.01, 'Dompet harus kembali nol');
        $this->assertEqualsWithDelta(0, $this->saldo('5101'), 0.01, 'Beban admin ditanggung pembeli');
        $this->assertEqualsWithDelta(0, $this->saldo('1130'), 0.01);
        $this->assertEqualsWithDelta(0, $this->saldo('5001'), 0.01);
    }

    public function test_paket_hilang_dana_dikembalikan_menyisakan_kerugian_sebesar_modal(): void
    {
        $so      = $this->pesananDibayar();
        $invoice = $this->kirim($so);

        app(MarketplaceEngineService::class)->handle($invoice->fresh(), self::FEE);

        $itemId = DB::table('sales_invoice_items')->where('sales_invoice_id', $invoice->id)->value('id');
        app(SalesReturnService::class)->post(new SalesReturnDTO(
            customer_id: $this->customerId,
            items: [['invoice_item_id' => $itemId, 'qty' => 1, 'condition' => 'hilang']],
            date: now()->toDateString(),
            invoice_id: $invoice->id,
        ));

        $this->assertPerantaraNol('Paket hilang, dana dikembalikan');

        // Barangnya tak pernah kembali: persediaan tetap berkurang, dan modalnya berpindah
        // dari HPP ke Kerugian Retur karena penjualannya batal.
        $this->assertEqualsWithDelta(-self::MODAL, $this->saldo('1130'), 0.01, 'Persediaan tidak pulih');
        $this->assertEqualsWithDelta(0, $this->saldo('5001'), 0.01, 'HPP kosong — penjualannya batal');
        $this->assertEqualsWithDelta(self::MODAL, $this->saldo('6105'), 0.01, 'Modalnya jadi kerugian');
    }

    public function test_paket_hilang_klaim_menang_tetap_jadi_penjualan_sah(): void
    {
        $so      = $this->pesananDibayar();
        $invoice = $this->kirim($so);

        app(MarketplaceEngineService::class)->handle($invoice->fresh(), self::FEE);

        $itemId = DB::table('sales_invoice_items')->where('sales_invoice_id', $invoice->id)->value('id');
        app(SalesReturnService::class)->post(new SalesReturnDTO(
            customer_id: $this->customerId,
            items: [['invoice_item_id' => $itemId, 'qty' => 1, 'condition' => 'tidak_kembali']],
            date: now()->toDateString(),
            invoice_id: $invoice->id,
        ));

        $this->assertPerantaraNol('Paket hilang, klaim menang');

        // Uangnya memang kita terima — omzet, HPP, dompet & beban admin dibiarkan apa adanya.
        $this->assertEqualsWithDelta(-self::HARGA, $this->saldo('4001'), 0.01);
        $this->assertEqualsWithDelta(0, $this->saldo('4004'), 0.01, 'Tidak ada omzet yang dibatalkan');
        $this->assertEqualsWithDelta(self::MODAL, $this->saldo('5001'), 0.01);
        $this->assertEqualsWithDelta(0, $this->saldo('6105'), 0.01);
        $this->assertEqualsWithDelta(self::HARGA - self::FEE, $this->saldo('1104'), 0.01);
    }
}
