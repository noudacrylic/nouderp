<?php

namespace Tests\Feature\Analysis;

use App\Models\User;
use App\Modules\Analysis\Models\ProductPackingCost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Inline edit biaya packing per produk di halaman HPP.
 *
 * Yang dijaga: angka Indonesia terbaca benar (15.000 = lima belas ribu, bukan 15),
 * dan bedanya "dikosongkan" (kembali ikut rata-rata Fixed Cost) dengan "diisi nol"
 * (produk ini memang tidak butuh biaya packing) — dua hal yang mudah tertukar.
 */
class ProductPackingCostTest extends TestCase
{
    use RefreshDatabase;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->productId = DB::table('products')->insertGetId([
            'sku'        => 'TEST-1',
            'name'       => 'Produk Uji',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_menyimpan_biaya_packing_dengan_format_ribuan_indonesia(): void
    {
        $this->save(['amount_per_unit' => '15.000', 'notes' => 'peti kayu'])->assertRedirect();

        $row = ProductPackingCost::where('product_id', $this->productId)->firstOrFail();

        // (float) '15.000' di PHP = 15.0 — inilah yang harus dicegah clean_number().
        $this->assertEqualsWithDelta(15_000, (float) $row->amount_per_unit, 0.01);
        $this->assertSame('peti kayu', $row->notes);
    }

    public function test_dikosongkan_berarti_kembali_ikut_fixed_cost(): void
    {
        ProductPackingCost::create(['product_id' => $this->productId, 'amount_per_unit' => 5_000]);

        $this->save(['amount_per_unit' => ''])->assertRedirect();

        $this->assertDatabaseMissing('production_product_packing_costs', ['product_id' => $this->productId]);
    }

    public function test_nol_disimpan_sebagai_nol_bukan_dihapus(): void
    {
        // Produk yang dikirim tanpa dus sama sekali harus bisa dinyatakan nol —
        // kalau nol ikut terhapus, angkanya diam-diam kembali ke rata-rata.
        $this->save(['amount_per_unit' => '0'])->assertRedirect();

        $row = ProductPackingCost::where('product_id', $this->productId)->firstOrFail();

        $this->assertEqualsWithDelta(0, (float) $row->amount_per_unit, 0.01);
    }

    public function test_produk_tidak_dikenal_ditolak(): void
    {
        $this->save(['amount_per_unit' => '1.000'], 999999)->assertNotFound();
    }

    private function save(array $payload, ?int $productId = null)
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        return $this->actingAs($user)->post(
            route('analisa.hpp.packing-cost.save', $productId ?? $this->productId),
            $payload,
        );
    }
}
