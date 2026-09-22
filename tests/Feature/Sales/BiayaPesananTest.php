<?php

namespace Tests\Feature\Sales;

use App\Core\Accounting\Account;
use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Core\Journal\Journal;
use App\Core\Period\AccountingPeriod;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Modules\Finance\Models\CashDisbursement;
use App\Modules\Finance\Models\CashDisbursementLine;
use App\Modules\Finance\Services\CashDisbursementService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Services\InvoicePostingService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Biaya Pesanan: tukang pasang / jasa antar dari luar yang dibayar untuk SATU pesanan.
 *
 * Uangnya ditahan di 1204 sampai pesanannya difakturkan, lalu pindah ke beban (5008 HPP
 * Jasa) bertanggal faktur — supaya biaya & pendapatannya jatuh di bulan yang sama. Yang
 * dijaga di sini: saldo 1204 selalu kembali NOL di setiap akhir cerita yang sah, dan
 * tidak ada biaya yang dibebankan dua kali.
 */
class BiayaPesananTest extends TestCase
{
    use RefreshDatabase;

    private int $kasId;
    private int $tangguhId;
    private int $hppJasaId;
    private int $customerId;
    private int $warehouseId;
    private SalesOrder $so;

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

        // Migrasi biaya pesanan sudah membuat 1204 & 5008 — firstOrCreate cukup mengambilnya.
        $this->tangguhId = $akun('1204', 'Biaya Pesanan Ditangguhkan', 'asset', 'debit');
        $this->hppJasaId = $akun('5008', 'HPP Jasa Pihak Ketiga', 'expense', 'debit');
        $this->kasId     = $akun('1101', 'Kas', 'asset', 'debit');
        foreach ([
            ['1120', 'Piutang Usaha', 'asset', 'debit'],
            ['4001', 'Penjualan Produk', 'revenue', 'credit'],
            ['4003', 'Diskon Penjualan', 'revenue', 'debit'],
            ['4010', 'Pendapatan Jasa', 'revenue', 'credit'],
            ['5001', 'Harga Pokok Penjualan', 'expense', 'debit'],
            ['1130', 'Persediaan', 'asset', 'debit'],
            ['2105', 'Uang Muka Customer', 'liability', 'credit'],
            ['1203', 'Titipan Pengiriman', 'asset', 'debit'],
        ] as $a) {
            $akun(...$a);
        }

        $this->customerId  = Customer::create(['code' => 'C-PSG', 'name' => 'Pak Pasang', 'is_active' => true])->id;
        $this->warehouseId = Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id;

