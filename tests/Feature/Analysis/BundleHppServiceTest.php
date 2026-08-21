<?php

namespace Tests\Feature\Analysis;

use App\Core\Inventory\BundleComponent;
use App\Core\Inventory\ProductBundle;
use App\Modules\Analysis\Models\ProductPackingCost;
use App\Modules\Analysis\Services\BundleHppService;
use App\Modules\Analysis\Services\ProductHppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * HPP bundle — dirakit dari HPP komponen, bukan dihitung ulang.
 *
 * Yang dikunci di sini adalah dua hal yang salahnya tidak akan terlihat sebagai angka aneh:
 *
 *  1. **Overhead packing masuk sekali per paket**, bukan sekali per isi. Kalau HPP komponen
 *     dijumlah apa adanya — masing-masing sudah menanggung overhead — bundle berisi tiga
 *     barang akan dibebani ongkos membungkus tiga kali, dan hasilnya tetap terlihat "wajar".
 *  2. **Komponen beli-jadi tetap terhitung.** Barang yang tidak pernah diproduksi sendiri
 *     tidak muncul di HPP Ready; kalau dilewat begitu saja, HPP bundle diam-diam kekurangan
 *     biaya sebesar isi komponen itu dan margin terbaca terlalu bagus.
 */
class BundleHppServiceTest extends TestCase
{
    use RefreshDatabase;

    private const OVERHEAD = 5_000.0;

    private int $bundleId;
    private int $dibuatId;   // komponen buatan sendiri — punya baris di HPP Ready
    private int $dibeliId;   // komponen beli-jadi — hanya punya kartu stok

    protected function setUp(): void
    {
        parent::setUp();

        $this->bundleId = $this->produk('PKT-1', 'Paket Uji', 200_000, 'bundle');
        $this->dibuatId = $this->produk('KOMP-A', 'Komponen Dibuat', 90_000);
        $this->dibeliId = $this->produk('KOMP-B', 'Komponen Dibeli', 5_000);

        // Kartu stok komponen beli-jadi: lapisan terbaru yang menang (id terbesar).
        $this->lapisan($this->dibeliId, 1_500);
        $this->lapisan($this->dibeliId, 2_000);

        $this->stubReady();
    }

    // ==========================================================
    // SUSUNAN
    // ==========================================================

    public function test_hpp_bundle_menjumlah_komponen_kali_jumlahnya(): void
    {
        $this->isi([$this->dibuatId => 2, $this->dibeliId => 3]);

        $r = $this->bundle();

        $this->assertEqualsWithDelta(26_000.0, $r['variable_cost'], 0.01, '(2 × Rp 10.000) + (3 × Rp 2.000) — komponen beli-jadi juga variable cost.');
        $this->assertEqualsWithDelta(8_000.0, $r['fixed_cost'], 0.01, '2 × Rp 4.000; komponen beli-jadi tidak menyerap jam pabrik.');
        $this->assertEqualsWithDelta(2_000.0, $r['component_packing_khusus'], 0.01, '2 × Rp 1.000 — peti kayu komponen tetap dibutuhkan di dalam paket.');
        $this->assertEqualsWithDelta(36_000.0, $r['components_subtotal'], 0.01, '(2 × Rp 15.000) + (3 × Rp 2.000).');
        $this->assertEqualsWithDelta(41_000.0, $r['hpp_per_unit'], 0.01, 'Isi paket + overhead packing sekali.');
    }

    public function test_overhead_packing_dihitung_sekali_bukan_per_komponen(): void
    {
        $this->isi([$this->dibuatId => 2, $this->dibeliId => 3]);

        $r = $this->bundle();

        $this->assertEqualsWithDelta(self::OVERHEAD, $r['packing_overhead'], 0.01);

        // Kalau HPP komponen dijumlah apa adanya (masing-masing sudah memuat overhead),
        // paket 5 barang ini akan menanggung Rp 25.000 ongkos membungkus untuk satu paket.
        $naif = (2 * (15_000.0 + self::OVERHEAD)) + (3 * (2_000.0 + self::OVERHEAD));
        $this->assertEqualsWithDelta(61_000.0, $naif, 0.01, 'Rp 25.000 di antaranya ongkos membungkus untuk satu paket.');
        $this->assertLessThan($naif, $r['hpp_per_unit'], 'Overhead packing terbawa berkali-kali dari komponen.');
    }

    public function test_packing_khusus_paket_ditambahkan_di_atas_overhead(): void
    {
        $this->isi([$this->dibuatId => 1]);
        ProductPackingCost::create(['product_id' => $this->bundleId, 'amount_per_unit' => 7_000]);

        $r = $this->bundle();

        $this->assertEqualsWithDelta(7_000.0, $r['packing_khusus'], 0.01);
        $this->assertEqualsWithDelta(12_000.0, $r['packing_total'], 0.01, 'Kardus paket adalah biaya EKSTRA, bukan pengganti.');
        $this->assertEqualsWithDelta(27_000.0, $r['hpp_per_unit'], 0.01);
    }

    // ==========================================================
    // ASAL ANGKA KOMPONEN
    // ==========================================================

