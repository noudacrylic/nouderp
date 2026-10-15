<?php

namespace Tests\Feature\Purchasing;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * No. Faktur Pemasok: lazim diisi di PO, dan dulu tidak ikut terbawa saat PO
 * dijadikan faktur — 60 faktur tampil "-" walau PO-nya punya nomor.
 */
class NoFakturPemasokTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Supplier $supplier;
    private Warehouse $gudang;
    private Product $produk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin    = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->supplier = Supplier::create(['code' => 'SUP-1', 'name' => 'Kranindo', 'is_active' => true]);
        $this->gudang   = Warehouse::create(['name' => 'Utama', 'is_active' => true]);
        $this->produk   = Product::create([
            'sku' => 'AKR-3', 'name' => 'Akrilik 3mm', 'sale_type' => 'ready',
            'base_unit' => 'pcs', 'base_price' => 100000, 'is_active' => true,
        ]);
    }

    private function po(?string $noFaktur): array
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO/TES/' . uniqid(), 'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->gudang->id,
            'po_date' => '2026-10-01', 'supplier_invoice_no' => $noFaktur, 'subtotal' => 1100000, 'total' => 1100000, 'status' => 'posted',
        ]);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'product_id' => $this->produk->id, 'description' => 'Akrilik 3mm',
            'qty' => 2, 'unit' => 'pcs', 'price' => 550000, 'subtotal' => 1100000, 'qty_invoiced' => 0,
        ]);
        return [$po, $item];
    }

    private function buatFaktur(array $override = [])
    {
        return $this->actingAs($this->admin)->post(route('purchasing.invoices.store'), array_merge([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->gudang->id,
            'invoice_date' => '2026-10-04',
            'items' => [['product_id' => $this->produk->id, 'qty' => 2, 'price' => 550000, 'unit' => 'pcs']],
        ], $override))->assertSessionHasNoErrors();
    }

    public function test_tanpa_po_no_faktur_dari_form_tersimpan(): void
    {
        $this->buatFaktur(['supplier_invoice_no' => 'KTA.00500.1026', 'supplier_invoice_date' => '2026-10-03']);

        $pi = PurchaseInvoice::sole();
        $this->assertSame('KTA.00500.1026', $pi->supplier_invoice_no);
        $this->assertSame('2026-10-03', $pi->supplier_invoice_date->format('Y-m-d'));
    }

    public function test_dari_po_field_kosong_memakai_nomor_po(): void
    {
        [$po, $item] = $this->po('INV-SUP-777');

        $this->buatFaktur([
            'purchase_order_id' => $po->id,
            'items' => [['product_id' => $this->produk->id, 'purchase_order_item_id' => $item->id, 'qty' => 2, 'price' => 550000, 'unit' => 'pcs']],
        ]);

        $this->assertSame('INV-SUP-777', PurchaseInvoice::sole()->supplier_invoice_no);
    }

    public function test_dari_po_ketikan_di_faktur_tidak_ditimpa(): void
    {
        [$po, $item] = $this->po('INV-SUP-777');

        $this->buatFaktur([
            'purchase_order_id' => $po->id,
            'supplier_invoice_no' => 'INV-REVISI-1',
            'items' => [['product_id' => $this->produk->id, 'purchase_order_item_id' => $item->id, 'qty' => 2, 'price' => 550000, 'unit' => 'pcs']],
        ]);

        $this->assertSame('INV-REVISI-1', PurchaseInvoice::sole()->supplier_invoice_no);
    }

    public function test_no_faktur_bisa_diubah_walau_posted_tapi_tidak_saat_void(): void
    {
        $this->buatFaktur();
        $pi = PurchaseInvoice::sole();
        $pi->update(['status' => 'posted']);

        $this->actingAs($this->admin)->get(route('purchasing.invoices.show', $pi->id))->assertOk()->assertSee('✏️ ubah', false);

        $this->actingAs($this->admin)->patch(route('purchasing.invoices.supplier-ref', $pi->id), [
            'supplier_invoice_no' => ' KTA.00999 ', 'supplier_invoice_date' => '2026-10-02',
        ])->assertSessionHas('success');

        $pi->refresh();
        $this->assertSame('KTA.00999', $pi->supplier_invoice_no);
        $this->assertSame('2026-10-02', $pi->supplier_invoice_date->format('Y-m-d'));
        $this->assertSame('posted', $pi->status);

        $pi->update(['status' => 'void']);
        $this->actingAs($this->admin)->patch(route('purchasing.invoices.supplier-ref', $pi->id), ['supplier_invoice_no' => 'X'])
            ->assertSessionHas('error');
        $this->assertSame('KTA.00999', $pi->fresh()->supplier_invoice_no);
    }

    public function test_perintah_isi_balik_dari_po(): void
    {
        [$po, $item] = $this->po('INV-LAMA-1');
        $this->buatFaktur([
            'purchase_order_id' => $po->id,
            'items' => [['product_id' => $this->produk->id, 'purchase_order_item_id' => $item->id, 'qty' => 2, 'price' => 550000, 'unit' => 'pcs']],
        ]);
        // Tiru data lama: nomor tidak terbawa.
        DB::table('purchase_invoices')->update(['supplier_invoice_no' => null]);

        $this->artisan('purchasing:isi-no-faktur-pemasok --dry-run')->assertSuccessful();
        $this->assertNull(PurchaseInvoice::sole()->supplier_invoice_no, 'dry-run tidak boleh mengubah data');

        $this->artisan('purchasing:isi-no-faktur-pemasok')->assertSuccessful();
        $this->assertSame('INV-LAMA-1', PurchaseInvoice::sole()->supplier_invoice_no);
    }
}
