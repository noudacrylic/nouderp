<?php

namespace Tests\Feature\Sales;

use App\Core\Inventory\Product;
use App\Models\Customer;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nasib UANG sebuah retur ditentukan oleh KONDISI BARANG per baris, bukan oleh satu kolom
 * hasil klaim di tingkat dokumen.
 *
 *   utuh / perbaikan / rusak  → membalik penjualan (dana dikembalikan)
 *   tidak_kembali             → barang hilang TAPI dananya diganti marketplace → tidak
 *                               membalik apa pun; omzet & HPP tetap sah
 *
 * Per baris supaya satu pesanan bisa campur: sebagian diganti, sebagian benar-benar kembali.
 */
class SalesReturnCaseTypeTest extends TestCase
{
    use RefreshDatabase;

    private int $customerId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customerId = Customer::create([
            'code' => 'CUST-RET', 'name' => 'Shopee', 'is_marketplace' => true, 'is_active' => true,
        ])->id;

        $this->productId = Product::firstOrCreate(['sku' => 'RET-TEST'], [
            'name' => 'Produk Uji Retur', 'sale_type' => 'ready', 'base_unit' => 'pcs',
            'base_price' => 1000, 'is_active' => true, 'is_sellable' => true,
        ])->id;
    }

    /** @param array<array{0:string,1:float}> $baris pasangan [kondisi, subtotal] */
    private function retur(array $baris, ?string $type = 'paket_hilang'): SalesReturn
    {
        $r = SalesReturn::create([
            'return_number' => 'SR-' . uniqid(),
            'customer_id'   => $this->customerId,
            'return_date'   => now()->toDateString(),
            'grand_total'   => array_sum(array_column($baris, 1)),
            'status'        => 'posted',
            'stage'         => 'selesai',
            'return_type'   => $type,
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

        return $r->fresh();
    }

    public function test_semua_tidak_kembali_tidak_membalik_apa_pun(): void
    {
        $r = $this->retur([['tidak_kembali', 77999]]);

        $this->assertTrue($r->skipsReversal());
        $this->assertEqualsWithDelta(0.0, $r->reversedAmount(), 0.01);
    }

    public function test_barang_rusak_membalik_penuh(): void
    {
        $r = $this->retur([['damaged', 77999]]);

        $this->assertFalse($r->skipsReversal());
        $this->assertEqualsWithDelta(77999.0, $r->reversedAmount(), 0.01);
    }

    public function test_utuh_dan_perbaikan_membalik_penuh(): void
    {
        $r = $this->retur([['good', 50000], ['repair', 30000]]);

        $this->assertFalse($r->skipsReversal());
        $this->assertEqualsWithDelta(80000.0, $r->reversedAmount(), 0.01);
    }

    /** Inilah alasan kondisinya dipasang per baris, bukan per dokumen. */
    public function test_retur_campur_hanya_membalik_baris_yang_kembali(): void
    {
        $r = $this->retur([['tidak_kembali', 30999], ['good', 25000], ['damaged', 10000]]);

        $this->assertFalse($r->skipsReversal(), 'masih ada baris yang membalik');
        $this->assertEqualsWithDelta(35000.0, $r->reversedAmount(), 0.01,
            'hanya baris utuh + rusak yang membalik; yang dananya diganti tidak');
        $this->assertEqualsWithDelta(65999.0, (float) $r->grand_total, 0.01,
            'grand_total tetap nilai kasus seutuhnya');
    }

    public function test_retur_tanpa_baris_tidak_dianggap_tanpa_pembalikan(): void
    {
        $r = $this->retur([]);

        $this->assertFalse($r->skipsReversal());
        $this->assertEqualsWithDelta(0.0, $r->reversedAmount(), 0.01);
    }

    public function test_daftar_jenis_dan_kondisi(): void
    {
        $this->assertSame('Paket Hilang', (new SalesReturn(['return_type' => 'paket_hilang']))->returnTypeLabel());
        $this->assertSame('—', (new SalesReturn())->returnTypeLabel());
        $this->assertCount(6, SalesReturn::RETURN_TYPES);
        $this->assertCount(5, SalesReturn::CONDITIONS);
        // Dua keadaan "barang tidak kembali" yang jurnalnya berlawanan, dan keduanya wajib
        // bisa diungkapkan: `tidak_kembali` (dana diganti, penjualan sah) vs `hilang` (dana
        // dikembalikan, penjualan batal & modalnya jadi kerugian).
        $this->assertArrayHasKey(SalesReturn::CONDITION_NO_RETURN, SalesReturn::CONDITIONS);
        $this->assertArrayHasKey('hilang', SalesReturn::CONDITIONS);
    }
}
