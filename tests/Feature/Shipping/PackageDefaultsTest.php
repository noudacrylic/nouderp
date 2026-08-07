<?php

namespace Tests\Feature\Shipping;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Shipping\Services\PackageDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Berat & dimensi bawaan di form Pengiriman.
 *
 * Dulu kolom berat di kartu "Ubah Kurir / Ongkir" selalu berisi 1000 gram: angka mati di HTML.
 * Hitung-ulang otomatisnya membaca baris item dari FORM, yang tidak ada di halaman lihat SO —
 * jadi di sana angkanya tak pernah berubah dari 1 kg, sementara hasil timbang yang tersimpan
 * di SO tidak pernah ditampilkan. Ongkir pun dicek dengan berat karangan.
 */
class PackageDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private function so(array $attrs = [], array $items = []): SalesOrder
    {
        $cust = Customer::create([
            'code' => 'CUST-' . uniqid(), 'name' => 'Toko Budi', 'is_marketplace' => false, 'is_active' => true,
        ]);
        $wh = Warehouse::firstOrCreate(['name' => 'Gudang Test']);

        $so = SalesOrder::create(array_merge([
            'order_number'         => 'SO-PKG-' . uniqid(),
            'customer_id'          => $cust->id,
            'warehouse_id'         => $wh->id,
            'order_date'           => now()->toDateString(),
            'global_discount_type' => 'nominal',
            'status'               => 'confirmed',
            'grand_total'          => 100000,
            'paid_amount'          => 100000,
            'delivery_method'      => 'kurir',
        ], $attrs));

        foreach ($items as $it) {
            $p = Product::create([
                'sku' => 'PKG-' . uniqid(), 'name' => $it['name'] ?? 'Barang', 'sale_type' => $it['sale_type'] ?? 'ready',
                'weight_gram' => $it['weight'] ?? 0,
                'length_cm' => $it['l'] ?? 0, 'width_cm' => $it['w'] ?? 0, 'height_cm' => $it['h'] ?? 0,
            ]);
            SalesOrderItem::create([
                'sales_order_id' => $so->id, 'product_id' => $p->id, 'qty' => $it['qty'] ?? 1,
                'conversion_to_base' => 1, 'unit_price' => 1000, 'net_unit_price' => 1000,
                'line_subtotal' => 1000, 'line_discount' => 0, 'line_total' => 1000,
            ]);
        }

        return $so->refresh()->load('items.product');
    }

    private function defaults(SalesOrder $so): array
    {
        return app(PackageDefaults::class)->for($so);
    }

    public function test_memakai_hasil_timbang_yang_tersimpan_di_so(): void
    {
        $so = $this->so(
            ['package_weight_gram' => 3200, 'package_length' => 40, 'package_width' => 30, 'package_height' => 15],
            [['weight' => 800, 'qty' => 2, 'l' => 10, 'w' => 10, 'h' => 10]],
        );

        $d = $this->defaults($so);

        $this->assertSame(3200, $d['weight_gram'], 'hasil timbang tidak boleh kalah dari taksiran produk');
        $this->assertEquals([40.0, 30.0, 15.0], [$d['length'], $d['width'], $d['height']]);
        $this->assertFalse($d['estimated_weight']);
    }

    public function test_tanpa_isian_so_ditaksir_dari_master_produk(): void
    {
        $so = $this->so([], [
            ['weight' => 800, 'qty' => 2, 'l' => 30, 'w' => 20, 'h' => 5],
            ['weight' => 150, 'qty' => 3, 'l' => 10, 'w' => 40, 'h' => 2],
        ]);

        $d = $this->defaults($so);

        // 800×2 + 150×3 = 2050 g — bukan 1000 g bawaan lama.
        $this->assertSame(2050, $d['weight_gram']);
        $this->assertTrue($d['estimated_weight']);
        // Dimensi diambil TERBESAR per sumbu, bukan dijumlah.
        $this->assertEquals([30.0, 40.0, 5.0], [$d['length'], $d['width'], $d['height']]);
    }

    public function test_jasa_dan_non_stok_tidak_menambah_berat(): void
    {
        $so = $this->so([], [
            ['weight' => 500, 'qty' => 1],
            ['weight' => 900, 'qty' => 1, 'sale_type' => 'service'],
            ['weight' => 900, 'qty' => 1, 'sale_type' => 'non_stock'],
        ]);

        $this->assertSame(500, $this->defaults($so)['weight_gram']);
    }

    public function test_berat_tersimpan_tetap_dipakai_walau_dimensi_masih_kosong(): void
    {
        // Kasus nyata: operator menimbang di "Perlu Ukur" tanpa mengisi dimensi.
        $so = $this->so(['package_weight_gram' => 2500], [['weight' => 100, 'qty' => 1, 'l' => 12, 'w' => 8, 'h' => 4]]);

        $d = $this->defaults($so);

        $this->assertSame(2500, $d['weight_gram']);
        $this->assertEquals([12.0, 8.0, 4.0], [$d['length'], $d['width'], $d['height']], 'dimensi boleh ditaksir');
        $this->assertTrue($d['estimated_dimensions']);
    }

    public function test_form_pengiriman_menampilkan_berat_bukan_seribu(): void
    {
        $so = $this->so([], [['weight' => 800, 'qty' => 2, 'l' => 30, 'w' => 20, 'h' => 5]]);

        $html = $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->get(route('sales.orders.show', $so->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="ship_weight" name="weight_gram" value="1600"', $html);
        $this->assertStringNotContainsString('id="ship_weight" name="weight_gram" value="1000"', $html);
    }

    public function test_berat_yang_disimpan_lewat_form_pengiriman_menempel_di_so(): void
    {
        $so = $this->so([], [['weight' => 800, 'qty' => 2]]);

        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->post(route('sales.orders.update-shipping', $so->id), [
                'shipping_gross'  => 25000,
                'weight_gram'     => 4200,
                'package_length'  => 40,
                'package_width'   => 30,
                'package_height'  => 20,
            ])
            ->assertRedirect();

        $so->refresh();
        $this->assertSame(4200, (int) $so->package_weight_gram);
        $this->assertEquals(40.0, (float) $so->package_length);
    }
}
