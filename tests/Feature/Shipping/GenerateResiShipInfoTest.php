<?php

namespace Tests\Feature\Shipping;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesDeliveryItem;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Popup "Generate Resi" di Telah Diproses mengambil nilai awalnya dari endpoint ship-info,
 * lalu MENGIRIM BALIK nilai itu sebagai override berat/dimensi saat resi dipesan.
 *
 * Karena itu dua hal harus benar di sini, kalau tidak fitur "Perlu Ukur" jadi sia-sia:
 *  1. beratnya = hasil timbang kardus, bukan penjumlahan berat master produk;
 *  2. tujuannya bisa dipakai cek ongkir provider yang sedang aktif — `area` di jawabannya
 *     milik Biteship dan selalu kosong untuk pesanan Jubelio.
 */
class GenerateResiShipInfoTest extends TestCase
{
    use RefreshDatabase;

    private function delivery(array $soAttrs = []): SalesDelivery
    {
        $cust = Customer::create([
            'code' => 'CUST-' . uniqid(), 'name' => 'Toko Budi', 'is_marketplace' => false, 'is_active' => true,
            'postal_code' => '50111', 'jubelio_area_id' => '12345', 'phone' => '08123456789',
        ]);
        $wh = Warehouse::firstOrCreate(['name' => 'Gudang Test'], ['is_sellable' => true]);

        $so = SalesOrder::create(array_merge([
            'order_number' => 'SO-SI-' . uniqid(), 'customer_id' => $cust->id, 'warehouse_id' => $wh->id,
            'order_date' => now()->toDateString(), 'global_discount_type' => 'nominal',
            'status' => 'confirmed', 'grand_total' => 100000, 'paid_amount' => 100000,
            'delivery_method' => 'kurir', 'shipping_provider' => 'jubelio_shipment',
            'shipping_courier_code' => '17', 'shipping_service_code' => '99',
            'shipping_service_name' => 'J&T Cargo',
        ], $soAttrs));

        $product = Product::create([
            'sku' => 'RDY-' . uniqid(), 'name' => 'Frame Akrilik',
            'sale_type' => 'ready', 'weight_gram' => 800,
        ]);

        $sj = SalesDelivery::create([
            'delivery_number' => 'SJ-SI-' . uniqid(),
            'sales_order_id'  => $so->id,
            'warehouse_id'    => $wh->id,
            'delivery_method' => 'kurir',
            'delivery_date'   => now()->toDateString(),
            'status'          => 'posted',
        ]);

        SalesDeliveryItem::create([
            'sales_delivery_id' => $sj->id,
            'product_id'        => $product->id,
            'qty'               => 2,
        ]);

        return $sj;
    }

    public function test_berat_bawaan_memakai_hasil_timbang_bukan_taksiran_produk(): void
    {
        $sj = $this->delivery([
            'package_weight_gram' => 3200, 'measured_at' => now(),
            'package_length' => 40, 'package_width' => 30, 'package_height' => 15,
        ]);

        $res = $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->getJson(route('sales.deliveries.ship-info', $sj->id))
            ->assertOk();

        // 2 × 800 g = 1600 g taksiran produk — yang benar hasil timbang 3200 g.
        $res->assertJsonPath('weight_gram', 3200);
        $res->assertJsonPath('package_length', 40);
    }

    public function test_tanpa_hasil_timbang_jatuh_ke_taksiran_produk(): void
    {
        $sj = $this->delivery();

        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->getJson(route('sales.deliveries.ship-info', $sj->id))
            ->assertOk()
            ->assertJsonPath('weight_gram', 1600);
    }

    public function test_membawa_customer_id_supaya_cek_ongkir_jalan_untuk_jubelio(): void
    {
        $sj = $this->delivery();

        $res = $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->getJson(route('sales.deliveries.ship-info', $sj->id))
            ->assertOk();

        // Pesanan Jubelio tidak punya area Biteship — tanpa customer_id popup mentok
        // di "butuh area customer" dan tarifnya tak pernah muncul.
        $res->assertJsonPath('area', null);
        $res->assertJsonPath('customer_id', $sj->order->customer_id);
        $res->assertJsonPath('provider', 'jubelio_shipment');
    }

    public function test_label_kurir_jubelio_pakai_nama_layanan_bukan_id_angka(): void
    {
        $sj = $this->delivery();

        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->getJson(route('sales.deliveries.ship-info', $sj->id))
            ->assertOk()
            ->assertJsonPath('courier_label', 'J&T Cargo');
    }
}
