<?php

namespace Tests\Feature\Sales;

use App\Core\Accounting\Account;
use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\DTO\SalesReturnDTO;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\User;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jurnal DANA retur yang ditulis tangan.
 *
 * Retur yang diajukan konsumen hasilnya bermacam-macam — ganti penuh, sebagian, atau tak
 * sama sekali — dan tak semuanya bisa disimpulkan dari kondisi barang. Yang dijaga di sini
 * bukan selera pemakainya, melainkan syarat yang membuat sebuah jurnal bisa dibaca sama
 * sekali: akunnya nyata, tiap baris berdiri di satu sisi, dan debit bertemu kredit.
 */
class SalesReturnJurnalManualTest extends TestCase
{
    use RefreshDatabase;

    private SalesInvoice $invoice;
    private int $itemId;

    protected function setUp(): void
    {
        parent::setUp();

        \App\Core\Period\AccountingPeriod::firstOrCreate(
            ['year' => (int) now()->year, 'month' => (int) now()->month],
            ['start_date' => now()->startOfMonth()->toDateString(),
             'end_date'   => now()->endOfMonth()->toDateString(), 'status' => 'open']
        );

        // Bagan akun seadanya — jalur otomatis butuh akun-akun ini benar-benar ada.
        $this->akun('1120');
        $this->akun('1130');
        $this->akun('2106');
        $this->akun('4004');
        $this->akun('5001');
        $this->akun('6105');

        $cust = Customer::create([
            'code' => 'CUST-JM', 'name' => 'Pembeli Uji', 'is_active' => true,
        ]);
        $warehouseId = Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id;
        $produk = Product::create(['sku' => 'SKU-JM', 'name' => 'Akrilik A4', 'sale_type' => 'ready']);

        $this->invoice = SalesInvoice::create([
            'invoice_number' => 'INV-JM-1',
            'customer_id'    => $cust->id,
            'warehouse_id'   => $warehouseId,
            'invoice_date'   => now()->toDateString(),
            'subtotal'       => 100000,
            'grand_total'    => 100000,
            'status'         => 'posted',
        ]);

        $this->itemId = SalesInvoiceItem::create([
            'sales_invoice_id' => $this->invoice->id,
            'product_id'       => $produk->id,
            'description'      => 'Akrilik A4',
            'qty'              => 2,
            'unit_price'       => 50000,
            'subtotal'         => 100000,
        ])->id;
    }

    private function akun(string $code): int
    {
        return Account::firstOrCreate(
            ['code' => $code],
            ['name' => 'Akun ' . $code, 'type' => 'asset', 'normal_balance' => 'debit', 'is_active' => true]
        )->id;
    }

    private function dto(?array $journal): SalesReturnDTO
    {
        return new SalesReturnDTO(
            customer_id: $this->invoice->customer_id,
            items: [['invoice_item_id' => $this->itemId, 'qty' => 1, 'condition' => 'good']],
            date: now()->toDateString(),
            invoice_id: $this->invoice->id,
            return_type: 'diajukan_konsumen',
            journal_override: $journal,
        );
    }

    /** Baris manual yang seimbang menggantikan blok dana, dan tersimpan apa adanya. */
    public function test_jurnal_manual_seimbang_dipakai_menggantikan_blok_dana(): void
    {
        $retur = app(SalesReturnService::class)->post($this->dto([
            ['account_id' => $this->akun('4004'), 'debit' => 30000, 'credit' => 0, 'memo' => 'Ganti sebagian'],
            ['account_id' => $this->akun('1120'), 'debit' => 0, 'credit' => 30000],
        ]));

        $this->assertSame('posted', $retur->status);
        $this->assertCount(2, $retur->journal_override);

        $jurnal = \App\Core\Journal\Journal::where('reference_type', 'sales_return')
            ->where('reference_id', $retur->id)->with('lines')->first();

        $this->assertNotNull($jurnal, 'jurnal retur harus terbentuk');
        $this->assertEqualsWithDelta(30000.0, (float) $jurnal->lines->where('account_id', $this->akun('4004'))->sum('debit'), 0.01);
        $this->assertEqualsWithDelta(30000.0, (float) $jurnal->lines->where('account_id', $this->akun('1120'))->sum('credit'), 0.01);

        $this->assertEqualsWithDelta(
            (float) $jurnal->lines->sum('debit'), (float) $jurnal->lines->sum('credit'), 0.01,
            'jurnal akhir tetap seimbang'
        );

        /*
         * Blok BARANG tidak muncul di sini karena faktur uji ini tak punya surat jalan
         * maupun lapisan FIFO, jadi modalnya nol — bukan karena baris manual menelannya.
         * Baris manual sengaja hanya menggantikan blok DANA (lihat post()), dan jurnal
         * barang dengan stok sungguhan sudah dikunci MarketplaceSiklusPenuhTest.
         */
        $this->assertTrue(
            $jurnal->lines->every(fn ($l) => in_array((int) $l->account_id, [$this->akun('4004'), $this->akun('1120')], true)),
            'blok dana bawaan tidak boleh ikut terbit di samping baris manual'
        );
    }

