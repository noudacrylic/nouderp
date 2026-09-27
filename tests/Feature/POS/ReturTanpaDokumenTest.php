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
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pesanan diretur HARUS berujung pada dokumen retur yang bisa dibuka.
 *
 * Dua lubang yang ditambal di sini, keduanya membuat kartu "BELUM ADA DOKUMEN"
 * menggantung permanen di tab Retur (28 pesanan per 27 Sep 2026):
 *
 *   1. Pembuat draft cuma membaca daftar retur WMS Jubelio. Pesanan yang ditandai
 *      'returned' lewat status pesanan — barangnya belum sampai gudang, atau
 *      returnya sudah di-accept/reject di Jubelio — tak pernah ada di daftar itu,
 *      jadi berapa kali pun "Tarik Retur" diklik tak ada yang terbentuk.
 *   2. Pendeteksi "belum ada dokumen" cuma mencocokkan sales_returns.sales_order_id,
 *      padahal retur atas FAKTUR menyimpan invoice_id dengan sales_order_id NULL —
 *      dan itulah jalur normal pesanan marketplace.
 */
class ReturTanpaDokumenTest extends TestCase
{
    use RefreshDatabase;

    private function pesananBerfaktur(): array
    {
        $cust = Customer::create([
            'code'           => 'CUST-' . uniqid(),
            'name'           => 'Shopee Official',
            'is_marketplace' => true,
            'is_active'      => true,
        ]);

        $warehouseId = Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id;

        $produk = Product::create([
            'sku'       => 'SKU-' . uniqid(),
            'name'      => 'Akrilik A4',
            'sale_type' => 'ready',
        ]);

        $so = SalesOrder::create([
            'order_number'         => 'SO-' . uniqid(),
            'customer_id'          => $cust->id,
            'warehouse_id'         => $warehouseId,
            'order_date'           => now()->subDays(20)->toDateString(),
            'global_discount_type' => 'nominal',
            'status'               => 'confirmed',
            'subtotal'             => 100000,
            'grand_total'          => 100000,
            'paid_amount'          => 100000,
            'delivery_method'      => 'kurir',
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-' . uniqid(),
            'sales_order_id' => $so->id,
            'customer_id'    => $cust->id,
            'warehouse_id'   => $warehouseId,
            'invoice_date'   => now()->subDays(5)->toDateString(),
            'subtotal'       => 100000,
            'grand_total'    => 100000,
            'status'         => 'posted',
        ]);

        SalesInvoiceItem::create([
            'sales_invoice_id' => $invoice->id,
            'product_id'       => $produk->id,
            'description'      => 'Akrilik A4',
            'qty'              => 2,
            'unit_price'       => 50000,
            'subtotal'         => 100000,
        ]);

        JubelioOrderLink::create([
            'jubelio_salesorder_id' => 7001,
            'jubelio_salesorder_no' => 'SP-7001',
            'sales_order_id'        => $so->id,
            'store'                 => 'Shopee',
            'dp_posted'             => true,
            'last_status'           => 'returned',
        ]);

        return [$so, $invoice];
    }

    private function baris(): \Illuminate\Support\Collection
    {
        return app(FulfillmentReadinessService::class)->returRows('baru');
    }

    /**
     * Status 'returned' saja sudah cukup untuk menerbitkan draft. Dulu pesanan ini
     * mentok: tak ada di daftar WMS → tak pernah dapat dokumen, selamanya.
     */
    public function test_pesanan_berstatus_returned_dapat_draft_meski_tak_ada_di_daftar_wms(): void
    {
        [$so, $invoice] = $this->pesananBerfaktur();

        $this->assertNull($this->baris()->firstWhere('number', $so->order_number)['retur_id']);

        $stats = app(JubelioOrderSyncService::class)->syncReturns();

        $this->assertSame(1, $stats['created']);

        $retur = SalesReturn::where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($retur, 'draft retur harus terbit dari status pesanan');
        $this->assertSame('draft', $retur->status);
        $this->assertSame('baru', $retur->stage);
        // Qty penuh — CS mengoreksinya saat cek barang.
        $this->assertEquals(2, $retur->items->first()->qty);
        // Flag diklaim supaya putaran berikutnya tak membuat dokumen kedua.
        $this->assertTrue((bool) JubelioOrderLink::where('sales_order_id', $so->id)->value('return_created'));
        // Kartu "BELUM ADA DOKUMEN" hilang dari tab Retur, berganti kartu returnya.
        $baris = $this->baris();
        $this->assertCount(1, $baris);
        $this->assertSame($retur->return_number, $baris->first()['retur_number']);
    }

    /** Sekali jalan, bukan tiap putaran cron: dokumen yang sudah ada tak digandakan. */
    public function test_tarik_retur_kedua_kali_tidak_menggandakan_dokumen(): void
    {
        $this->pesananBerfaktur();

        app(JubelioOrderSyncService::class)->syncReturns();
        $stats = app(JubelioOrderSyncService::class)->syncReturns();

        $this->assertSame(0, $stats['created']);
        $this->assertSame(1, SalesReturn::count());
    }

