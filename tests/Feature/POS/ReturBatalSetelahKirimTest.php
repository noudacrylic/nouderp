<?php

namespace Tests\Feature\POS;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink;
use App\Modules\Marketplace\Jubelio\Services\JubelioOrderSyncService;
use App\Modules\POS\Services\FulfillmentReadinessService;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesDeliveryItem;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pesanan marketplace yang dibatalkan SETELAH barangnya keluar adalah kasus RETUR.
 *
 * Per 29 Sep 2026 ada 12 pesanan begini menggantung di tab Pembatalan dengan anjuran
 * "void manual" — padahal SO-nya justru tidak boleh di-void, dan 10 di antaranya sudah
 * punya draft retur. Dua sisanya tak pernah dapat retur karena Surat Jalannya memuat
 * produk yang berbeda dari faktur (paket dikirim sebagai komponen, varian diganti).
 */
class ReturBatalSetelahKirimTest extends TestCase
{
    use RefreshDatabase;

    /** Faktur memuat PAKET, Surat Jalan memuat KOMPONENNYA — bentuk yang dulu gagal. */
    private function pesananBatalSetelahKirim(bool $returnCreated = false, bool $sudahDiserahkan = true): array
    {
        $cust = Customer::create([
            'code' => 'CUST-' . uniqid(), 'name' => 'Shopee Official',
            'is_marketplace' => true, 'is_active' => true,
        ]);
        $warehouseId = Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id;

        $paket    = Product::create(['sku' => 'FF-3R', 'name' => 'Frame Foto 3R', 'sale_type' => 'bundle']);
        $komponen = Product::create(['sku' => 'FF-3R-Body', 'name' => 'Frame Foto 3R Body', 'sale_type' => 'ready']);

        $so = SalesOrder::create([
            'order_number' => 'SO-' . uniqid(), 'customer_id' => $cust->id, 'warehouse_id' => $warehouseId,
            'order_date' => now()->subDays(10)->toDateString(), 'global_discount_type' => 'nominal',
            'status' => 'confirmed', 'subtotal' => 50000, 'grand_total' => 50000, 'paid_amount' => 50000,
            'delivery_method' => 'kurir',
        ]);
        $soItem = SalesOrderItem::create([
            'sales_order_id' => $so->id, 'product_id' => $paket->id, 'qty' => 1,
            'conversion_to_base' => 1, 'unit_price' => 50000, 'net_unit_price' => 50000,
            'line_subtotal' => 50000, 'line_discount' => 0, 'line_total' => 50000,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-' . uniqid(), 'sales_order_id' => $so->id, 'customer_id' => $cust->id,
            'warehouse_id' => $warehouseId, 'invoice_date' => now()->subDays(8)->toDateString(),
            'subtotal' => 50000, 'grand_total' => 50000, 'status' => 'posted',
        ]);
        SalesInvoiceItem::create([
            'sales_invoice_id' => $invoice->id, 'sales_order_item_id' => $soItem->id, 'product_id' => $paket->id,
            'description' => 'Frame Foto 3R', 'qty' => 1, 'unit_price' => 50000, 'subtotal' => 50000,
        ]);

        $sj = SalesDelivery::create([
            'delivery_number' => 'DO-' . uniqid(), 'sales_order_id' => $so->id,
            'reference_type' => 'sales_order', 'reference_id' => $so->id,
            'warehouse_id' => $warehouseId, 'delivery_date' => now()->subDays(8), 'status' => 'posted',
        ]);
        SalesDeliveryItem::create([
            'sales_delivery_id' => $sj->id, 'sales_order_item_id' => $soItem->id,
            'product_id' => $komponen->id, 'qty' => 2, 'cogs_total' => 5000,
        ]);

        JubelioOrderLink::create([
            'jubelio_salesorder_id' => 8101, 'jubelio_salesorder_no' => 'SP-8101', 'sales_order_id' => $so->id,
            'store' => 'Shopee', 'dp_posted' => true, 'invoice_posted' => true, 'sj_created' => true,
            'last_status' => 'canceled', 'return_created' => $returnCreated,
            'shipped_at' => $sudahDiserahkan ? now()->subDays(7) : null,
        ]);

        return [$so, $invoice];
    }

    public function test_batal_setelah_kirim_tidak_nongkrong_di_tab_pembatalan(): void
    {
        [$so] = $this->pesananBatalSetelahKirim();
        $svc = app(FulfillmentReadinessService::class);

        $this->assertNull($svc->pembatalanRows()->firstWhere('id', $so->id));
        $this->assertSame(0, $svc->counts()['pembatalan']);
    }