    public function test_komponen_beli_jadi_memakai_harga_perolehan_terbaru(): void
    {
        $this->isi([$this->dibeliId => 1]);

        $c = $this->bundle()['components'][0];

        $this->assertSame('kartu stok', $c['source']);
        $this->assertEqualsWithDelta(2_000.0, $c['variable_cost'], 0.01, 'Lapisan terbaru, bukan rata-rata lapisan.');
        $this->assertEqualsWithDelta(0.0, $c['fixed_cost'], 0.01, 'Pabrik tidak mengeluarkan jam kerja untuk barang beli-jadi.');
    }

    public function test_komponen_tanpa_hpp_maupun_kartu_stok_diberi_peringatan(): void
    {
        $kosong = $this->produk('KOMP-C', 'Komponen Tanpa Jejak', 0);
        $this->isi([$kosong => 1]);

        $r = $this->bundle();

        $this->assertNotEmpty($r['warnings'], 'Tanpa peringatan, HPP terbaca wajar padahal kekurangan biaya satu komponen.');
        $this->assertEqualsWithDelta(self::OVERHEAD, $r['hpp_per_unit'], 0.01);
    }

    public function test_waktu_produksi_adalah_jumlah_waktu_komponen(): void
    {
        $this->isi([$this->dibuatId => 2, $this->dibeliId => 3]);

        $this->assertEqualsWithDelta(7_200.0, $this->bundle()['sec_per_unit'], 0.01, '2 × 1 jam; komponen beli-jadi tidak menambah waktu.');
    }

    // ==========================================================
    // ISI PAKET
    // ==========================================================

    public function test_isi_paket_dibaca_dari_product_bundles_saat_bundle_components_kosong(): void
    {
        // Urutan yang sama dengan BundleService, supaya HPP membaca isi paket yang persis
        // sama dengan yang dipotong dari stok saat bundle dikirim.
        ProductBundle::create([
            'bundle_product_id'    => $this->bundleId,
            'component_product_id' => $this->dibuatId,
            'qty_required'         => 4,
        ]);

        $r = $this->bundle();

        $this->assertSame(1, $r['component_count']);
        $this->assertEqualsWithDelta(60_000.0, $r['components_subtotal'], 0.01, '4 × Rp 15.000.');
    }

    public function test_bundle_tanpa_komponen_diberi_peringatan(): void
    {
        $r = $this->bundle();

        $this->assertSame(0, $r['component_count']);
        $this->assertNotEmpty($r['warnings']);
    }

    // ==========================================================
    // MARGIN
    // ==========================================================

    public function test_margin_dan_potongan_bundling_dihitung_dari_harga_base(): void
    {
        $this->isi([$this->dibuatId => 2, $this->dibeliId => 3]);

        $r = $this->bundle();

        $this->assertEqualsWithDelta(200_000.0 - 41_000.0, $r['margin'], 0.01);
        $this->assertEqualsWithDelta(195_000.0, $r['components_price_total'], 0.01, '(2 × Rp 90.000) + (3 × Rp 5.000) kalau dibeli satuan.');
        $this->assertEqualsWithDelta(-5_000.0, $r['bundle_discount'], 0.01, 'Paket ini justru lebih mahal dari isinya — dan itu harus kelihatan.');
    }

    // ==========================================================
    // Bantuan
    // ==========================================================

    private function bundle(): array
    {
        return app(BundleHppService::class)->forProduct($this->bundleId, []);
    }

    /** @param array<int,float> $komponen [product_id => qty] */
    private function isi(array $komponen): void
    {
        foreach ($komponen as $pid => $qty) {
            BundleComponent::create([
                'bundle_product_id'    => $this->bundleId,
                'component_product_id' => $pid,
                'qty'                  => $qty,
            ]);
        }
    }

    private function produk(string $sku, string $nama, float $harga, string $tipe = 'ready'): int
    {
        return DB::table('products')->insertGetId([
            'sku' => $sku, 'name' => $nama, 'base_price' => $harga, 'sale_type' => $tipe,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function lapisan(int $productId, float $unitCost): void
    {
        DB::table('stock_layers')->insert([
            'product_id' => $productId, 'warehouse_id' => 1,
            'qty_in' => 10, 'qty_remaining' => 10, 'unit_cost' => $unitCost,
            'source_type' => 'purchase_invoice', 'source_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * HPP Ready dipatok, supaya yang diuji perakitannya — bukan lagi cara komponen dihitung
     * (itu punya ProductHppServiceTest). Komponen beli-jadi sengaja TIDAK ada di sini,
     * persis seperti kenyataannya: barang yang tak pernah diproduksi tidak punya sampel OP.
     */
    private function stubReady(): void
    {
        $rows = [
            $this->dibuatId => [
                'product'                => ['id' => $this->dibuatId, 'sku' => 'KOMP-A', 'name' => 'Komponen Dibuat'],
                'variable_cost'          => 10_000.0,
                'fixed_cost'             => 4_000.0,
                'packing_khusus'         => 1_000.0,
                'packing_overhead'       => self::OVERHEAD,
                'sec_per_unit_effective' => 3_600.0,
            ],
        ];

        $basis = ['packing_per_transaction' => self::OVERHEAD, 'rate_per_slot_hour' => 20_000.0];

        $this->app->instance(ProductHppService::class, new class($rows, $basis) extends ProductHppService {
            public function __construct(private array $rows, private array $angka)
            {
            }

            public function all(array $filters = []): Collection
            {
                return collect($this->rows);
            }

            public function basis(array $filters = []): array
            {
                return $this->angka;
            }
        });

        app()->forgetInstance(BundleHppService::class);
    }
}
