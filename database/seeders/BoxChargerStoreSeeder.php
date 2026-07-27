<?php

namespace Database\Seeders;

use App\Core\Inventory\Product;
use App\Models\StoreCategory;
use App\Models\StoreProduct;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Listing toko online Box Charger (kategori + 11 produk per ukuran) untuk storefront.
 *
 * Idempoten: aman dijalankan berulang (updateOrCreate by slug, varian dibuat ulang).
 * SKU dipetakan lewat KODE `sku` (bukan id) supaya cocok di DB mana pun (id server ≠ lokal).
 * Dibuat sebagai DRAFT — terbitkan manual dari UI setelah foto/video diisi.
 *
 * Jalankan: php artisan db:seed --class=BoxChargerStoreSeeder --force
 */
class BoxChargerStoreSeeder extends Seeder
{
    /** Grup per ukuran: [label opsi varian, kode SKU]. axis null = produk tunggal. */
    private const SIZES = [
        ['kotak' => 2,  'axis' => 'Orientasi', 'variants' => [['Landscape (Mendatar)', 'BC-2x1'], ['Portrait (Berdiri)', 'BC-1x2']]],
        ['kotak' => 3,  'axis' => null,        'variants' => [[null, 'BC-3x1']]],
        ['kotak' => 4,  'axis' => 'Susunan',   'variants' => [['4×1 Mendatar', 'BC-4x1'], ['1×4 Berdiri', 'BC-1x4'], ['2×2 (Kotak)', 'BC-2x2']]],
        ['kotak' => 5,  'axis' => null,        'variants' => [[null, 'BC-1x5']]],
        ['kotak' => 6,  'axis' => 'Orientasi', 'variants' => [['Landscape (Mendatar)', 'BC-2x3'], ['Portrait (Berdiri)', 'BC-3x2']]],
        ['kotak' => 8,  'axis' => 'Orientasi', 'variants' => [['Landscape (Mendatar)', 'BC-4x2'], ['Portrait (Berdiri)', 'BC-2x4']]],
        ['kotak' => 9,  'axis' => null,        'variants' => [[null, 'BC-3x3']]],
        ['kotak' => 10, 'axis' => 'Orientasi', 'variants' => [['Landscape (Mendatar)', 'BC-5x2'], ['Portrait (Berdiri)', 'BC-2x5']]],
        ['kotak' => 12, 'axis' => 'Orientasi', 'variants' => [['Landscape (Mendatar)', 'BC-4x3'], ['Portrait (Berdiri)', 'BC-3x4']]],
        ['kotak' => 15, 'axis' => null,        'variants' => [[null, 'BC-3x5']]],
        ['kotak' => 16, 'axis' => null,        'variants' => [[null, 'BC-4x4']]],
    ];

