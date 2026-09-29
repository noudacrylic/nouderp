<?php

namespace Tests\Feature\Sales;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnItem;
use App\Modules\Sales\Services\SalesReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Retur DRAFT yang lahir saat SO belum berfaktur harus bisa dipindah ke faktur SO itu.
 *
 * Tetap di SO berarti saat diposting ia membalik Uang Muka — padahal Uang Muka itu sudah
 * habis dipakai faktur — dan kasusnya tak tersambung ke dokumen penjualannya. Yang berganti
 * hanya dokumen acuan: tanggal, jenis, kondisi, dan catatan retur tidak disentuh.
 */
class ReturPindahKeFakturTest extends TestCase
{
    use RefreshDatabase;

    private int $customerId;
    private int $productId;
    private int $warehouseId;
    private SalesOrder $so;
    private int $soItemId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customerId = Customer::create([
            'code' => 'CUST-PF', 'name' => 'Shopee', 'is_marketplace' => true, 'is_active' => true,
        ])->id;
        $this->warehouseId = Warehouse::firstOrCreate(['name' => 'Gudang PF'])->id;
        $this->productId = Product::firstOrCreate(['sku' => 'PF-1'], [
            'name' => 'Produk PF', 'sale_type' => 'ready', 'base_unit' => 'pcs',
            'base_price' => 18999, 'is_active' => true, 'is_sellable' => true,
        ])->id;

        $this->so = SalesOrder::create([
            'order_number' => 'SP-PF-1', 'customer_id' => $this->customerId,
            'warehouse_id' => $this->warehouseId, 'order_date' => '2026-06-29',
            'status' => 'confirmed', 'subtotal' => 37998, 'grand_total' => 37998,
        ]);
        $this->soItemId = DB::table('sales_order_items')->insertGetId([
            'sales_order_id' => $this->so->id, 'product_id' => $this->productId, 'qty' => 2,
            'conversion_to_base' => 1, 'unit_price' => 18999, 'discount_per_unit' => 0,
            'net_unit_price' => 18999, 'line_subtotal' => 37998, 'line_discount' => 0,
            'line_total' => 37998, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function faktur(string $status = 'posted', float $qty = 2, ?int $soItemId = null): array
    {
        $inv = SalesInvoice::create([
            'invoice_number' => 'SP-PF-1', 'customer_id' => $this->customerId,
            'warehouse_id' => $this->warehouseId, 'sales_order_id' => $this->so->id,
            'invoice_date' => '2026-09-19', 'subtotal' => 18999 * $qty,
            'grand_total' => 18999 * $qty, 'status' => $status,
        ]);
        $itemId = DB::table('sales_invoice_items')->insertGetId([
            'sales_invoice_id' => $inv->id, 'sales_order_item_id' => $soItemId ?? $this->soItemId,
            'product_id' => $this->productId, 'description' => 'Produk PF', 'item_type' => 'product',
            'qty' => $qty, 'unit_price' => 18999, 'subtotal' => 18999 * $qty,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$inv, $itemId];
    }

    private function returSO(string $status = 'draft', float $qty = 1): SalesReturn
    {
        $r = SalesReturn::create([
            'return_number' => 'SR-PF-' . uniqid(), 'customer_id' => $this->customerId,
            'sales_order_id' => $this->so->id, 'return_date' => '2026-07-01',
            'grand_total' => 18999 * $qty, 'status' => $status, 'stage' => 'baru',
            'return_type' => 'gagal_kirim', 'notes' => 'catatan CS',
        ]);
        SalesReturnItem::create([
            'sales_return_id' => $r->id, 'reference_item_id' => $this->soItemId,
            'product_id' => $this->productId, 'qty' => $qty, 'unit_price' => 18999,
            'subtotal' => 18999 * $qty, 'condition' => 'tidak_kembali',
        ]);

        return $r;
    }

    public function test_draft_pindah_ke_faktur_dan_tanggal_tetap(): void
    {
        [$inv, $invItemId] = $this->faktur();
        $retur = $this->returSO();

        $hasil = app(SalesReturnService::class)->pindahkanReturKeFaktur($retur);

        $this->assertSame($inv->id, $hasil->id);
        $retur->refresh();
        $this->assertSame($inv->id, (int) $retur->invoice_id);
        $this->assertNull($retur->sales_order_id, 'jalur SO membalik Uang Muka — kaitannya harus lepas');
        $this->assertSame('2026-07-01', $retur->return_date->format('Y-m-d'));
        $this->assertSame('draft', $retur->status);
        $this->assertSame('gagal_kirim', $retur->return_type);
        $this->assertSame('catatan CS', $retur->notes);

        $item = $retur->items()->first();
        $this->assertSame($invItemId, (int) $item->reference_item_id);
        $this->assertSame('tidak_kembali', $item->condition);
        $this->assertEqualsWithDelta(18999, (float) $retur->grand_total, 0.01);
    }

    public function test_retur_posted_tidak_dipindah(): void
    {
        $this->faktur();
        $retur = $this->returSO('posted');

        $hasil = app(SalesReturnService::class)->fakturUntukReturSO($retur);

        $this->assertNull($hasil['invoice']);
        $this->assertStringContainsString('draf', $hasil['alasan']);
    }

    public function test_so_tanpa_faktur_aktif_tidak_dipindah(): void
    {
        $this->faktur('void');
        $retur = $this->returSO();

        $hasil = app(SalesReturnService::class)->fakturUntukReturSO($retur);

        $this->assertNull($hasil['invoice']);
        $this->assertSame($this->so->id, (int) $retur->fresh()->sales_order_id);
    }

    public function test_qty_melebihi_baris_faktur_tidak_dipindah(): void
    {
        // Faktur parsial: baru 1 dari 2 barang yang difakturkan, retur minta 2.
        $this->faktur('posted', 1);
        $retur = $this->returSO('draft', 2);

        $hasil = app(SalesReturnService::class)->fakturUntukReturSO($retur);

        $this->assertNull($hasil['invoice']);
    }

    public function test_command_memindahkan_draft_dan_dry_run_tidak_menulis(): void
    {
        [$inv] = $this->faktur();
        $retur = $this->returSO();

        $this->artisan('retur:pindah-ke-faktur', ['--dry-run' => true])->assertSuccessful();
        $this->assertNull($retur->fresh()->invoice_id);

        $this->artisan('retur:pindah-ke-faktur')->assertSuccessful();
        $this->assertSame($inv->id, (int) $retur->fresh()->invoice_id);
    }

    public function test_pindahkan_semua_draft_saat_faktur_terbit(): void
    {
        [$inv] = $this->faktur();
        $a = $this->returSO();
        $b = $this->returSO('posted');

        $hasil = app(SalesReturnService::class)->pindahkanDraftSOKeFaktur($this->so->id, $inv);

        $this->assertSame([$a->return_number], $hasil['dipindah']);
        $this->assertSame($inv->id, (int) $a->fresh()->invoice_id);
        $this->assertNull($b->fresh()->invoice_id, 'retur posted tak boleh disentuh');
    }
}
