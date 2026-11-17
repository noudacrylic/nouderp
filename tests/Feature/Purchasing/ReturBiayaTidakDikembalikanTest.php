<?php

namespace Tests\Feature\Purchasing;

use App\Core\Accounting\Account;
use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Core\Journal\JournalLine;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Services\PurchaseReturnService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retur faktur dengan biaya yang tidak dikembalikan pemasok (kasus Shopee: biaya layanan
 * hanya dikembalikan sebagian). Persediaan tetap keluar sebesar HPP; tagihan ke pemasok
 * berkurang sebesar biaya, dan biaya itu dibebankan ke akun pilihan.
 */
class ReturBiayaTidakDikembalikanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Supplier $supplier;
    private Warehouse $gudang;
    private Product $produk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChartOfAccountSeeder::class);
        $this->admin    = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->supplier = Supplier::create(['code' => 'SUP-1', 'name' => 'Shopee', 'is_active' => true]);
        $this->gudang   = Warehouse::create(['name' => 'Utama', 'is_active' => true]);
        $this->produk   = Product::create([
            'sku' => 'KNC-1', 'name' => 'Kunci Huben', 'sale_type' => 'ready',
            'base_unit' => 'pcs', 'base_price' => 70000, 'is_active' => true,
        ]);
    }

    private function fakturPosted(): PurchaseInvoice
    {
        $this->actingAs($this->admin)->post(route('purchasing.invoices.store'), [
            'supplier_id'  => $this->supplier->id,
            'warehouse_id' => $this->gudang->id,
            'invoice_date' => now()->toDateString(),
            'items'        => [['product_id' => $this->produk->id, 'qty' => 10, 'price' => 60000, 'unit' => 'pcs']],
            '_after_save'  => 'post',
        ])->assertSessionHasNoErrors();

        return PurchaseInvoice::with('items')->latest('id')->firstOrFail();
    }

    private function saldo(string $code, $journalId): float
    {
        return (float) JournalLine::where('journal_id', $journalId)
            ->whereHas('account', fn ($q) => $q->where('code', $code))
            ->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) AS s')->value('s');
    }

    public function test_biaya_mengurangi_tagihan_dan_dibebankan(): void
    {
        $pi    = $this->fakturPosted();
        $beban = Account::where('code', '5103')->value('id');
        $svc   = app(PurchaseReturnService::class);

        $draft = $svc->createDraft([
            'purchase_invoice_id' => $pi->id,
            'return_date'         => now()->toDateString(),
            'items'               => [['purchase_invoice_item_id' => $pi->items->first()->id, 'qty' => 3]],
            'fee_withheld'        => '270',
            'fee_account_id'      => $beban,
        ]);
        $this->assertEqualsWithDelta(270, (float) $draft->fee_withheld, 0.001);
        $ret = $svc->post($draft->fresh());

        // HPP 3 × 60.000 = 180.000 keluar dari persediaan; tagihan ke pemasok 179.730.
        $this->assertEqualsWithDelta(-180000, $this->saldo('1130', $ret->journal_id), 0.01);
        $this->assertEqualsWithDelta(179730, $this->saldo('2101', $ret->journal_id), 0.01);
        $this->assertEqualsWithDelta(270, $this->saldo('5103', $ret->journal_id), 0.01);
        $this->assertEqualsWithDelta(600000 - 179730, (float) $pi->fresh()->outstanding_amount, 0.01);

        // Void memulihkan hutang faktur persis sebesar yang dikurangi.
        $svc->void($ret->fresh());
        $this->assertEqualsWithDelta(600000, (float) $pi->fresh()->outstanding_amount, 0.01);
    }

    public function test_tanpa_biaya_tetap_seperti_semula(): void
    {
        $pi  = $this->fakturPosted();
        $svc = app(PurchaseReturnService::class);
        $ret = $svc->post($svc->createDraft([
            'purchase_invoice_id' => $pi->id,
            'return_date'         => now()->toDateString(),
            'items'               => [['purchase_invoice_item_id' => $pi->items->first()->id, 'qty' => 3]],
        ]));

        $this->assertEqualsWithDelta(180000, $this->saldo('2101', $ret->journal_id), 0.01);
        $this->assertEqualsWithDelta(0, $this->saldo('5103', $ret->journal_id), 0.01);
        $this->assertNull($ret->fresh()->fee_account_id);
    }

    public function test_form_menampilkan_isian_biaya(): void
    {
        $this->actingAs($this->admin)->get(route('purchasing.returns.create'))
            ->assertOk()->assertSee('Biaya tidak dikembalikan')->assertSee('5103');
    }

    public function test_biaya_melebihi_nilai_retur_ditolak(): void
    {
        $pi = $this->fakturPosted();
        $this->expectException(\DomainException::class);
        app(PurchaseReturnService::class)->createDraft([
            'purchase_invoice_id' => $pi->id,
            'return_date'         => now()->toDateString(),
            'items'               => [['purchase_invoice_item_id' => $pi->items->first()->id, 'qty' => 1]],
            'fee_withheld'        => '70000',
        ]);
    }
}