    public function run(): void
    {
        $cat = StoreCategory::updateOrCreate(
            ['slug' => 'box-charger'],
            ['name' => 'Box Charger', 'parent_id' => null, 'sort_order' => 2]
        );

        // Peta kode SKU → id Product (di DB ini). SKU tak ditemukan → varian dilewati.
        $skuCodes = collect(self::SIZES)->flatMap(fn ($s) => array_map(fn ($v) => $v[1], $s['variants']))->all();
        $skuMap = Product::whereIn('sku', $skuCodes)->pluck('id', 'sku');

        $order = 1;
        $created = 0;
        $skipped = [];

        foreach (self::SIZES as $size) {
            $k = $size['kotak'];

            // Bangun daftar varian yang SKU-nya ada.
            $variantRows = [];
            foreach (array_values($size['variants']) as $i => [$opt, $sku]) {
                if (!isset($skuMap[$sku])) {
                    $skipped[] = "$sku ({$k} kotak)";
                    continue;
                }
                $variantRows[] = [
                    'product_id'    => (int) $skuMap[$sku],
                    'variant_label' => $opt,
                    'option_values' => $opt === null ? null : [$opt],
                    'is_default'    => false, // ditetapkan setelah difilter
                    'sort_order'    => $i,
                ];
            }
            if (empty($variantRows)) {
                $this->command?->warn("Lewati {$k} kotak — tidak ada SKU BC yang cocok.");
                continue;
            }
            $variantRows[0]['is_default'] = true;

            $name = "Box Charger HP Akrilik {$k} Kotak";
            $slug = "box-charger-hp-akrilik-{$k}-kotak";

            // variant_axes hanya bila lebih dari satu varian tersedia.
            $axes = ($size['axis'] !== null && count($variantRows) > 1)
                ? [['name' => $size['axis'], 'options' => array_map(fn ($v) => $v['variant_label'], $variantRows)]]
                : null;

            DB::transaction(function () use ($cat, $size, $k, $name, $slug, $axes, $variantRows, $order) {
                $sp = StoreProduct::updateOrCreate(
                    ['slug' => $slug],
                    [
                        'name'              => $name,
                        'store_category_id' => $cat->id,
                        'short_description' => "Box charger / charging station akrilik {$k} slot untuk pengisian daya HP di fasilitas umum. Bisa custom logo instansi.",
                        'description'       => $this->buildDescription($size, $variantRows),
                        'meta_title'        => "Box Charger HP Akrilik {$k} Kotak (Charging Station) | Noud Acrylic",
                        'meta_description'  => "Jual Box Charger HP Akrilik {$k} Kotak / charging station akrilik untuk {$k} HP. Cocok masjid, kantor, bank & fasilitas umum. Bisa custom logo instansi. Produksi ±5 hari kerja.",
                        'status'            => 'draft',
                        'is_featured'       => false,
                        'sort_order'        => $order,
                        'variant_axes'      => $axes,
                    ]
                );

                $sp->variants()->delete();
                foreach ($variantRows as $r) {
                    $sp->variants()->create($r);
                }
            });

            $created++;
            $order++;
        }

        $this->command?->info("BoxChargerStoreSeeder: {$created} listing dibuat/diperbarui (kategori '{$cat->name}').");
        if (!empty($skipped)) {
            $this->command?->warn('SKU tidak ditemukan (varian dilewati): ' . implode(', ', $skipped));
        }
    }

    /** Deskripsi teks (emoji + newline), memakai dimensi dari Product per SKU. */
    private function buildDescription(array $size, array $variantRows): string
    {
        $k = $size['kotak'];

        // Baris ukuran: satu varian → satu ukuran; banyak → per opsi.
        if (count($variantRows) === 1) {
            $ukuran = 'Ukuran (P×L×T): ' . $this->dimOf($variantRows[0]['product_id']);
        } else {
            $parts = [];
            foreach ($variantRows as $r) {
                $parts[] = '• ' . $r['variant_label'] . ': ' . $this->dimOf($r['product_id']);
            }
            $ukuran = "Ukuran (P×L×T) per pilihan:\n" . implode("\n", $parts);
        }

        return <<<TXT
Box Charger HP Akrilik {$k} Kotak — tempat pengisian daya HP untuk {$k} unit sekaligus. Dikenal juga sebagai charging station HP akrilik, tempat cas HP umum, kotak charger HP, atau loker charger HP. Solusi rapi untuk titik pengisian daya di fasilitas umum.

✨ Spesifikasi
• Bahan: Akrilik premium
• Kapasitas: {$k} slot pengisian HP
• {$ukuran}
• Sistem: Pre-order (dibuat sesuai pesanan)
• Estimasi produksi: ±5 hari kerja

🏢 Cocok untuk
Masjid, kantor, bank, rumah sakit, sekolah, hotel, dan area publik lain yang ingin menyediakan fasilitas free charging bagi pengunjung.

🎨 Bisa Custom Logo Instansi
Tambahkan logo atau nama instansi Anda pada box charger. Sudah dipercaya berbagai kantor pemerintah, bank, dan perusahaan di seluruh Indonesia sejak 2018.

📦 Pengiriman Aman
Dikemas rapi dengan proteksi berlapis. Untuk pengiriman jarak jauh tersedia opsi packing kayu.

💬 Konsultasi kebutuhan instansi (jumlah, ukuran, custom logo) via chat sebelum memesan.
TXT;
    }

    /** Dimensi "P×L×T cm" dari Product (format desimal Indonesia). */
    private function dimOf(int $productId): string
    {
        $p = Product::find($productId);
        if (!$p) {
            return '-';
        }
        $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', ''), '0'), ',');
        return $fmt($p->length_cm) . '×' . $fmt($p->width_cm) . '×' . $fmt($p->height_cm) . ' cm';
    }
}
