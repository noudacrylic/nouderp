<?php

namespace Tests\Feature\POS;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Modules\POS\Services\FulfillmentReadinessService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gerbang "Perlu Ukur": pesanan kurir non-marketplace ditahan sampai kardusnya ditimbang
 * & diukur, supaya resi terbit dengan ongkir yang benar.
 *
 * Ukurannya menempel di SO (bukan Surat Jalan) — keputusan pemilik: pengukuran dikerjakan
 * setelah barang dipacking tapi SEBELUM pesanan diproses, jadi Surat Jalan belum ada.
 *
 * Sekalian menguji "Belum Siap" yang kini juga menampung pesanan ready-stock yang stok
 * fisiknya belum cukup, bukan cuma yang menunggu produksi.
 */
class FulfillmentPerluUkurTest extends TestCase
{
    use RefreshDatabase;

    private function warehouseId(): int
    {
        return Warehouse::firstOrCreate(['name' => 'Gudang Test'], ['is_sellable' => true])->id;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    /** SO lunas berisi 1 produk ready stock, dengan stok gudang secukupnya. */
    private function so(array $attrs = [], float $stok = 10): SalesOrder
    {
        $cust = Customer::create([
            'code' => 'CUST-' . uniqid(), 'name' => 'Toko Budi', 'is_marketplace' => false, 'is_active' => true,
        ]);

        $so = SalesOrder::create(array_merge([
            'order_number'         => 'SO-UK-' . uniqid(),
            'customer_id'          => $cust->id,
            'warehouse_id'         => $this->warehouseId(),
            'order_date'           => now()->toDateString(),
            'global_discount_type' => 'nominal',
            'status'               => 'confirmed',
            'delivery_method'      => 'kurir',
            'grand_total'          => 100000,
            'paid_amount'          => 100000,
        ], $attrs));

        $product = Product::create([
            'sku' => 'RDY-' . uniqid(), 'name' => 'Frame Akrilik',
            'sale_type' => 'ready', 'weight_gram' => 800,
            'length_cm' => 30, 'width_cm' => 20, 'height_cm' => 5,
        ]);

        ProductStock::create([
            'product_id' => $product->id, 'warehouse_id' => $so->warehouse_id, 'qty_on_hand' => $stok,
        ]);

        SalesOrderItem::create([
            'sales_order_id'     => $so->id,
            'product_id'         => $product->id,
            'qty'                => 2,
            'conversion_to_base' => 1,
            'unit_price'         => 50000,
            'net_unit_price'     => 50000,
            'line_subtotal'      => 100000,
            'line_discount'      => 0,
            'line_total'         => 100000,
        ]);

        return $so->refresh();
    }

    /** Service di-forget dulu: memoized per-request, kalau tidak yang terbaca klasifikasi lama. */
    private function bucketOf(SalesOrder $so): ?string
    {
        app()->forgetInstance(FulfillmentReadinessService::class);
        $svc = app(FulfillmentReadinessService::class);

        foreach (['belum_bayar', 'belum_siap', 'belum_lunas', 'perlu_ukur', 'perlu_diproses'] as $bucket) {
            if ($svc->bucket($bucket)->firstWhere('id', $so->id)) {
                return $bucket;
            }
        }

        return null;
    }

    public function test_pesanan_kurir_lunas_tertahan_di_perlu_ukur(): void
    {
        $so = $this->so();

        $this->assertSame('perlu_ukur', $this->bucketOf($so));
    }

    public function test_ambil_di_toko_lompati_gerbang_ukur(): void
    {
        // Tak ada resi yang diterbitkan → tak ada gunanya menimbang.
        $so = $this->so(['delivery_method' => 'ambil_toko']);

        $this->assertSame('perlu_diproses', $this->bucketOf($so));
    }

    public function test_form_ukur_terisi_taksiran_dari_master_produk(): void
    {
        $so = $this->so();

        $row = app(FulfillmentReadinessService::class)->bucket('perlu_ukur')->firstWhere('id', $so->id);

        // 2 × 800 g; dimensi = terbesar per sumbu, bukan dijumlah.
        $this->assertSame(1600, $row['measure']['weight_gram']);
        $this->assertEquals(30.0, $row['measure']['length']);
        $this->assertEquals(20.0, $row['measure']['width']);
        $this->assertEquals(5.0, $row['measure']['height']);
    }

    public function test_simpan_ukuran_memindahkan_ke_siap_proses(): void
    {
        $so = $this->so();

        $this->actingAs($this->admin())
            ->post(route('pos.fulfillment.ukur', $so->id), [
                'weight_gram'    => 2500,
                'package_length' => 40,
                'package_width'  => 30,
                'package_height' => 15,
            ])
            ->assertRedirect();

        $so->refresh();
        $this->assertSame(2500, (int) $so->package_weight_gram);
        $this->assertEquals(40.0, (float) $so->package_length);
        $this->assertNotNull($so->measured_at);

        $this->assertSame('perlu_diproses', $this->bucketOf($so));
    }

    /** Operator yang menilai taksirannya sudah benar cukup menekan tombolnya. */
    public function test_simpan_tanpa_mengubah_angka_tetap_dianggap_diukur(): void
    {
        $so = $this->so();

        $this->actingAs($this->admin())
            ->post(route('pos.fulfillment.ukur', $so->id), [])
            ->assertRedirect();

        $this->assertNotNull($so->refresh()->measured_at);
        $this->assertSame('perlu_diproses', $this->bucketOf($so));
    }

    public function test_ukur_ulang_mengembalikan_ke_perlu_ukur(): void
    {
        $so = $this->so(['measured_at' => now(), 'package_weight_gram' => 2000]);
        $this->assertSame('perlu_diproses', $this->bucketOf($so));

        $this->actingAs($this->admin())
            ->post(route('pos.fulfillment.batal-ukur', $so->id))
            ->assertRedirect();

        $this->assertNull($so->refresh()->measured_at);
        $this->assertSame('perlu_ukur', $this->bucketOf($so));
    }

    public function test_hasil_ukur_dipakai_saat_booking_resi(): void
    {
        $so = $this->so(['measured_at' => now(), 'package_weight_gram' => 3200]);

        // Bukan memanggil API: cukup pastikan berat hasil timbang yang dibaca, bukan
        // penjumlahan berat produk (2 × 800 = 1600 g).
        $this->assertSame(3200, (int) $so->package_weight_gram);
        $this->assertNotSame(1600, (int) $so->package_weight_gram);
    }

    // ───────────── Belum Siap: stok fisik belum cukup ─────────────

    public function test_stok_kurang_masuk_belum_siap_dengan_sku_nya(): void
    {
        $so = $this->so(stok: 1);   // butuh 2, ada 1

        $row = app(FulfillmentReadinessService::class)->bucket('belum_siap')->firstWhere('id', $so->id);

        $this->assertNotNull($row, 'SO yang stoknya kurang harus tertahan di Belum Siap');
        $this->assertStringContainsString('Stok belum cukup', $row['reason']);
        $this->assertStringContainsString($so->items->first()->product->sku, $row['reason']);
    }

    public function test_kesepakatan_keep_stock_membebaskan_pesanan(): void
    {
        // allow_backorder = sudah disepakati boleh jalan walau stok kurang.
        $so = $this->so(['allow_backorder' => true], stok: 0);

        $this->assertSame('perlu_ukur', $this->bucketOf($so), 'Keep stock tidak boleh tertahan di Belum Siap');
    }

    public function test_halaman_sub_tab_perlu_ukur_terbuka(): void
    {
        $so = $this->so();

        $this->actingAs($this->admin())
            ->get(route('pos.fulfillment.perlu-diproses', ['tahap' => 'perlu-ukur']))
            ->assertOk()
            ->assertSee($so->order_number)
            ->assertSee('Simpan Ukuran');
    }
}