    /** Jurnal timpang ditolak — dan returnya tidak ikut tersimpan. */
    public function test_jurnal_timpang_ditolak(): void
    {
        $this->expectExceptionMessageMatches('/belum seimbang/i');

        app(SalesReturnService::class)->post($this->dto([
            ['account_id' => $this->akun('4004'), 'debit' => 30000, 'credit' => 0],
            ['account_id' => $this->akun('1120'), 'debit' => 0, 'credit' => 25000],
        ]));
    }

    public function test_satu_baris_tidak_boleh_debit_sekaligus_kredit(): void
    {
        $this->expectExceptionMessageMatches('/debit ATAU kredit/i');

        app(SalesReturnService::class)->post($this->dto([
            ['account_id' => $this->akun('4004'), 'debit' => 30000, 'credit' => 30000],
            ['account_id' => $this->akun('1120'), 'debit' => 0, 'credit' => 30000],
        ]));
    }

    public function test_akun_nonaktif_ditolak(): void
    {
        $mati = Account::create([
            'code' => '9999', 'name' => 'Akun Mati', 'type' => 'asset',
            'normal_balance' => 'debit', 'is_active' => false,
        ]);

        $this->expectExceptionMessageMatches('/tidak dikenal atau sudah nonaktif/i');

        app(SalesReturnService::class)->post($this->dto([
            ['account_id' => $mati->id, 'debit' => 30000, 'credit' => 0],
            ['account_id' => $this->akun('1120'), 'debit' => 0, 'credit' => 30000],
        ]));
    }

    /** Tanpa baris manual, jalur otomatis tetap seperti sebelumnya. */
    public function test_tanpa_baris_manual_tetap_pakai_hitungan_sistem(): void
    {
        $retur = app(SalesReturnService::class)->post($this->dto(null));

        $this->assertNull($retur->journal_override);
        $this->assertSame('posted', $retur->status);
    }

    /** Baris kosong dari form tidak boleh diam-diam mematikan jurnal otomatis. */
    public function test_baris_kosong_dianggap_tidak_ada(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($admin)->post(route('sales.returns.store'), [
            'status'      => 'draft',
            'invoice_id'  => $this->invoice->id,
            'customer_id' => $this->invoice->customer_id,
            'return_date' => now()->toDateString(),
            'items'       => [['invoice_item_id' => $this->itemId, 'qty' => 1, 'condition' => 'good']],
            'return_type' => 'diajukan_konsumen',
            'journal'     => [
                ['account_id' => '', 'debit' => '', 'credit' => ''],
                ['account_id' => '', 'debit' => '', 'credit' => ''],
            ],
        ])->assertRedirect();

        $this->assertNull(SalesReturn::latest('id')->first()->journal_override);
    }

    /** Draft boleh menyimpan jurnal yang belum seimbang — yang menuntut seimbang hanya posting. */
    public function test_draft_boleh_menyimpan_jurnal_belum_seimbang(): void
    {
        $retur = app(SalesReturnService::class)->saveDraft($this->dto([
            ['account_id' => $this->akun('4004'), 'debit' => 30000, 'credit' => 0],
        ]));

        $this->assertSame('draft', $retur->status);
        $this->assertCount(1, $retur->fresh()->journal_override);
    }
}
