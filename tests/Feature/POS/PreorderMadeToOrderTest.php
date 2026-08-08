<?php

namespace Tests\Feature\POS;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\StockReservation;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Modules\POS\Services\FulfillmentReadinessService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tipe `preorder` menampung dua watak yang selama ini tidak dibedakan:
 *
 *  - berspesifikasi tetap (Box Charger dll) — satu unit bisa menggantikan unit lain;
 *  - dibuat mengikuti permintaan pembeli (CS1, CS2) — SKU cuma wadah, unit di bawahnya
 *    bisa berbeda barang, HPP-nya menempel pada order produksinya sendiri.
 *
 * Karena disamakan, ERP memperlakukan semuanya seperti kelompok kedua: kesiapan dinilai dari
 * "OP milik SO ini sudah finalized?", bukan dari ada-tidaknya barang. Akibatnya sisa produksi
 * pesanan yang batal tak pernah dianggap ada, pesanan baru ditahan sampai OP barunya jadi, dan
 * unit lamanya mengendap jadi deadstock.
 *
 * Penanda `made_to_order` yang memisahkan keduanya.
 */
class PreorderMadeToOrderTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::firstOrCreate(['name' => 'Gudang Test'], ['is_sellable' => true]);
    }

    private function produk(bool $dibuatKhusus): Product
    {
        return Product::create([
            'sku'            => ($dibuatKhusus ? 'CS-' : 'BC-') . uniqid(),
            'name'           => $dibuatKhusus ? 'Produk Custom' : 'Box Charger 2 Kotak',
            'sale_type'      => 'preorder',
            'made_to_order'  => $dibuatKhusus,
            'preorder_stock' => 100,
            'lead_time_days' => 7,
        ]);
    }

    /** SO lunas berisi 1 produk preorder. */
    private function so(Product $p, float $qty = 1, array $attrs = []): SalesOrder
    {
        $cust = Customer::create([
            'code' => 'CUST-' . uniqid(), 'name' => 'Toko Budi', 'is_marketplace' => false, 'is_active' => true,
        ]);

        $so = SalesOrder::create(array_merge([
            'order_number'         => 'SO-MTO-' . uniqid(),
            'customer_id'          => $cust->id,
            'warehouse_id'         => $this->warehouse()->id,
            'order_date'           => now()->toDateString(),
            'global_discount_type' => 'nominal',
            'status'               => 'confirmed',
            'delivery_method'      => 'kurir',
            'grand_total'          => 100000,
            'paid_amount'          => 100000,
            'measured_at'          => now(),
            'package_weight_gram'  => 1000,
        ], $attrs));

        SalesOrderItem::create([
            'sales_order_id'     => $so->id,
            'product_id'         => $p->id,
            'qty'                => $qty,
            'conversion_to_base' => 1,
            'unit_price'         => 100000,
            'net_unit_price'     => 100000,
            'line_subtotal'      => 100000,
            'line_discount'      => 0,
            'line_total'         => 100000,
        ]);

        // SO confirmed selalu mereservasi stok — reservasi inilah yang jadi "permintaan".
        StockReservation::create([
            'product_id'     => $p->id,
            'warehouse_id'   => $so->warehouse_id,
            'sales_order_id' => $so->id,
            'qty'            => $qty,
            'status'         => 'active',
        ]);

        return $so->refresh();
    }

    private function stok(Product $p, float $qty): void
    {
        ProductStock::updateOrCreate(
            ['product_id' => $p->id, 'warehouse_id' => $this->warehouse()->id],
            ['qty_on_hand' => $qty]
        );
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

    // ───────────── Produk berspesifikasi tetap: kesiapan dari stok ─────────────

    /** Inti masalahnya: unit sisa pesanan yang batal harus menutup pesanan berikutnya. */
    public function test_stok_yang_ada_membuat_pesanan_langsung_siap_tanpa_op(): void
    {
        $p  = $this->produk(dibuatKhusus: false);
        $so = $this->so($p);
        $this->stok($p, 1);

        $this->assertSame('perlu_diproses', $this->bucketOf($so),
            'Barangnya ada di rak — tidak ada gunanya menunggu order produksi.');
    }

    public function test_tanpa_stok_tetap_tertahan_di_belum_siap(): void
    {
        $p  = $this->produk(dibuatKhusus: false);
        $so = $this->so($p);
        $this->stok($p, 0);

        $this->assertSame('belum_siap', $this->bucketOf($so));
    }

    public function test_stok_hanya_menutup_sebagian_tetap_tertahan(): void
    {
        $p  = $this->produk(dibuatKhusus: false);
        $so = $this->so($p, qty: 3);
        $this->stok($p, 1);

        $this->assertSame('belum_siap', $this->bucketOf($so));
    }

    /** Kuota preorder itu izin MENJUAL, bukan barang — tidak boleh melepas pesanan. */
    public function test_kuota_preorder_tidak_membuat_pesanan_dianggap_siap(): void
    {
        $p = $this->produk(dibuatKhusus: false);
        $p->update(['preorder_stock' => 500]);
        $so = $this->so($p);
        $this->stok($p, 0);

        $this->assertSame('belum_siap', $this->bucketOf($so),
            'Kuota jual tidak bisa dimasukkan kardus.');
    }

    // ───────────── Produk dibuat khusus: perilaku lama dipertahankan ─────────────

    /** Stok SKU CS tidak berarti apa-apa — unit di bawahnya bisa barang orang lain. */
    public function test_produk_dibuat_khusus_tidak_dilepas_oleh_stok(): void
    {
        $p  = $this->produk(dibuatKhusus: true);
        $so = $this->so($p);
        $this->stok($p, 5);

        $this->assertSame('belum_siap', $this->bucketOf($so),
            'Kesiapan produk custom dinilai dari order produksinya sendiri, bukan angka stok.');
    }

    // ───────────── Alokasi tertua duluan ─────────────

    public function test_satu_unit_diperebutkan_dua_pesanan_jatuh_ke_yang_tertua(): void
    {
        $p    = $this->produk(dibuatKhusus: false);
        $tua  = $this->so($p);
        $muda = $this->so($p);
        $this->stok($p, 1);

        $this->assertSame('perlu_diproses', $this->bucketOf($tua),
            'Pesanan tertua berhak atas unit yang ada.');
        $this->assertSame('belum_siap', $this->bucketOf($muda),
            'Pesanan berikutnya menunggu — bukan ikut dilepas.');
    }

    // ───────────── Pembebasan gerbang produksi (waiver) ─────────────

    public function test_tombol_bebaskan_produksi_melepas_pesanan_custom(): void
    {
        $p  = $this->produk(dibuatKhusus: true);
        $so = $this->so($p);
        $this->stok($p, 1); // barangnya ada, tapi tak pernah lewat OP di ERP

        $this->assertSame('belum_siap', $this->bucketOf($so));

        $this->actingAs($this->admin())
            ->post(route('pos.fulfillment.bebaskan-produksi', $so->id), ['reason' => 'Sisa order batal'])
            ->assertRedirect();

        $so->refresh();
        $this->assertNotNull($so->production_waived_at);
        $this->assertSame('Sisa order batal', $so->production_waived_reason);
        $this->assertSame('perlu_diproses', $this->bucketOf($so));
    }

    public function test_bebaskan_produksi_wajib_beralasan(): void
    {
        $p  = $this->produk(dibuatKhusus: true);
        $so = $this->so($p);

        $this->actingAs($this->admin())
            ->post(route('pos.fulfillment.bebaskan-produksi', $so->id), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertNull($so->refresh()->production_waived_at);
    }

    /** Waiver hanya melepas gerbang PRODUKSI — barang yang tidak ada tetap menahan. */
    public function test_waiver_tidak_melepas_gerbang_stok(): void
    {
        $p  = $this->produk(dibuatKhusus: false);
        $so = $this->so($p);
        $this->stok($p, 0);

        $so->update(['production_waived_at' => now(), 'production_waived_reason' => 'coba-coba']);

        $this->assertSame('belum_siap', $this->bucketOf($so),
            'Membebaskan pesanan tidak menciptakan barang.');
    }

    public function test_pembebasan_bisa_ditarik_kembali(): void
    {
        $p  = $this->produk(dibuatKhusus: true);
        $so = $this->so($p);
        $so->update(['production_waived_at' => now(), 'production_waived_reason' => 'sisa order batal']);

        $this->actingAs($this->admin())
            ->post(route('pos.fulfillment.batal-bebas', $so->id))
            ->assertRedirect();

        $this->assertNull($so->refresh()->production_waived_at);
        $this->assertSame('belum_siap', $this->bucketOf($so));
    }

    // ───────────── Penanda di daftar produk ─────────────

    public function test_toggle_penanda_dari_daftar_produk(): void
    {
        $p = $this->produk(dibuatKhusus: true);

        $this->actingAs($this->admin())
            ->postJson(route('products.updateMadeToOrder'), ['product_id' => $p->id, 'made_to_order' => 0])
            ->assertOk()
            ->assertJson(['success' => true, 'made_to_order' => false]);

        $this->assertFalse((bool) $p->refresh()->made_to_order);
    }

    /** Bawaannya menyala: produk baru mulai dari sisi yang aman. */
    public function test_produk_baru_bawaannya_dibuat_khusus(): void
    {
        $p = Product::create(['sku' => 'X-' . uniqid(), 'name' => 'Baru', 'sale_type' => 'preorder']);

        $this->assertTrue((bool) $p->refresh()->made_to_order);
    }
}
