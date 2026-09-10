<?php

namespace App\Modules\CRM\Services;

use App\Core\Inventory\Product;
use App\Core\Inventory\Services\StokTersediaService;
use App\Models\StoreProduct;
use App\Modules\CRM\Models\CrmStockWatch;
use App\Modules\Sales\Services\PromotionService;

/**
 * Pencarian produk untuk chat: stok, harga setelah promo, dan tautannya.
 *
 * Dipindahkan keluar dari CrmInboxController saat agen AI lahir (Tahap 8),
 * karena sekarang ada DUA pembaca — panel Produk di rail kanan dan alat
 * `cari_produk` milik agen. Menyalinnya berarti dua tempat menghitung stok dan
 * diskon sendiri-sendiri, dan bedanya baru ketahuan dari pembeli yang melihat
 * satu angka di chat dan angka lain di etalase.
 *
 * Tidak menyentuh request maupun auth — memang harus begitu: agen memanggilnya
 * dari luar konteks HTTP.
 */
class PencarianProdukService
{
    public function __construct(
        private StokTersediaService $stok,
        private PromotionService $promosi,
    ) {
    }

    /**
     * Sub-tab WEB: hasilnya DIKELOMPOKKAN per halaman etalase, bukan per SKU.
     *
     * Satu halaman produk sering menampung banyak SKU yang berbeda tipis —
     * "1 Kotak", "1 Kotak Instant", "1 Kotak Packing Kayu". Sebagai kartu
     * terpisah mereka memakan seluruh layar dan tampak seperti tiga barang
     * yang tak berhubungan, padahal yang ditanya pembeli justru
     * PERBANDINGANNYA: mana yang lebih murah, mana yang stoknya ada.
     * Dijejer sebagai baris di bawah satu nama, jawabannya terbaca sekilas.
     *
     * Varian saudara ikut ditarik walau tidak cocok dengan kata pencarian:
     * kelompok yang cuma memuat sebagian variannya menyesatkan — admin
     * menyimpulkan "cuma ada dua pilihan" padahal ada lima.
     *
     * @return array{grup: array<int, array<string, mixed>>}
     */
    public function web(string $cari, int $chatId = 0, int $batas = 15): array
    {
        $basis = rtrim((string) config('crm.storefront_url'), '/');

        $halaman = StoreProduct::query()
            ->published()
            ->when($cari !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$cari}%")
                ->orWhereHas('variants.product', fn ($p) => $p
                    ->where('name', 'like', "%{$cari}%")
                    ->orWhere('sku', 'like', "%{$cari}%"))))
            ->with([
                'images',
                'variants' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
                'variants.product' => fn ($q) => $q->where('is_active', 1)->where('is_sellable', 1),
            ])
            ->orderBy('name')
            ->limit($batas)
            ->get();

        // Varian yang produknya sudah mati/tidak dijual disaring di sini, bukan
        // di kueri: `with` yang berkondisi hanya mengosongkan relasinya.
        $produkIds = $halaman->flatMap(
            fn ($h) => $h->variants->filter(fn ($v) => $v->product)->pluck('product_id')
        )->unique()->values();

        $stok     = $this->stok->untuk($produkIds);
        $diskon   = $this->diskonItem($halaman->flatMap(fn ($h) => $h->variants)->pluck('product')->filter());
        $ditandai = $this->titipanAktif($chatId, $produkIds);

        $grup = $halaman->map(function (StoreProduct $h) use ($basis, $stok, $diskon, $ditandai) {
            $varian = $h->variants
                ->filter(fn ($v) => $v->product)
                ->map(fn ($v) => $this->barisVarian($v->product, $stok, $diskon, $ditandai, [
                    // Label varian kadang kosong (halaman satu-SKU); nama produknya
                    // yang dipakai, supaya kolomnya tidak pernah melompong.
                    'label' => $v->variant_label ?: $v->product->name,
                ]))
                ->values();

            return [
                'id'     => $h->id,
                'nama'   => $h->name,
                'url'    => $basis . '/produk/' . $h->slug,
                'foto'   => $h->images->sortByDesc('is_primary')->first()?->url,
                'varian' => $varian,
            ];
        })->filter(fn ($g) => $g['varian']->isNotEmpty())->values();

        return ['grup' => $grup];
    }

    /**
     * Sub-tab CUSTOM: tetap per SKU, tanpa pengelompokan.
     *
     * Barang buatan tidak punya halaman etalase untuk dijadikan induk, dan
     * memang tidak berpasangan — tiap SKU berdiri sendiri.
     *
     * @return array{produk: array<int, array<string, mixed>>}
     */
    public function custom(string $cari, int $chatId = 0, int $batas = 15): array
    {
        $produk = Product::query()
            ->where('is_active', 1)
            ->where('is_sellable', 1)
            ->madeToOrder()
            ->when($cari !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$cari}%")
                ->orWhere('sku', 'like', "%{$cari}%")))
            ->with(['links' => fn ($q) => $q->orderBy('urutan')->orderBy('id')])
            ->orderBy('name')
            ->limit($batas)
            ->get();

        $stok     = $this->stok->untuk($produk->pluck('id'));
        $diskon   = $this->diskonItem($produk);
        $ditandai = $this->titipanAktif($chatId, $produk->pluck('id'));

        return [
            'produk' => $produk->map(fn ($p) => $this->barisVarian($p, $stok, $diskon, $ditandai, [
                'berat'  => (int) ($p->weight_gram ?? 0),
                'tautan' => $p->links->map(fn ($l) => [
                    'id'    => $l->id,
                    'judul' => $l->judul,
                    'url'   => $l->url,
                ])->values(),
            ]))->values(),
        ];
    }

    /**
     * Daftar RATA untuk agen: satu baris per SKU, web dan custom disatukan,
     * masing-masing membawa tautan halamannya.
     *
     * Bentuknya berbeda dari dua method di atas dengan sengaja. Layar butuh
     * pengelompokan supaya perbandingan varian terbaca sekilas; agen justru
     * tersesat olehnya — yang ia perlukan adalah daftar pendek berisi nama,
     * stok, harga, dan tautan, siap disebut apa adanya.
     *
     * @return array<int, array<string, mixed>>
     */
    public function untukAgen(string $cari, int $batas = 8): array
    {
        $hasil = [];

        foreach ($this->web($cari, 0, $batas)['grup'] as $grup) {
            foreach ($grup['varian'] as $v) {
                $hasil[] = [
                    'nama'        => $v['label'] ?: $v['nama'],
                    'sku'         => $v['sku'],
                    'stok'        => $v['stok'],
                    'harga'       => $v['harga'],
                    'harga_coret' => $v['harga_coret'],
                    'promo'       => $v['promo'],
                    'preorder'    => $v['preorder'],
                    'custom'      => $v['custom'],
                    'url'         => $grup['url'],
                ];
            }
        }

        foreach ($this->custom($cari, 0, $batas)['produk'] as $p) {
            $hasil[] = [
                'nama'        => $p['nama'],
                'sku'         => $p['sku'],
                'stok'        => $p['stok'],
                'harga'       => $p['harga'],
                'harga_coret' => $p['harga_coret'],
                'promo'       => $p['promo'],
                'preorder'    => $p['preorder'],
                'custom'      => $p['custom'],
                'url'         => $p['tautan'][0]['url'] ?? null,
            ];
        }

        return array_slice($hasil, 0, $batas);
    }

    /**
     * Satu baris SKU siap tampil — dipakai kedua sub-tab supaya angka yang
     * dibacakan admin tak pernah berbeda bentuk antar layar.
     */
    private function barisVarian(Product $p, array $stok, array $diskon, $ditandai, array $tambahan = []): array
    {
        $harga = round((float) $p->display_price, 2);
        $promo = $diskon[$p->id] ?? null;

        return array_merge([
            'id'          => $p->id,
            'nama'        => $p->name,
            'label'       => $p->name,
            'sku'         => $p->sku,
            'harga'       => $promo ? round(max(0, $harga - (float) $promo['discount_amount']), 2) : $harga,
            'harga_coret' => $promo ? $harga : null,
            'promo'       => $promo['promotion_name'] ?? null,
            'stok'        => (float) ($stok[$p->id] ?? 0),
            'preorder'    => $p->isPreorder(),
            'custom'      => $p->isMadeToOrder(),
            'titipan'     => $ditandai[$p->id]->id ?? null,
            // Jumlah yang ditunggu ikut, supaya tanda di layar berbunyi
            // "dikabari saat mencapai 10" — bukan sekadar "ditandai".
            'titipan_qty' => isset($ditandai[$p->id]) ? (float) $ditandai[$p->id]->qty : null,
        ], $tambahan);
    }

    /**
     * Diskon item aktif, dihitung sekali untuk seluruh hasil.
     *
     * Harga yang dibacakan ke pembeli WAJIB harga setelah promo — angka yang
     * sama dengan yang terpampang di etalase. Menyebut harga coret di chat lalu
     * pembeli melihat harga lain di web adalah cara tercepat kehilangan
     * kepercayaan, dan koreksinya selalu merugikan kita.
     */
    private function diskonItem($produk): array
    {
        $items = collect($produk)->filter()->unique('id')
            ->map(fn ($p) => [
                'product_id' => $p->id,
                'qty'        => 1,
                'unit_price' => (float) $p->display_price,
            ])->values()->all();

        return $items ? $this->promosi->resolveItemDiscounts($items) : [];
    }

    /** Titipan "kabari kalau stok ada" milik chat yang sedang dibuka. */
    private function titipanAktif(int $chatId, $produkIds)
    {
        if (! $chatId) {
            return collect();
        }

        return CrmStockWatch::aktif()
            ->where('conversation_id', $chatId)
            ->whereIn('product_id', collect($produkIds)->all())
            ->get(['id', 'product_id', 'qty'])
            ->keyBy('product_id');
    }
}