    /** Retur yang dibuat manual oleh CS juga menghalangi draft otomatis. */
    public function test_retur_buatan_manual_tidak_ditimpa(): void
    {
        [$so, $invoice] = $this->pesananBerfaktur();

        SalesReturn::create([
            'return_number' => 'SR-MANUAL',
            'customer_id'   => $so->customer_id,
            'invoice_id'    => $invoice->id,
            'return_date'   => now()->toDateString(),
            'grand_total'   => 50000,
            'status'        => 'draft',
            'stage'         => 'baru',
        ]);

        $stats = app(JubelioOrderSyncService::class)->syncReturns();

        $this->assertSame(0, $stats['created']);
        $this->assertSame(1, SalesReturn::count());
    }

    /**
     * Retur yang menggantung di FAKTUR tetap dianggap "sudah ada dokumen".
     *
     * Dulu pesanannya muncul dua kali di tab Retur Baru — sekali sebagai kartu
     * returnya, sekali lagi sebagai "BELUM ADA DOKUMEN" — dan dihitung dua kali
     * di lencana sub-tabnya.
     */
    public function test_retur_lewat_faktur_tidak_dihitung_dua_kali(): void
    {
        [$so, $invoice] = $this->pesananBerfaktur();

        $retur = SalesReturn::create([
            'return_number'  => 'SR-FAKTUR',
            'customer_id'    => $so->customer_id,
            'invoice_id'     => $invoice->id,
            'sales_order_id' => null, // persis yang disimpan SalesReturnService::saveDraft
            'return_date'    => now()->toDateString(),
            'grand_total'    => 50000,
            'status'         => 'draft',
            'stage'          => 'baru',
        ]);

        $baris = $this->baris();

        $this->assertCount(1, $baris, 'pesanan yang sudah punya retur tak boleh muncul dua kali');
        $this->assertSame($retur->return_number, $baris->first()['retur_number']);
        $this->assertSame(1, app(FulfillmentReadinessService::class)->returCounts()['baru']);
    }

    /* ------------------------------------------- tombol "+ Buat Retur" terisi */

    /**
     * Kartu sudah memegang pelanggan & fakturnya, jadi form retur dibuka terisi.
     * Menyuruh CS mengetik ulang keduanya cuma undangan salah pilih dokumen:
     * pelanggan marketplace punya ribuan faktur bernomor mirip.
     */
    public function test_form_retur_terisi_dari_nomor_pesanan(): void
    {
        [$so, $invoice] = $this->pesananBerfaktur();

        $res = $this->actingAs($this->admin())
            ->get(route('sales.returns.create', ['sales_order_id' => $so->id]))
            ->assertOk();

        $prefill = $res->viewData('prefill');

        $this->assertSame($invoice->id, $prefill['invoice_id']);
        $this->assertSame($so->customer_id, $prefill['customer_id']);
        $this->assertSame('Shopee Official', $prefill['customer']['name']);
        $this->assertSame($invoice->invoice_number, $prefill['invoice']['invoice_number']);
        $this->assertTrue($prefill['customer']['is_marketplace']);
    }

    /** Faktur void tidak boleh terpilih — tak ada yang bisa diretur dari sana. */
    public function test_faktur_void_tidak_dipakai_mengisi_form(): void
    {
        [$so, $invoice] = $this->pesananBerfaktur();
        $invoice->update(['status' => 'void']);

        $res = $this->actingAs($this->admin())
            ->get(route('sales.returns.create', ['sales_order_id' => $so->id]))
            ->assertOk();

        $this->assertNull($res->viewData('prefill'));
    }

    /** Dibuka tanpa parameter, form tetap polos seperti sebelumnya. */
    public function test_form_retur_polos_tanpa_parameter(): void
    {
        $this->assertNull(
            $this->actingAs($this->admin())->get(route('sales.returns.create'))->assertOk()->viewData('prefill')
        );
    }

    /**
     * Pesanan tanpa faktur TIDAK dibuatkan draft. Dokumen induknya akan jatuh ke SO,
     * dan retur atas SO membalik Uang Muka serta mengkredit HPP yang belum pernah
     * dibukukan. Obatnya `marketplace:faktur-susulan` dulu, baru returnya.
     */
    public function test_pesanan_tanpa_faktur_tidak_dibuatkan_draft(): void
    {
        [$so, $invoice] = $this->pesananBerfaktur();
        $invoice->update(['status' => 'void']);

        $stats = app(JubelioOrderSyncService::class)->syncReturns();

        $this->assertSame(0, $stats['created']);
        $this->assertSame(0, SalesReturn::count());
        // Flag tak diklaim, jadi setelah faktur susulan terbit ia dicoba lagi.
        $this->assertFalse((bool) JubelioOrderLink::where('sales_order_id', $so->id)->value('return_created'));
    }

    private function admin(): \App\Models\User
    {
        return \App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }
}