    public function test_yang_belum_berdokumen_tetap_terlihat_di_retur_baru(): void
    {
        [$so] = $this->pesananBatalSetelahKirim();

        $baris = app(FulfillmentReadinessService::class)->returRows('baru');

        $this->assertNotNull($baris->firstWhere('id', $so->id));
        $this->assertNull($baris->firstWhere('id', $so->id)['retur_id']);
    }

    /** Paket yang dikirim sebagai komponennya tetap menjadi retur atas baris faktur paketnya. */
    public function test_tarik_retur_membuka_ulang_kasus_yang_dulu_gagal_dipetakan(): void
    {
        [$so, $invoice] = $this->pesananBatalSetelahKirim();

        $stats = app(JubelioOrderSyncService::class)->syncReturns();

        $this->assertSame(1, $stats['created']);
        $retur = SalesReturn::where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($retur);
        $this->assertSame('baru', $retur->stage);
        $this->assertEquals(1, $retur->items->first()->qty, 'qty dalam satuan faktur (1 paket), bukan qty komponen');
        $this->assertTrue((bool) JubelioOrderLink::where('sales_order_id', $so->id)->value('return_created'));

        // Putaran kedua tidak menggandakan.
        app(JubelioOrderSyncService::class)->syncReturns();
        $this->assertSame(1, SalesReturn::count());
    }

    /**
     * GARIS BATAL vs RETUR = serah ke kurir, BUKAN "ada Surat Jalan". Surat Jalan
     * marketplace terbit saat diproses; batal selagi paket di "Telah Diproses" = pembatalan.
     */
    public function test_batal_sebelum_diserahkan_kurir_tetap_di_tab_pembatalan_meski_sudah_ber_sj(): void
    {
        [$so] = $this->pesananBatalSetelahKirim(false, false);
        $svc = app(FulfillmentReadinessService::class);

        $this->assertNotNull($svc->pembatalanRows()->firstWhere('id', $so->id));
        $this->assertNull($svc->returRows('baru')->firstWhere('id', $so->id));
    }

    public function test_tarik_retur_tidak_membuat_retur_untuk_yang_belum_diserahkan(): void
    {
        $this->pesananBatalSetelahKirim(false, false);

        app(JubelioOrderSyncService::class)->syncReturns();

        $this->assertSame(0, SalesReturn::count());
    }

    private function batalkan(SalesOrder $so): void
    {
        $svc = app(JubelioOrderSyncService::class);
        $m = new \ReflectionMethod($svc, 'cancelOrderFromJubelio');
        $m->setAccessible(true);
        $m->invoke($svc, JubelioOrderLink::where('sales_order_id', $so->id)->first());
    }

    /** Sinyal batal untuk paket yang BELUM diserahkan → void, bukan retur. */
    public function test_sinyal_batal_sebelum_diserahkan_mem_void_bukan_membuka_retur(): void
    {
        [$so, $invoice] = $this->pesananBatalSetelahKirim(false, false);
        // Faktur dinonaktifkan supaya yang diuji murni garisnya; SJ di-void lewat voidDelivery.
        $invoice->forceFill(['status' => 'void'])->save();
        SalesDeliveryItem::query()->update(['cogs_deferred' => true]);

        $this->batalkan($so);

        $this->assertSame('void', $so->fresh()->status);
        $this->assertSame('void', SalesDelivery::where('sales_order_id', $so->id)->value('status'));
        $this->assertSame(0, SalesReturn::count());
    }

    public function test_sinyal_batal_sesudah_diserahkan_membuka_retur(): void
    {
        [$so] = $this->pesananBatalSetelahKirim(false, true);

        $this->batalkan($so);

        $this->assertSame('confirmed', $so->fresh()->status);
        $this->assertSame(1, SalesReturn::count());
    }

    /** Kasus lama yang terlanjur jadi retur dikoreksi admin dari kartu retur. */
    public function test_admin_mengoreksi_retur_yang_ternyata_batal_sebelum_dikirim(): void
    {
        [$so, $invoice] = $this->pesananBatalSetelahKirim(false, false);
        $invoice->forceFill(['status' => 'void'])->save();
        SalesDeliveryItem::query()->update(['cogs_deferred' => true]);
        JubelioOrderLink::where('sales_order_id', $so->id)->update(['return_created' => true]);
        SalesReturn::create([
            'return_number' => 'SR-UJI', 'sales_order_id' => $so->id, 'customer_id' => $so->customer_id,
            'return_date' => now()->toDateString(), 'status' => 'draft', 'stage' => 'baru',
        ]);

        $admin = \App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin)->get(route('pos.fulfillment.retur'))->assertOk()
            ->assertSee('Bukan retur — batal sebelum dikirim');

        $this->actingAs($admin)->post(route('pos.fulfillment.retur-jadi-batal', $so->id))
            ->assertSessionHas('success');

        $this->assertSame(0, SalesReturn::count());
        $this->assertSame('void', $so->fresh()->status);
    }
}
