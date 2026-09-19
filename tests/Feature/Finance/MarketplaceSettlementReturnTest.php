<?php

namespace Tests\Feature\Finance;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Modules\Finance\Services\MarketplaceSettlementService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rekonsiliasi marketplace tidak cukup melihat faktur — retur ikut menentukan berapa yang
 * SEHARUSNYA cair.
 *
 * Kasus nyata yang mendasarinya (pesanan Shopee 2608113FR4CT2P): 4 pcs diretur, subtotal turun
 * 618.000 → 281.587, dana cair 235.613. Kalau gross tetap dihitung 618.000, feeActual melar
 * jadi ratusan ribu dan baris itu tampak seperti potongan admin raksasa.
 *
 * Pengecualiannya baris berkondisi `tidak_kembali`: barang hilang tapi dananya diganti
 * marketplace, jadi baris itu tidak pernah membalik penjualan dan nilai yang diharapkan cair
 * TETAP sebesar faktur. Dihitung per baris, sehingga satu retur boleh campur.
 */
class MarketplaceSettlementReturnTest extends TestCase
{
    use RefreshDatabase;

    private int $customerId;
    private int $productId;
    private SalesInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customerId = Customer::create([
            'code' => 'CUST-MP', 'name' => 'Shopee Official', 'is_marketplace' => true, 'is_active' => true,
        ])->id;

        $this->productId = Product::firstOrCreate(['sku' => 'RET-TEST'], [
            'name' => 'Produk Uji Retur', 'sale_type' => 'ready', 'base_unit' => 'pcs',
            'base_price' => 1000, 'is_active' => true, 'is_sellable' => true,
        ])->id;

        $warehouseId = Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id;

        $so = SalesOrder::create([
            'order_number' => 'SO-RET-1',
            'customer_id'  => $this->customerId,
            'warehouse_id' => $warehouseId,
            'order_date'   => now()->toDateString(),
            'status'       => 'confirmed',
            'subtotal'     => 618000,
            'grand_total'  => 618000,
        ]);

        // Faktur bersih 550.000 + biaya admin 68.000 → gross (nilai jual) 618.000.
        $this->invoice = SalesInvoice::create([
            'invoice_number'  => 'INV-RET-1',
            'sales_order_id'  => $so->id,
            'customer_id'     => $this->customerId,
            'warehouse_id'    => $warehouseId,
            'invoice_date'    => now()->toDateString(),
            'subtotal'        => 618000,
            'grand_total'     => 550000,
            'marketplace_fee' => 68000,
            'status'          => 'posted',
        ]);
    }

    /** Panggil resolveGross() yang protected. */
    private function gross(float $net = 0): float
    {
        $svc = $this->app->make(MarketplaceSettlementService::class);
        $m = new \ReflectionMethod($svc, 'resolveGross');
        $m->setAccessible(true);

        return (float) $m->invoke($svc, $this->invoice, ['net_amount' => $net])[0];
    }

    /** @param array<array{0:string,1:float}> $baris pasangan [kondisi, subtotal] */
    private function retur(array $baris, string $status = 'posted'): SalesReturn
    {
        $r = SalesReturn::create([
            'return_number'  => 'SR-' . uniqid(),
            'customer_id'    => $this->customerId,
            'sales_order_id' => $this->invoice->sales_order_id,
            'return_date'    => now()->toDateString(),
            'grand_total'    => array_sum(array_column($baris, 1)),
            'status'         => $status,
            'stage'          => $status === 'posted' ? 'selesai' : 'diproses',
            'return_type'    => 'paket_hilang',
        ]);

        foreach ($baris as [$kondisi, $subtotal]) {
            SalesReturnItem::create([
                'sales_return_id'   => $r->id,
                'reference_item_id' => 0, // tak ada dokumen sumber di tes ini
                'product_id'        => $this->productId,
                'qty'               => 1,
                'unit_price'        => $subtotal,
                'subtotal'          => $subtotal,
                'condition'         => $kondisi,
            ]);
        }

        return $r;
    }

    public function test_tanpa_retur_gross_sama_dengan_faktur_plus_biaya_admin(): void
    {
        $this->assertEqualsWithDelta(618000.0, $this->gross(), 0.01);
    }

    public function test_retur_sebagian_mengurangi_gross(): void
    {
        $this->retur([['good', 336413]]);

        $this->assertEqualsWithDelta(281587.0, $this->gross(235613), 0.01,
            'gross harus turun sebesar retur yang membalik penjualan');
    }

    public function test_barang_tidak_kembali_tapi_dana_diganti_tidak_mengurangi_gross(): void
    {
        $this->retur([['tidak_kembali', 618000]]);

        $this->assertEqualsWithDelta(618000.0, $this->gross(618000), 0.01,
            'dananya diganti → nilai yang diharapkan cair tetap penuh');
    }

    public function test_barang_rusak_dan_dana_hilang_mengurangi_gross(): void
    {
        $this->retur([['damaged', 618000]]);

        $this->assertEqualsWithDelta(0.0, $this->gross(0), 0.01,
            'dana ikut hilang → retur membalik penuh, gross habis');
    }

    /** Satu retur campur: sebagian diganti marketplace, sebagian benar-benar dikembalikan. */
    public function test_retur_campur_hanya_mengurangi_baris_yang_membalik(): void
    {
        $this->retur([['tidak_kembali', 200000], ['good', 136413]]);

        $this->assertEqualsWithDelta(481587.0, $this->gross(), 0.01,
            'hanya baris yang membalik penjualan yang mengurangi gross');
    }

    public function test_retur_draft_dan_void_tidak_dihitung(): void
    {
        $this->retur([['good', 100000]], 'draft');
        $this->retur([['good', 100000]], 'void');

        $this->assertEqualsWithDelta(618000.0, $this->gross(),  0.01,
            'hanya retur POSTED yang benar-benar membalik penjualan');
    }

    public function test_gross_tidak_pernah_negatif(): void
    {
        $this->retur([['good', 900000]]);

        $this->assertEqualsWithDelta(0.0, $this->gross(), 0.01);
    }

    /**
     * Faktur gaya BARU: terbit saat pengiriman mengikuti SO persis, jadi `grand_total` sudah
     * KOTOR dan `marketplace_fee` diisi belakangan oleh engine sebagai catatan "sudah
     * dibebankan". Menambahkannya lagi ke grand_total akan menggelembungkan nilai jual.
     */
    public function test_faktur_gaya_baru_gross_adalah_grand_total_apa_adanya(): void
    {
        $this->invoice->forceFill([
            'grand_total'       => 618000,   // kotor
            'marketplace_fee'   => 68000,    // sudah dibebankan engine saat pesanan selesai
            'fee_at_settlement' => true,
        ])->save();

        $this->assertEqualsWithDelta(618000.0, $this->gross(550000), 0.01);
    }

    public function test_faktur_gaya_baru_tetap_dikurangi_retur(): void
    {
        $this->invoice->forceFill([
            'grand_total'       => 618000,
            'marketplace_fee'   => 68000,
            'fee_at_settlement' => true,
        ])->save();

        $this->retur([['good', 336413]]);

        $this->assertEqualsWithDelta(281587.0, $this->gross(235613), 0.01);
    }
}
