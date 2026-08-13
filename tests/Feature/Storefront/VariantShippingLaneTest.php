<?php

namespace Tests\Feature\Storefront;

use App\Core\Inventory\Product;
use App\Models\StoreProduct;
use App\Models\StorefrontSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Jalur pengiriman per varian (box mahar): varian berpacking bubble tipis tidak boleh
 * lewat ekspedisi, varian berpacking kayu memang untuk dikirim.
 *
 * Etalase mematikan metode pengiriman berdasarkan penanda ini, jadi yang dijaga di sini
 * adalah SAMPAINYA penanda itu ke keranjang — termasuk untuk barang yang sudah lama
 * tersimpan di localStorage pembeli, yang penilaiannya hanya bisa lewat kuotasi.
 */
class VariantShippingLaneTest extends TestCase
{
    use RefreshDatabase;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->key = StorefrontSetting::generateKey();
        StorefrontSetting::singleton()->update(['is_active' => true, 'api_key' => $this->key]);
    }

    private function sku(string $sku): Product
    {
        return Product::create([
            'sku'         => $sku,
            'name'        => 'Uji ' . $sku,
            'sale_type'   => 'ready',
            'base_unit'   => 'pcs',
            'is_active'   => true,
            'is_sellable' => true,
        ]);
    }

    private function api()
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->key, 'Accept' => 'application/json']);
    }

    public function test_kuotasi_membawa_jalur_pengiriman_tiap_varian(): void
    {
        $bubble = $this->sku('UJI-MAHAR-BUBBLE');
        $kayu   = $this->sku('UJI-MAHAR-KAYU');

        $store = StoreProduct::create([
            'slug'         => 'box-mahar-uji',
            'name'         => 'Box Mahar Uji',
            'status'       => 'published',
            'variant_axes' => [['name' => 'Packing', 'options' => ['Tanpa Kardus', 'Packing kayu']]],
        ]);
        $store->variants()->create([
            'product_id'    => $bubble->id,
            'variant_label' => 'Tanpa Kardus',
            'option_values' => ['Tanpa Kardus'],
            'allow_courier' => false,   // bubble tipis: pecah bila lewat ekspedisi
            'allow_pickup'  => true,
            'is_default'    => true,
            'sort_order'    => 0,
        ]);
        $store->variants()->create([
            'product_id'    => $kayu->id,
            'variant_label' => 'Packing kayu',
            'option_values' => ['Packing kayu'],
            'allow_courier' => true,
            'allow_pickup'  => false,   // dikemas khusus untuk dikirim
            'is_default'    => false,
            'sort_order'    => 1,
        ]);

        $res = $this->api()->postJson('/api/storefront/cart/quote', ['items' => [
            ['product_id' => $bubble->id, 'qty' => 1],
            ['product_id' => $kayu->id, 'qty' => 1],
        ]]);

        $res->assertOk();
        $lines = collect($res->json('data.items'))->keyBy('product_id');

        $this->assertFalse($lines[$bubble->id]['allow_courier']);
        $this->assertTrue($lines[$bubble->id]['allow_pickup']);
        $this->assertTrue($lines[$kayu->id]['allow_courier']);
        $this->assertFalse($lines[$kayu->id]['allow_pickup']);
    }

    public function test_sku_di_luar_etalase_dianggap_melayani_semua_jalur(): void
    {
        $lepas = $this->sku('UJI-TANPA-ETALASE');

        $res = $this->api()->postJson('/api/storefront/cart/quote', [
            'items' => [['product_id' => $lepas->id, 'qty' => 2]],
        ]);

        $res->assertOk();
        $this->assertTrue($res->json('data.items.0.allow_courier'));
        $this->assertTrue($res->json('data.items.0.allow_pickup'));
    }

    public function test_payload_produk_menyertakan_jalur_varian(): void
    {
        $bubble = $this->sku('UJI-PAYLOAD-BUBBLE');

        $store = StoreProduct::create([
            'slug'         => 'box-mahar-payload',
            'name'         => 'Box Mahar Payload',
            'status'       => 'published',
            'variant_axes' => [['name' => 'Packing', 'options' => ['Tanpa Kardus']]],
        ]);
        $store->variants()->create([
            'product_id'    => $bubble->id,
            'variant_label' => 'Tanpa Kardus',
            'option_values' => ['Tanpa Kardus'],
            'allow_courier' => false,
            'allow_pickup'  => true,
            'is_default'    => true,
            'sort_order'    => 0,
        ]);

        $res = $this->api()->getJson('/api/storefront/products/box-mahar-payload');

        $res->assertOk();
        $this->assertFalse($res->json('data.variants.0.allow_courier'));
        $this->assertTrue($res->json('data.variants.0.allow_pickup'));
    }

    public function test_editor_menyimpan_centang_jalur_dan_menolak_varian_tanpa_jalur(): void
    {
        $bubble = $this->sku('UJI-EDITOR-BUBBLE');
        $kayu   = $this->sku('UJI-EDITOR-KAYU');

        $store = StoreProduct::create(['slug' => 'editor-uji', 'name' => 'Editor Uji', 'status' => 'draft']);
        $admin = \App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $payload = fn (array $laneKayu) => [
            'name'         => 'Editor Uji',
            'variant_mode' => 'variant',
            'axes'         => [['name' => 'Packing', 'options' => ['Tanpa Kardus', 'Packing kayu']]],
            'variants'     => [
                ['product_id' => $bubble->id, 'options' => ['Tanpa Kardus'], 'allow_courier' => 0, 'allow_pickup' => 1],
                array_merge(['product_id' => $kayu->id, 'options' => ['Packing kayu']], $laneKayu),
            ],
            'default_index' => 0,
        ];

        $this->actingAs($admin)
            ->put(route('store.products.update', $store->id), $payload(['allow_courier' => 1, 'allow_pickup' => 0]))
            ->assertSessionHasNoErrors();

        $saved = $store->fresh()->variants->keyBy('product_id');
        $this->assertFalse((bool) $saved[$bubble->id]->allow_courier);
        $this->assertTrue((bool) $saved[$bubble->id]->allow_pickup);
        $this->assertTrue((bool) $saved[$kayu->id]->allow_courier);
        $this->assertFalse((bool) $saved[$kayu->id]->allow_pickup);

        // Dua-duanya mati = varian yang tidak akan pernah bisa dibeli.
        $this->actingAs($admin)
            ->put(route('store.products.update', $store->id), $payload(['allow_courier' => 0, 'allow_pickup' => 0]))
            ->assertSessionHasErrors('variants');
    }

    public function test_varian_baru_bawaannya_melayani_semua_jalur(): void
    {
        $p = $this->sku('UJI-BAWAAN');

        $store = StoreProduct::create(['slug' => 'produk-bawaan', 'name' => 'Produk Bawaan', 'status' => 'published']);
        $variant = $store->variants()->create([
            'product_id' => $p->id,
            'is_default' => true,
            'sort_order' => 0,
        ]);

        $this->assertTrue((bool) $variant->fresh()->allow_courier);
        $this->assertTrue((bool) $variant->fresh()->allow_pickup);
    }
}
