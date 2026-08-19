<?php

namespace Tests\Feature\Shipping;

use App\Core\Inventory\BundleComponent;
use App\Core\Inventory\Product;
use App\Modules\Shipping\Services\PackageDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Berat & dimensi paket untuk produk bundle.
 *
 * Bundle Noud bukan sekadar gabungan barang — ia produk yang SUDAH DIKEMAS
 * (bubble, peti kayu). Beratnya karena itu lebih besar daripada jumlah isinya,
 * dan komponen kemasannya kerap tak punya berat tercatat sama sekali.
 *
 * Dulu form Pengiriman memecah bundle jadi komponen, sehingga berat kemasan
 * menguap dan ongkir kurang tagih — 89 dari 142 bundle terdampak, rata-rata 837
 * gram. Uji ini menjaga agar aturannya tidak diam-diam kembali ke sana.
 */
class BundlePackageWeightTest extends TestCase
{
    use RefreshDatabase;

    private function produk(array $attrs): Product
    {
        static $n = 0;
        $n++;

        return Product::create(array_merge([
            'sku'         => 'UJI-' . $n . '-' . uniqid(),
            'name'        => 'Produk Uji ' . $n,
            'sale_type'   => 'ready',
            'is_active'   => true,
        ], $attrs));
    }

    /** Bundle = produk dasar + kemasan yang beratnya tidak tercatat. */
    private function bundleTerkemas(?int $beratBundle): array
    {
        $dasar   = $this->produk(['name' => 'Frame Mahar 30x30x6', 'weight_gram' => 1730]);
        $kemasan = $this->produk(['name' => 'Bubble Wrap Tebal', 'weight_gram' => null]);

        $bundle = $this->produk([
            'name'        => 'Frame Mahar 30x30x6 Extra Buble',
            'sale_type'   => 'bundle',
            'weight_gram' => $beratBundle,
            'length_cm'   => 37,
            'width_cm'    => 13,
            'height_cm'   => 43,
        ]);

        foreach ([$dasar, $kemasan] as $c) {
            BundleComponent::create([
                'bundle_product_id'    => $bundle->id,
                'component_product_id' => $c->id,
                'qty'                  => 1,
            ]);
        }

        return [$bundle, $dasar];
    }

    private function taksir(Product $p, float $qty = 1): array
    {
        return app(PackageDefaults::class)->for(null, [
            (object) ['product' => $p, 'qty' => $qty, 'conversion_to_base' => 1],
        ]);
    }

    public function test_bundle_pakai_berat_sendiri_bukan_jumlah_komponen(): void
    {
        [$bundle] = $this->bundleTerkemas(3900);

        // Menjumlahkan komponen hanya menghasilkan 1730 g — berat kemasannya hilang,
        // dan selisih 2170 g itu ongkir yang tidak tertagih ke pembeli.
        $this->assertSame(3900, $this->taksir($bundle)['weight_gram']);
    }

    public function test_berat_bundle_dikali_jumlah_pesanan(): void
    {
        [$bundle] = $this->bundleTerkemas(3900);

        $this->assertSame(11700, $this->taksir($bundle, 3)['weight_gram']);
    }

    public function test_bundle_belum_ditimbang_jatuh_ke_komponen_bukan_nol(): void
    {
        [$bundle] = $this->bundleTerkemas(null);

        // Taksiran yang merendahkan masih jauh lebih baik daripada 0 gram, yang
        // membuat Jubelio memakai lantai minimumnya dan ongkirnya makin melenceng.
        $this->assertSame(1730, $this->taksir($bundle)['weight_gram']);
    }

    public function test_dimensi_bundle_ikut_terbawa(): void
    {
        [$bundle] = $this->bundleTerkemas(3900);
        $hasil = $this->taksir($bundle);

        // Tanpa ini dimensi terkirim 0 ke Jubelio, padahal untuk sebagian barang
        // akrilik berat volumetrik justru yang lebih besar dan itulah yang ditagih.
        $this->assertSame(37.0, (float) $hasil['length']);
        $this->assertSame(13.0, (float) $hasil['width']);
        $this->assertSame(43.0, (float) $hasil['height']);
        $this->assertTrue($hasil['estimated_dimensions'], 'harus ditandai taksiran supaya operator mengoreksi');
    }

    public function test_produk_biasa_tidak_ikut_terpengaruh(): void
    {
        $biasa = $this->produk(['weight_gram' => 500, 'length_cm' => 20, 'width_cm' => 10, 'height_cm' => 5]);

        $this->assertSame(1000, $this->taksir($biasa, 2)['weight_gram']);
    }

    public function test_jasa_tidak_menambah_berat_maupun_dimensi(): void
    {
        $jasa = $this->produk(['sale_type' => 'service', 'weight_gram' => 9999, 'length_cm' => 99]);

        $hasil = $this->taksir($jasa);
        $this->assertSame(0, (int) $hasil['weight_gram']);
        $this->assertNull($hasil['length']);
    }

    /**
     * Kontrak yang dipakai cek ongkir ETALASE. Etalase mengirim tiap produk
     * sebagai baris terpisah, jadi ia butuh berat per satuan — bukan total paket
     * — tapi aturannya harus sama persis dengan form Pengiriman di ERP.
     */
    public function test_berat_satuan_dipakai_bersama_etalase(): void
    {
        [$bundleDitimbang] = $this->bundleTerkemas(3900);
        [$bundleBelum]     = $this->bundleTerkemas(null);
        $biasa             = $this->produk(['weight_gram' => 500]);

        $svc = app(PackageDefaults::class);

        $this->assertSame(3900, $svc->beratSatuan($bundleDitimbang));
        $this->assertSame(1730, $svc->beratSatuan($bundleBelum));
        $this->assertSame(500, $svc->beratSatuan($biasa));
    }

    public function test_berat_tersimpan_di_dokumen_menang_atas_taksiran(): void
    {
        [$bundle] = $this->bundleTerkemas(3900);

        // Hasil timbang sungguhan di sub-tab "Perlu Ukur" tidak boleh ditawar
        // ulang oleh taksiran master produk.
        $doc = (object) [
            'package_weight_gram' => 4250,
            'package_length'      => null,
            'package_width'       => null,
            'package_height'      => null,
            'items'               => [(object) ['product' => $bundle, 'qty' => 1, 'conversion_to_base' => 1]],
        ];

        $hasil = app(PackageDefaults::class)->for($doc);
        $this->assertSame(4250, $hasil['weight_gram']);
        $this->assertFalse($hasil['estimated_weight']);
    }
}
