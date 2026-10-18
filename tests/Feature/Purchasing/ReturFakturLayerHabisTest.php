<?php

namespace Tests\Feature\Purchasing;

use App\Core\Inventory\FifoService;
use App\Core\Inventory\Product;
use App\Core\Inventory\StockLayer;
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
 * Faktur dicatat tapi barang tak pernah datang: stok hantunya sudah terpakai FIFO,
 * jadi layer faktur asal habis. Retur tetap harus bisa — sisanya diambil FIFO dari
 * layer lain, selisih HPP ke 4100/5200.
 */
class ReturFakturLayerHabisTest extends TestCase
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
        $this->supplier = Supplier::create(['code' => 'SUP-1', 'name' => 'Ko Awi', 'is_active' => true]);
        $this->gudang   = Warehouse::create(['name' => 'Utama', 'is_active' => true]);
        $this->produk   = Product::create([
            'sku' => 'AKR-2', 'name' => 'Akrilik 2mm', 'sale_type' => 'ready',
            'base_unit' => 'pcs', 'base_price' => 400000, 'is_active' => true,
        ]);
    }

    private function fakturPosted(int $qty, float $harga): PurchaseInvoice
    {
        $this->actingAs($this->admin)->post(route('purchasing.invoices.store'), [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->gudang->id,
            'invoice_date' => now()->toDateString(),
            'items' => [['product_id' => $this->produk->id, 'qty' => $qty, 'price' => $harga, 'unit' => 'pcs']],
            '_after_save' => 'post',
        ])->assertSessionHasNoErrors();

        return PurchaseInvoice::latest('id')->firstOrFail();
    }

    private function retur(PurchaseInvoice $pi, int $qty)
    {
        $svc = app(PurchaseReturnService::class);
        $draft = $svc->createDraft([
            'purchase_invoice_id' => $pi->id,
            'return_date' => now()->toDateString(),
            'items' => [['purchase_invoice_item_id' => $pi->items->first()->id, 'qty' => $qty]],
        ]);
        return $svc->post($draft);
    }

    private function saldo(string $code, $journalId): float
    {
        return (float) JournalLine::where('journal_id', $journalId)
            ->whereHas('account', fn($q) => $q->where('code', $code))
            ->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) AS s')->value('s');
    }

    public function test_layer_faktur_habis_ambil_dari_layer_lain_dan_selisih_ke_pendapatan(): void
    {
        $hantu = $this->fakturPosted(10, 317000);
        $this->assertSame('posted', $hantu->status);
        $lain  = $this->fakturPosted(20, 300000);

        // Stok hantu terpakai FIFO (mis. produksi)
        app(FifoService::class)->consume($this->produk->id, $this->gudang->id, 10, 'test', 0);

        $ret = $this->retur($hantu->fresh('items'), 10);

        $this->assertSame('posted', $ret->status);
        $this->assertEqualsWithDelta(-3000000, $this->saldo('1130', $ret->journal_id), 0.01);
        $this->assertEqualsWithDelta(3170000, $this->saldo('2101', $ret->journal_id), 0.01);
        $this->assertEqualsWithDelta(-170000, $this->saldo('4100', $ret->journal_id), 0.01);
        $this->assertEqualsWithDelta(10, (float) StockLayer::where('source_id', $lain->id)->where('source_type', 'purchase')->value('qty_remaining'), 0.0001);
        $this->assertEqualsWithDelta(10, (float) $hantu->items()->first()->qty_returned, 0.0001);
    }

    public function test_layer_lain_lebih_mahal_selisih_ke_beban(): void
    {
        $hantu = $this->fakturPosted(10, 317000);
        $this->fakturPosted(20, 330000);
        app(FifoService::class)->consume($this->produk->id, $this->gudang->id, 10, 'test', 0);

        $ret = $this->retur($hantu->fresh('items'), 10);

        $this->assertEqualsWithDelta(-3300000, $this->saldo('1130', $ret->journal_id), 0.01);
        $this->assertEqualsWithDelta(130000, $this->saldo('5200', $ret->journal_id), 0.01);
    }

    public function test_sebagian_dari_layer_faktur_sisanya_layer_lain(): void
    {
        $hantu = $this->fakturPosted(10, 317000);
        $this->fakturPosted(20, 300000);
        app(FifoService::class)->consume($this->produk->id, $this->gudang->id, 4, 'test', 0);

        $ret = $this->retur($hantu->fresh('items'), 10);

        // 6 dari layer faktur (6×317rb) + 4 dari layer lain (4×300rb) → selisih −68rb
        $this->assertEqualsWithDelta(-(1902000 + 1200000), $this->saldo('1130', $ret->journal_id), 0.01);
        $this->assertEqualsWithDelta(-68000, $this->saldo('4100', $ret->journal_id), 0.01);
    }

    public function test_stok_total_kurang_tetap_ditolak(): void
    {
        $hantu = $this->fakturPosted(10, 317000);
        app(FifoService::class)->consume($this->produk->id, $this->gudang->id, 10, 'test', 0);

        $this->expectException(\DomainException::class);
        $this->retur($hantu->fresh('items'), 10);
    }
}