        $this->so = SalesOrder::create([
            'order_number' => 'SO-PSG-1', 'customer_id' => $this->customerId,
            'warehouse_id' => $this->warehouseId, 'order_date' => now()->toDateString(),
            'status' => 'confirmed', 'subtotal' => 1000000, 'grand_total' => 1000000,
        ]);
    }

    private function bayarTukang(float $nominal, bool $post = true, ?int $soId = null, ?int $akunId = null): CashDisbursement
    {
        $svc = app(CashDisbursementService::class);
        $cd  = $svc->createDraft([
            'date' => now()->toDateString(), 'type' => 'general', 'cash_account_id' => $this->kasId,
            'lines' => [[
                'account_id'     => $akunId ?? $this->hppJasaId,
                'amount'         => $nominal,
                'description'    => 'Tukang pasang',
                'sales_order_id' => $soId ?? $this->so->id,
            ]],
        ]);

        return $post ? $svc->post($cd) : $cd;
    }

    /** Faktur jasa saja — tidak butuh Surat Jalan/stok, jadi bisa di-post utuh. */
    private function fakturPosted(string $nomor = 'INV-PSG-1'): SalesInvoice
    {
        $jasa = Product::firstOrCreate(['sku' => 'JASA-PSG'], [
            'name' => 'Jasa Pasang', 'is_active' => 1, 'is_sellable' => 1, 'sale_type' => 'service',
        ]);

        $inv = SalesInvoice::create([
            'invoice_number' => $nomor, 'sales_order_id' => $this->so->id,
            'customer_id' => $this->customerId, 'warehouse_id' => $this->warehouseId,
            'invoice_date' => now()->toDateString(), 'subtotal' => 1000000, 'grand_total' => 1000000,
            'status' => 'draft',
        ]);
        SalesInvoiceItem::create([
            'sales_invoice_id' => $inv->id, 'product_id' => $jasa->id, 'description' => 'Jasa Pasang',
            'qty' => 1, 'unit_price' => 1000000, 'subtotal' => 1000000,
        ]);

        app(InvoicePostingService::class)->post($inv->fresh());

        return $inv->fresh();
    }

    /** Saldo akun dari jurnal yang tidak void. */
    private function saldo(int $accountId): float
    {
        return round((float) DB::table('journal_lines')
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journals.status', '!=', 'void')
            ->where('journal_lines.account_id', $accountId)
            ->sum(DB::raw('journal_lines.debit - journal_lines.credit')), 2);
    }

    public function test_dibayar_sebelum_faktur_ditahan_lalu_jadi_hpp_saat_faktur_diposting(): void
    {
        $this->bayarTukang(350000);

        $this->assertEqualsWithDelta(350000, $this->saldo($this->tangguhId), 0.01);
        $this->assertEqualsWithDelta(0, $this->saldo($this->hppJasaId), 0.01, 'belum boleh jadi beban sebelum difakturkan');
        $this->assertEqualsWithDelta(-350000, $this->saldo($this->kasId), 0.01);

        $inv = $this->fakturPosted();

        $this->assertEqualsWithDelta(0, $this->saldo($this->tangguhId), 0.01);
        $this->assertEqualsWithDelta(350000, $this->saldo($this->hppJasaId), 0.01);

        $line = CashDisbursementLine::where('sales_order_id', $this->so->id)->sole();
        $this->assertSame($inv->id, (int) $line->cost_invoice_id);
        $this->assertSame(
            $inv->invoice_date->toDateString(),
            \Carbon\Carbon::parse(Journal::find($line->cost_recognition_journal_id)->date)->toDateString()
        );
    }

    public function test_dibayar_setelah_faktur_langsung_jadi_hpp(): void
    {
        $inv = $this->fakturPosted();

        $this->bayarTukang(200000);

        $this->assertEqualsWithDelta(0, $this->saldo($this->tangguhId), 0.01);
        $this->assertEqualsWithDelta(200000, $this->saldo($this->hppJasaId), 0.01);
        $this->assertSame($inv->id, (int) CashDisbursementLine::where('sales_order_id', $this->so->id)->value('cost_invoice_id'));
    }

    public function test_void_faktur_mengembalikan_biaya_ke_tangguhan_dan_posting_ulang_mengakui_lagi(): void
    {
        $this->bayarTukang(350000);
        $inv = $this->fakturPosted();

        app(SalesInvoiceService::class)->voidPosted($inv);

        $this->assertEqualsWithDelta(350000, $this->saldo($this->tangguhId), 0.01);
        $this->assertEqualsWithDelta(0, $this->saldo($this->hppJasaId), 0.01);
        $this->assertNull(CashDisbursementLine::where('sales_order_id', $this->so->id)->value('cost_invoice_id'));

        // Faktur pengganti mengambil biaya yang sama — sekali saja, bukan dua kali.
        $this->fakturPosted('INV-PSG-2');

        $this->assertEqualsWithDelta(0, $this->saldo($this->tangguhId), 0.01);
        $this->assertEqualsWithDelta(350000, $this->saldo($this->hppJasaId), 0.01);
    }

    public function test_void_pengeluaran_membatalkan_kas_dan_pengakuan_beban(): void
    {
        $this->fakturPosted();
        $cd = $this->bayarTukang(200000);

        app(CashDisbursementService::class)->void($cd->fresh());

        $this->assertEqualsWithDelta(0, $this->saldo($this->tangguhId), 0.01);
        $this->assertEqualsWithDelta(0, $this->saldo($this->hppJasaId), 0.01);
        $this->assertEqualsWithDelta(0, $this->saldo($this->kasId), 0.01);
    }

    public function test_hanya_so_confirmed_dan_akun_beban_yang_diterima(): void
    {
        $voidSo = SalesOrder::create([
            'order_number' => 'SO-VOID', 'customer_id' => $this->customerId,
            'warehouse_id' => $this->warehouseId, 'order_date' => now()->toDateString(),
            'status' => 'void', 'subtotal' => 0, 'grand_total' => 0,
        ]);

        try {
            $this->bayarTukang(100000, false, $voidSo->id);
            $this->fail('SO void tidak boleh menerima biaya pesanan.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('SO confirmed', $e->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('akun beban');
        $this->bayarTukang(100000, false, null, $this->kasId);
    }

    public function test_so_yang_dibatalkan_setelah_draft_ditolak_saat_posting(): void
    {
        $cd = $this->bayarTukang(100000, false);
        $this->so->update(['status' => 'void']);

        $this->expectException(DomainException::class);
        app(CashDisbursementService::class)->post($cd->fresh());
    }

    public function test_so_dengan_biaya_pesanan_tidak_bisa_divoid(): void
    {
        $this->bayarTukang(100000);

        $this->actingAs(\App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->from(route('sales.orders.show', $this->so->id))
            ->post(route('sales.orders.void', $this->so->id))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Biaya Pesanan'));

        $this->assertSame('confirmed', $this->so->fresh()->status);
    }

    public function test_kartu_biaya_pesanan_tampil_di_halaman_so(): void
    {
        $this->bayarTukang(350000);

        $html = $this->actingAs(\App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->get(route('sales.orders.show', $this->so->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Biaya Pesanan', $html);
        $this->assertStringContainsString('Menunggu faktur', $html);
        $this->assertStringContainsString('Rp 350.000', $html);
    }

    public function test_form_pengeluaran_dari_tombol_so_sudah_terisi_pesanannya(): void
    {
        $html = $this->actingAs(\App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->get(route('finance.cash-bank.disbursements.create', ['type' => 'general', 'sales_order_id' => $this->so->id]))
            ->assertOk()
            ->getContent();

        // Baris siap-isi dikirim ke JS lewat json_encode (tanda pisah jadi —).
        $this->assertStringContainsString('"sales_order_id":' . $this->so->id, $html);
        $this->assertStringContainsString('SO-PSG-1', $html);

        $this->getJson(route('finance.cash-bank.disbursements.order-search', ['q' => 'Pasang']))
            ->assertOk()
            ->assertJsonPath('0.id', $this->so->id);
    }
}
