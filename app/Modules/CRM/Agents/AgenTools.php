<?php

namespace App\Modules\CRM\Agents;

use App\Modules\CRM\Models\CrmAgent;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Services\PencarianProdukService;
use App\Modules\Marketplace\Shopee\Models\ShopeeItem;
use App\Modules\Marketplace\Shopee\Models\ShopeeOrder;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeClient;
use App\Modules\Tasks\MejaKerja\Skills\DesainSkill;
use App\Core\Inventory\Product;
use App\Models\StoreProduct;
use Illuminate\Support\Collection;
use Carbon\Carbon;

/**
 * Alat yang boleh dipanggil agen, beserta pelaksanaannya.
 *
 * Ronde 1 SELURUHNYA baca saja. Tidak ada alat yang mengubah data ERP — kecuali
 * `lempar_ke_manusia`, yang hanya menggeser label percakapan dan justru
 * pengaman, bukan risiko.
 *
 * Kontrak balikan meniru AiAccountantService yang sudah terbukti:
 *   ['content' => string]                 hasil biasa, dikembalikan ke model
 *   ['content' => string, '_lempar' => true]  agen menyerah ke manusia
 *
 * Hasil alat SELALU string, bukan JSON. Bukan kebetulan: model membaca kalimat
 * jauh lebih andal daripada struktur bersarang, dan galat yang dikembalikan
 * sebagai teks biasa ("Tidak ketemu…") membuatnya memperbaiki diri sendiri
 * alih-alih menggagalkan seluruh giliran.
 */
class AgenTools
{
    /** Provinsi Pulau Jawa — selain ini "luar Jawa" (wajib packing kayu, butir F2). */
    private const PROVINSI_JAWA = ['dki jakarta', 'jakarta', 'jawa barat', 'jawa tengah', 'jawa timur',
        'di yogyakarta', 'yogyakarta', 'daerah istimewa yogyakarta', 'banten'];

    private const BATAS_CARI_SHOPEE = 40;

    public function __construct(
        private PencarianProdukService $pencarian,
        private ShopeeClient $shopee,
    ) {
    }

    /**
     * Skema alat per agen.
     *
     * URUTANNYA HARUS TETAP. Definisi alat ikut masuk awalan cache; daftar yang
     * urutannya berubah-ubah membatalkan cache tiap panggilan tanpa gejala apa
     * pun selain tagihan yang naik.
     *
     * @return array<int, array<string, mixed>>
     */
    public function skema(CrmAgent $agen): array
    {
        return match ($agen->kode) {
            CrmAgent::PENYAMBUTAN => [$this->skemaCariProduk(), $this->skemaLempar()],
            CrmAgent::CS_SHOPEE   => [$this->skemaCekStok(), $this->skemaInfoPesanan(), $this->skemaDeskripsiProduk(), $this->skemaUsulkan()],
            // Nama alat sama dengan CS Shopee (daftar tanya-jawab menyebutnya), isinya untuk WhatsApp:
            // harga & link web boleh, pesanan = SO ERP pelanggan ini.
            CrmAgent::CS_WHATSAPP => [$this->skemaCekStokWa(), $this->skemaInfoPesananWa(), $this->skemaDeskripsiProduk(), $this->skemaCekOngkir(), $this->skemaBuatPesanan(), $this->skemaUsulkan(true)],
            // Penjualan & cetak belum dikerjakan; keduanya minimal punya pintu
            // menyerah supaya tidak pernah terjebak mengarang jawaban.
            default => [$this->skemaLempar()],
        };
    }

    /**
     * @return array{content: string, _lempar?: bool}
     */
    public function jalankan(string $nama, array $input, ?CrmConversation $percakapan, ?CrmAgent $agen = null): array
    {
        $wa = $agen?->kode === CrmAgent::CS_WHATSAPP;

        return match ($nama) {
            'cari_produk'       => $this->cariProduk($input),
            'lempar_ke_manusia' => $this->lempar($input, $percakapan),
            'cek_stok'          => $wa ? $this->cekStokWa($input) : $this->cekStok($input),
            'info_pesanan'      => $wa ? $this->infoPesananWa($percakapan) : $this->infoPesanan($percakapan),
            'deskripsi_produk'  => $this->deskripsiProduk($input, ! $wa),
            'cek_ongkir'        => $wa ? $this->cekOngkir($input, $percakapan) : ['content' => 'Alat cek_ongkir hanya untuk WhatsApp.'],
            'buat_pesanan'      => $wa ? $this->buatPesanan($input, $percakapan) : ['content' => 'Alat buat_pesanan hanya untuk WhatsApp.'],
            'usulkan_balasan'   => $this->usulkan($input),
            default             => ['content' => 'Alat "' . $nama . '" tidak ada. Jangan panggil lagi.'],
        };
    }

    /* ------------------------------------------------------------ cari produk */

    private function skemaCariProduk(): array
    {
        return [
            'name'        => 'cari_produk',
            'description' => 'Cari barang di ERP untuk mengetahui stok, harga, dan tautan halamannya. '
                . 'WAJIB dipakai sebelum menyebut stok atau harga apa pun — jangan pernah '
                . 'menjawab dari ingatan. Kalau hasilnya lebih dari satu dan kamu tidak yakin '
                . 'yang dimaksud yang mana, tanyakan dulu ke pelanggan.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'kata_kunci' => [
                        'type'        => 'string',
                        'description' => 'Nama barang atau SKU, mis. "frame mahar 30x30" atau "box charger".',
                    ],
                ],
                'required' => ['kata_kunci'],
            ],
        ];
    }

    private function cariProduk(array $input): array
    {
        $kata = trim((string) ($input['kata_kunci'] ?? ''));

        if ($kata === '') {
            return ['content' => 'Kata kunci kosong. Sebutkan nama barang atau SKU-nya.'];
        }

        $hasil = $this->pencarian->untukAgen($kata);

        if (! $hasil) {
            return ['content' => 'Tidak ada barang yang cocok dengan "' . $kata . '". '
                . 'Jangan mengarang — tanyakan ke pelanggan nama barangnya, atau lempar ke tim.'];
        }

        $baris = [];

        foreach ($hasil as $p) {
            $bagian = [$p['nama']];

            if ($p['sku']) {
                $bagian[] = 'SKU ' . $p['sku'];
            }

            /*
             * Stok ditulis apa adanya, termasuk nol. Menyembunyikan stok nol
             * membuat agen menjawab seolah barangnya ada — kesalahan yang baru
             * ketahuan setelah pelanggan membayar.
             */
            $bagian[] = $p['custom']
                ? 'barang custom (dibuat setelah dipesan, bukan stok siap)'
                : ($p['preorder'] ? 'preorder, stok ' : 'stok ') . $this->angka($p['stok']);

            $harga = 'harga Rp' . number_format((float) $p['harga'], 0, ',', '.');

            if ($p['harga_coret']) {
                $harga .= ' (turun dari Rp' . number_format((float) $p['harga_coret'], 0, ',', '.')
                    . ($p['promo'] ? ', promo ' . $p['promo'] : '') . ')';
            }

            $bagian[] = $harga;

            if ($p['url']) {
                $bagian[] = 'tautan ' . $p['url'];
            }

            $baris[] = '- ' . implode(' | ', $bagian);
        }

        return ['content' => "Hasil pencarian (sebutkan apa adanya, jangan dibulatkan):\n"
            . implode("\n", $baris)];
    }

    private function angka(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
    }

    /* ------------------------------------------------------------ CS Shopee */

    private function skemaCekStok(): array
    {
        return [
            'name'        => 'cek_stok',
            'description' => 'Cek stok ERP sebuah barang (nama atau SKU). Hasilnya juga menyebut apakah '
                . 'barang itu pre-order dan link listing Shopee-nya. WAJIB dipakai sebelum menyebut stok '
                . 'atau mengirim link produk. Tidak memuat harga.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'kata_kunci' => ['type' => 'string', 'description' => 'Nama barang atau SKU, mis. "kotak saran 3 kotak" atau "BC-2x2".'],
                ],
                'required' => ['kata_kunci'],
            ],
        ];
    }

    /** Seperti cari_produk, tapi tanpa harga & tautan web: di Shopee keduanya tak boleh disebut. */
    private function cekStok(array $input): array
    {
        $kata = trim((string) ($input['kata_kunci'] ?? ''));
        if ($kata === '') {
            return ['content' => 'Kata kunci kosong. Sebutkan nama barang atau SKU-nya.'];
        }

        $hasil = $this->cariBarangShopee($kata);
        if (! $hasil) {
            return ['content' => 'Tidak ada barang yang cocok dengan "' . $kata . '". Jangan mengarang — tanya pembeli atau lempar ke admin.'];
        }

        $baris = array_map(fn ($p) => '- ' . $p['nama'] . ($p['sku'] ? ' | SKU ' . $p['sku'] : '') . ' | ' . ($p['custom']
            ? 'barang custom (dibuat setelah dipesan)'
            : ($p['preorder'] ? 'PRE-ORDER, stok ' : 'ready, stok ') . $this->angka($p['stok']))
            . (($link = $this->linkShopee($p['sku'])) ? ' | link Shopee ' . $link : ''), $hasil);

        return ['content' => ($this->cocokSebagian ? self::CATATAN_SEBAGIAN . "\n" : '')
            . "Hasil cek stok, urut dari yang paling cocok dengan kata pembeli (sebutkan apa adanya; SKU hanya untukmu, jangan disebut ke pembeli):\n" . implode("\n", $baris)];
    }

    /**
     * Pencarian untuk CS Shopee. Batasnya longgar: satu kata umum ("kotak saran") mencakup banyak
     * varian, dan batas kecil memotong produk utamanya. Tak ketemu lewat nama ERP → lewat nama
     * listing Shopee & teks SEO halaman web, tempat sebutan pembeli ("box charger", "rak brosur") ada.
     */
    private function cariBarangShopee(string $kata): array
    {
        $this->cocokSebagian = false;
        $hasil = $this->pencarian->untukAgen($kata, self::BATAS_CARI_SHOPEE);
        if ($hasil) {
            return $this->urutkanMenurutKata($hasil, $kata);
        }

        $perSku = [];
        foreach ($this->skuDariSebutan($kata) as $sku) {
            foreach ($this->pencarian->untukAgen($sku, self::BATAS_CARI_SHOPEE) as $p) {
                $perSku[$p['sku'] ?: $p['nama']] = $p;
            }
        }

        return array_slice($this->urutkanMenurutKata(array_values($perSku), $kata), 0, self::BATAS_CARI_SHOPEE);
    }

    /**
     * Varian yang paling banyak memuat kata pembeli di atas (permintaan owner 24 Nov 2026):
     * "gantungan landscape" menaruh varian Landscape sebelum "Tanpa Gantungan". Yang dibaca:
     * label varian web, nama ERP (label web bisa salah ketik, mis. "Landcsape"), dan SKU.
     * Urutan asli dipertahankan untuk skor yang sama.
     */
    private function urutkanMenurutKata(array $hasil, string $kata): array
    {
        $kataList = $this->pecahKata(mb_strtolower($kata));
        if (count($hasil) < 2 || ! $kataList) {
            return $hasil;
        }

        $namaErp = Product::whereIn('sku', array_filter(array_column($hasil, 'sku')))->pluck('name', 'sku');
        $skor = fn (array $p) => count(array_filter($kataList, fn ($w) => $this->kataCocok(
            $this->normalUkuran(mb_strtolower($p['nama'] . ' ' . ($namaErp[$p['sku']] ?? '') . ' ' . $p['sku'])), $w)));

        $berskor = array_map(fn ($p, $i) => [$skor($p), $i, $p], $hasil, array_keys($hasil));
        usort($berskor, fn ($a, $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        return array_column($berskor, 2);
    }

    /** Hasil cari terakhir hanya cocok sebagian kata (ukuran terbalik ditoleransi) — wajib dikonfirmasi ke pembeli. */
    private bool $cocokSebagian = false;

    private const CATATAN_SEBAGIAN = 'Cocok SEBAGIAN dengan sebutan pembeli (tidak semua kata ada di nama produk). '
        . 'WAJIB konfirmasi dulu: kirim link produknya dan tanya "apakah produk yang dimaksud yang ini kak?" — '
        . 'baru lanjut stok/harga/checkout setelah pembeli mengiyakan.';

    /**
     * SKU listing/halaman yang namanya memuat SEMUA kata dalam sebutan pembeli. Tak ada → yang
     * ukurannya cocok (P×L boleh terbalik: "30x40x6" = "40x30x6", keluhan owner 24 Nov 2026)
     * dengan kata cocok terbanyak; hasil begini ditandai `cocokSebagian`.
     *
     * @return string[]
     */
    private function skuDariSebutan(string $kata): array
    {
        $kandidat = ShopeeItem::where('env', $this->shopee->env())->where('status', 'NORMAL')
            ->get(['nama', 'model_sku', 'item_sku'])->toBase()
            ->map(fn ($i) => ['teks' => (string) $i->nama, 'sku' => [$i->model_sku ?: $i->item_sku]])
            ->concat(StoreProduct::published()
                ->with('variants.product:id,sku,name')
                ->get(['id', 'name', 'short_description', 'meta_title', 'meta_description'])->toBase()
                // Per VARIAN: teks halaman + label varian + nama ERP-nya, supaya "gantungan landscape"
                // (yang hanya ada di label/nama varian) ikut terbaca.
                ->flatMap(fn ($h) => $h->variants->filter(fn ($v) => $v->product)->map(fn ($v) => [
                    'teks' => "{$h->name} {$h->short_description} {$h->meta_title} {$h->meta_description} {$v->variant_label} {$v->product->name}",
                    'sku'  => [$v->product->sku],
                ])));

        $semua = $kandidat->filter(fn ($k) => $this->memuatSemuaKata($k['teks'], $kata));
        $kataList = $this->pecahKata($kata);
        $ukuran   = array_filter($kataList, fn ($w) => $this->kataUkuran($w));
        // Cocok sebagian hanya bila pembeli menyebut ukuran: tanpa ukuran, kata umum ("box charger
        // pigura") terlalu mudah menyeret produk lain.
        if ($semua->isEmpty() && $ukuran) {
            $skor     = $kandidat->map(fn ($k) => $k + ['skor' => count(array_filter($kataList, fn ($w) => $this->kataCocok($this->normalUkuran($k['teks']), $w)))])
                // Ukuran yang disebut WAJIB cocok (ukuran lain = produk lain), plus minimal satu kata lain.
                ->filter(fn ($k) => collect($ukuran)->every(fn ($w) => $this->kataCocok($this->normalUkuran($k['teks']), $w))
                    && $k['skor'] >= min(count($kataList), count($ukuran) + 1));
            $terbaik  = $skor->max('skor');
            $semua    = $skor->filter(fn ($k) => $k['skor'] === $terbaik);
            $this->cocokSebagian = $semua->isNotEmpty();
        }

        return $semua->flatMap(fn ($k) => $k['sku'])->filter()->unique()->values()->all();
    }

    /**
     * Semua kata ada di teks. Angka harus utuh: "4 kotak" tidak boleh cocok dengan "14 kotak"
     * atau "A4", karena jawabannya jadi ukuran produk lain. Ukuran P×L boleh terbalik.
     */
    private function memuatSemuaKata(string $teks, string $kata): bool
    {
        $teks = $this->normalUkuran($teks);
        foreach ($this->pecahKata($kata) as $w) {
            if (! $this->kataCocok($teks, $w)) {
                return false;
            }
        }

        return true;
    }

    /** @return string[] kata sebutan pembeli; ukuran "40 x 30" dirapatkan jadi satu kata "40x30" */
    private function pecahKata(string $kata): array
    {
        return array_values(array_filter(preg_split('/\s+/u', $this->normalUkuran($kata))));
    }

    /** "40 X 30 x 6" / "40×30" → "40x30x6", supaya ukuran dibandingkan sebagai satu kata. */
    private function normalUkuran(string $teks): string
    {
        return preg_replace_callback('/(\d+)\s*[x×*]\s*(\d+)(?:\s*[x×*]\s*(\d+))?/iu',
            fn ($m) => $m[1] . 'x' . $m[2] . (isset($m[3]) && $m[3] !== '' ? 'x' . $m[3] : ''), $teks);
    }

    private function kataUkuran(string $w): bool
    {
        return (bool) preg_match('/^\d+x\d+(x\d+)?$/i', $w);
    }

    private function kataCocok(string $teksNormal, string $w): bool
    {
        if ($this->kataUkuran($w)) {
            $d = explode('x', mb_strtolower($w));
            $varian = array_unique([implode('x', $d), implode('x', [$d[1], $d[0]] + $d)]);

            return collect($varian)->contains(fn ($v) => preg_match('/(?<![\p{L}\p{N}])' . preg_quote($v, '/') . '(?![\p{N}])/iu', $teksNormal));
        }

        return ctype_digit($w)
            ? (bool) preg_match('/(?<![\p{L}\p{N}])' . $w . '(?![\p{L}\p{N}])/u', $teksNormal)
            : mb_stripos($teksNormal, $w) !== false;
    }

    /** Halaman yang NAMANYA memuat angka dalam kata kunci didahulukan: "4 kotak" membaca halaman 4 Kotak. */
    private function urutkanMenurutAngka(Collection $halaman, string $kata): Collection
    {
        $angka = implode(' ', array_filter(preg_split('/\s+/u', $kata), 'ctype_digit'));

        return $angka === '' ? $halaman
            : $halaman->sortBy(fn (StoreProduct $h) => $this->memuatSemuaKata($h->name, $angka) ? 0 : 1)->values();
    }

    /** Link listing Shopee untuk SKU ini (peta SKU shopee_items) — link di dalam Shopee boleh dikirim. */
    private function linkShopee(?string $sku): ?string
    {
        if (! $sku) {
            return null;
        }
        $produk = Product::where('sku', $sku)->value('id');
        $item = ShopeeItem::where('env', $this->shopee->env())->where('status', 'NORMAL')
            ->where(fn ($q) => $q->where('model_sku', $sku)->orWhere('item_sku', $sku)->when($produk, fn ($w) => $w->orWhere('product_id', $produk)))
            ->first(['shop_id', 'item_id']);

        return $item ? "https://shopee.co.id/product/{$item->shop_id}/{$item->item_id}" : null;
    }

    private function skemaDeskripsiProduk(): array
    {
        return [
            'name'        => 'deskripsi_produk',
            'description' => 'Deskripsi lengkap produk: ukuran, bahan, ketebalan, isi paket, kelengkapan, fitur, cara pakai. '
                . 'WAJIB dipakai sebelum menjawab hal-hal itu, jangan mengarang. Pakai SKU dari "Produk yang sedang dibahas" '
                . 'bila ada. Jawab hanya yang ditanyakan, jangan menyalin seluruh deskripsi. Yang tidak tertulis berarti tidak '
                . 'diketahui: lempar ke admin. Harga & stok tidak diambil dari sini.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'kata_kunci' => ['type' => 'string', 'description' => 'SKU atau nama barang, mis. "BC-2x2" atau "kotak saran 3 kotak".'],
                ],
                'required' => ['kata_kunci'],
            ],
        ];
    }

    /**
     * Deskripsi halaman web produk (lebih lengkap dari deskripsi listing Shopee).
     * $untukShopee: buang ajakan WA/tautan luar — di WhatsApp kalimat itu boleh tetap ada.
     */
    private function deskripsiProduk(array $input, bool $untukShopee = true): array
    {
        $kata = trim((string) ($input['kata_kunci'] ?? ''));
        if ($kata === '') {
            return ['content' => 'Kata kunci kosong. Sebutkan SKU atau nama barangnya.'];
        }

        $halaman = fn (array $sku) => StoreProduct::published()
            ->whereHas('variants.product', fn ($q) => $q->whereIn('sku', $sku))
            ->with('variants.product:id,sku')
            ->orderBy('name')->get();

        // Urutan: SKU persis, nama halaman (yang berawalan kata itu dulu, supaya "kotak saran"
        // tak dijawab dengan "Koin Akrilik Kotak Saran"), baru pencarian barang.
        $produk = $halaman([$kata]);
        if ($produk->isEmpty()) {
            $produk = StoreProduct::published()->where('name', 'like', "%{$kata}%")
                ->with('variants.product:id,sku')
                ->orderByRaw('CASE WHEN name LIKE ? THEN 0 ELSE 1 END', ["{$kata}%"])->orderBy('name')->get();
        }
        if ($produk->isEmpty()) {
            $produk = $this->urutkanMenurutAngka($halaman(array_values(array_filter(array_column($this->cariBarangShopee($kata), 'sku')))), $kata);
        }
        if ($produk->isEmpty()) {
            return ['content' => 'Belum ada deskripsi untuk "' . $kata . '". Jangan mengarang — lempar ke admin.'];
        }

        $sku = fn (StoreProduct $p) => $p->variants->map(fn ($v) => trim(($v->variant_label ?: 'tanpa varian') . ' = ' . $v->product?->sku))->implode('; ');
        $isi = $produk->take(2)->map(fn (StoreProduct $p) => $p->name . "\nVarian & SKU: " . $sku($p)
            . "\n\n" . ($untukShopee ? $this->deskripsiUntukShopee((string) $p->description) : $this->deskripsiPolos((string) $p->description)));
        $lain = $produk->slice(2)->map(fn (StoreProduct $p) => '- ' . $p->name . ': ' . $sku($p));

        return ['content' => "Deskripsi produk (SKU hanya untukmu, jangan disebut ke pembeli):\n\n" . $isi->implode("\n\n---\n\n")
            . ($lain->isEmpty() ? '' : "\n\n---\n\nProduk lain yang juga cocok (belum ditampilkan, panggil lagi dengan SKU-nya bila produk ini yang dimaksud):\n" . $lain->implode("\n"))];
    }

    /**
     * Deskripsi ditulis untuk web: ajakan menghubungi lewat WhatsApp, nomor, dan alamat luar
     * dibuang per kalimat, karena di chat Shopee semua itu melanggar aturan dan bisa diblokir.
     */
    private function deskripsiUntukShopee(string $deskripsi): string
    {
        $bersih = [];
        foreach (preg_split('/\R/u', html_entity_decode(strip_tags($deskripsi))) as $baris) {
            $kalimat = preg_split('/(?<=[.!?])\s+/u', trim($baris));
            $sisa = array_filter($kalimat, fn ($k) => ! preg_match('~whats\s*app|\bwa\b|https?://|www\.|\.com\b|\.co\.id\b|(\+?62|\b08)[\d\s\-]{7,}~iu', $k));
            if ($sisa !== []) {
                $bersih[] = implode(' ', $sisa);
            }
        }

        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $bersih)));
    }

    /** Deskripsi web sebagai teks biasa (tanpa HTML). */
    private function deskripsiPolos(string $deskripsi): string
    {
        return trim(preg_replace("/\n{3,}/", "\n\n", html_entity_decode(strip_tags($deskripsi))));
    }

    /* ------------------------------------------------------------ CS WhatsApp */

    private function skemaCekStokWa(): array
    {
        return [
            'name'        => 'cek_stok',
            'description' => 'Cek stok, harga, dan link halaman web sebuah barang katalog (nama atau SKU). Harga sudah '
                . 'termasuk diskon web. WAJIB dipakai sebelum menyebut stok, harga, atau mengirim link produk — jangan '
                . 'menjawab dari ingatan. Hasil lebih dari satu dan ragu yang dimaksud → tanya pembeli dulu.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'kata_kunci' => ['type' => 'string', 'description' => 'Nama barang atau SKU, mis. "kotak saran 3 kotak" atau "BC-2x2".'],
                ],
                'required' => ['kata_kunci'],
            ],
        ];
    }

    /** Pencarian luas CS Shopee (nama ERP, listing, teks web), dengan harga & link web. */
    private function cekStokWa(array $input): array
    {
        $kata = trim((string) ($input['kata_kunci'] ?? ''));
        if ($kata === '') {
            return ['content' => 'Kata kunci kosong. Sebutkan nama barang atau SKU-nya.'];
        }

        $hasil = $this->cariBarangShopee($kata);
        if (! $hasil) {
            return ['content' => 'Tidak ada barang yang cocok dengan "' . $kata . '". Jangan mengarang — tanya pembeli atau lempar ke admin.'];
        }

        $baris = array_map(function ($p) {
            $harga = 'harga Rp' . number_format((float) $p['harga'], 0, ',', '.');
            if ($p['harga_coret']) {
                $harga .= ' (turun dari Rp' . number_format((float) $p['harga_coret'], 0, ',', '.') . ')';
            }

            return '- ' . $p['nama'] . ($p['sku'] ? ' | SKU ' . $p['sku'] : '') . ' | ' . ($p['custom']
                ? 'barang custom (dibuat setelah dipesan)'
                : ($p['preorder'] ? 'PRE-ORDER, stok ' : 'ready, stok ') . $this->angka($p['stok']))
                . ' | ' . $harga . ($p['url'] ? ' | link ' . $p['url'] : '');
        }, $hasil);

        return ['content' => ($this->cocokSebagian ? self::CATATAN_SEBAGIAN . "\n" : '')
            . "Hasil cek stok, urut dari yang paling cocok dengan kata pembeli (sebutkan apa adanya, jangan dibulatkan; SKU hanya untukmu):\n" . implode("\n", $baris)];
    }

    /* ------------------------------------------------------------ cek ongkir (WA) */

    private function skemaCekOngkir(): array
    {
        return [
            'name'        => 'cek_ongkir',
            'description' => 'Hitung ongkir dari toko ke pembeli untuk barang katalog, lengkap dengan potongan ongkir promo '
                . '(sama dengan checkout web). WAJIB dipakai sebelum menyebut ongkir atau potongan ongkir. Butuh SKU dari '
                . 'cek_stok + jumlah. Kurir reguler: tujuan sampai kecamatan + kota (atau kode pos). Kurir instan '
                . '(GoSend/Grab, dalam kota): isi instan = true — dihitung dari shareloc terakhir pembeli di chat ini, '
                . 'jadi minta pembeli kirim lokasinya dulu.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'tujuan' => ['type' => 'string', 'description' => 'Kecamatan + kota tujuan, atau kode pos, mis. "Tembalang Semarang" atau "50275". Boleh kosong bila instan.'],
                    'instan' => ['type' => 'boolean', 'description' => 'true = kurir instan dari shareloc pembeli.'],
                    'barang' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'sku'    => ['type' => 'string', 'description' => 'SKU dari hasil cek_stok.'],
                                'jumlah' => ['type' => 'integer', 'description' => 'Jumlah barang.'],
                            ],
                            'required' => ['sku', 'jumlah'],
                        ],
                    ],
                ],
                'required' => ['barang'],
            ],
        ];
    }

    private function cekOngkir(array $input, ?CrmConversation $percakapan): array
    {
        $tujuan = trim((string) ($input['tujuan'] ?? ''));
        $instan = (bool) ($input['instan'] ?? false);
        $barang = collect($input['barang'] ?? [])->filter(fn ($b) => is_array($b) && trim((string) ($b['sku'] ?? '')) !== '');
        if ($barang->isEmpty() || (! $instan && $tujuan === '')) {
            return ['content' => 'Barang wajib diisi, dan tujuan untuk kurir reguler. Tanyakan barang + jumlah dan kecamatan + kota tujuan dulu.'];
        }

        $produk = Product::where('is_sellable', true)
            ->whereIn('sku', $barang->pluck('sku')->map(fn ($s) => trim((string) $s))->all())
            ->get(['id', 'sku', 'name'])->keyBy(fn ($p) => mb_strtolower($p->sku));
        $items = [];
        $hilang = [];
        foreach ($barang as $b) {
            $p = $produk->get(mb_strtolower(trim((string) $b['sku'])));
            if ($p) {
                $items[] = ['product_id' => $p->id, 'qty' => max(1, (int) ($b['jumlah'] ?? 1))];
            } else {
                $hilang[] = $b['sku'];
            }
        }
        if ($hilang) {
            return ['content' => 'SKU tidak dikenal: ' . implode(', ', $hilang) . '. Pakai SKU persis dari hasil cek_stok.'];
        }

        $ongkir = app(\App\Modules\Shipping\Services\OngkirEtalaseService::class);
        $label  = fn (array $a) => implode(', ', array_filter([$a['subdistrict'] ?? null, $a['district'] ?? null, $a['city'] ?? null, $a['province'] ?? null]))
            . (($a['postal_code'] ?? null) ? ' ' . $a['postal_code'] : '');
        $lain   = '';

        if ($instan) {
            $titik = $this->sharelocTerakhir($percakapan);
            if (! $titik) {
                return ['content' => 'Belum ada shareloc dari pembeli di chat ini (' . \App\Modules\CRM\Services\PesananAiService::JAM_SHARELOC . ' jam terakhir). '
                    . 'Minta pembeli kirim lokasi lewat fitur Lokasi WhatsApp (bukan ketik alamat), lalu cek lagi.'];
            }
            $w = $ongkir->wilayahDariTitik($titik['lat'], $titik['lng']);
            if (! $w['postal_code']) {
                return ['content' => 'Lokasi pembeli belum bisa dikenali kurir (' . ($w['error'] ?? 'kode pos kosong') . '). Lempar ke admin gudang.'];
            }
            $tempat = $w['address'] ?: ($titik['alamat'] ?: ($titik['nama'] ?: 'titik shareloc'));
            $hasil  = $ongkir->tarif([
                'mode' => 'instant', 'destination_area_id' => $w['area_id'], 'destination_postal_code' => $w['postal_code'],
                'destination_latitude' => $titik['lat'], 'destination_longitude' => $titik['lng'],
            ], $items);
            $judul = 'Ongkir kurir instan ke shareloc pembeli (' . mb_substr($tempat, 0, 120) . ')';
        } else {
            // Satu kata saja ("Semarang") ambigu: ada kelurahan bernama Semarang di Bengkulu & Banjarnegara.
            $kataTujuan = array_filter(preg_split('/[\s,.\/]+/u', $tujuan), fn ($w) => mb_strlen($w) >= 3 && ! ctype_digit($w));
            if (! preg_match('/\b\d{5}\b/', $tujuan) && count($kataTujuan) < 2) {
                return ['content' => 'Tujuan "' . $tujuan . '" kurang lengkap. Tanyakan kecamatan + kota (atau kode pos) pembeli dulu.'];
            }
            $wilayah = $ongkir->cariWilayahLonggar($tujuan);
            if (! $wilayah) {
                return ['content' => 'Wilayah "' . $tujuan . '" tidak ditemukan. Tanyakan kecamatan + kota (atau kode pos) pembeli.'];
            }
            $area  = $wilayah[0];
            $hasil = $ongkir->tarif(['destination_area_id' => $area['id'] ?? null, 'destination_postal_code' => $area['postal_code'] ?? null], $items);
            $judul = 'Ongkir ke ' . $label($area);
            $lain  = collect($wilayah)->slice(1, 3)->map($label)->implode(' | ');
        }

        if (! $hasil['rates']) {
            return ['content' => 'Ongkir gagal dihitung (' . (implode('; ', $hasil['errors']) ?: 'tidak ada kurir') . '). '
                . ($instan ? 'Mungkin di luar jangkauan kurir instan — tawarkan kurir reguler, atau lempar ke admin gudang.' : 'Jangan menebak ongkir: lempar ke admin gudang.')];
        }

        $berat = collect($ongkir->rateItems($items))->sum(fn ($r) => $r['weight'] * $r['quantity']);
        $rp    = fn (float $n) => 'Rp' . number_format($n, 0, ',', '.');

        $baris = collect($hasil['rates'])->sortBy('price')->take(6)->map(function ($r) use ($rp) {
            $harga = (float) $r['price'];
            $pot   = (float) ($r['discount'] ?? 0);

            return '- ' . \App\Modules\Shipping\Services\OngkirEtalaseService::namaLayanan($r) . ': ' . $rp($harga)
                . ($pot > 0 ? ', potongan ongkir ' . $rp($pot) . ' → dibayar ' . $rp($harga - $pot) : '')
                . (($r['etd'] ?? '') !== '' ? ', estimasi ' . $r['etd'] : '');
        });

        return ['content' => $judul . ' (berat paket ±' . $this->angka($berat / 1000) . ' kg):' . "\n"
            . $baris->implode("\n") . "\n"
            . ($lain !== '' ? 'Wilayah lain yang mirip: ' . $lain . '. Bila bukan yang dimaksud pembeli, tanyakan kecamatannya.' . "\n" : '')
            . ($instan ? 'Tarif instan bisa berubah saat dipesan (jarak & ketersediaan driver). ' : 'Estimasi dihitung sejak paket diserahkan ke kurir. ')
            . 'Tawarkan 2–3 pilihan saja. Sebut "potongan ongkir", jangan pernah "gratis ongkir".'];
    }

    /** @return array{lat: float, lng: float, nama: ?string, alamat: ?string}|null shareloc terbaru pembeli */
    private function sharelocTerakhir(?CrmConversation $percakapan): ?array
    {
        return $percakapan ? app(\App\Modules\CRM\Services\PesananAiService::class)->sharelocTerakhir($percakapan) : null;
    }

    /* ------------------------------------------------------------ buat pesanan (WA) */

    private function skemaBuatPesanan(): array
    {
        return [
            'name'        => 'buat_pesanan',
            'description' => 'Siapkan pesanan produk katalog READY setelah pembeli MENYETUJUI ringkasan (produk × jumlah, '
                . 'harga, ongkir, total). Sistem memeriksa stok, harga ERP, ongkir, dan batas total; bila lolos, nota + '
                . 'tautan bayar ditambahkan sistem di bawah balasanmu. Jangan dipakai untuk custom, pre-order, grosir, '
                . 'atau nego.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'nama_pemesan' => ['type' => 'string', 'description' => 'Nama untuk nota (wajib bila pembeli belum terdaftar).'],
                    'barang'       => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'sku'    => ['type' => 'string', 'description' => 'SKU dari hasil cek_stok.'],
                                'jumlah' => ['type' => 'integer', 'description' => 'Jumlah barang.'],
                            ],
                            'required' => ['sku', 'jumlah'],
                        ],
                    ],
                    'pengiriman' => ['type' => 'string', 'enum' => ['ambil', 'kurir', 'instan'], 'description' => 'ambil di toko, kurir reguler, atau kurir instan (dari shareloc).'],
                    'tujuan'     => ['type' => 'string', 'description' => 'Kurir reguler: kecamatan + kota atau kode pos, sama dengan saat cek_ongkir.'],
                    'alamat'     => ['type' => 'string', 'description' => 'Kurir/instan: alamat lengkap (jalan, nomor rumah, RT/RW, patokan).'],
                    'kurir'      => ['type' => 'string', 'description' => 'Kurir/instan: nama layanan persis dari hasil cek_ongkir, mis. "J&T EZ".'],
                ],
                'required' => ['barang', 'pengiriman'],
            ],
        ];
    }

    private function buatPesanan(array $input, ?CrmConversation $percakapan): array
    {
        if (! $percakapan) {
            return ['content' => 'Tidak ada percakapan — pesanan tidak bisa disiapkan.'];
        }

        $siap = app(\App\Modules\CRM\Services\PesananAiService::class)->siapkan($input, $percakapan);
        if (! $siap['ok']) {
            return ['content' => 'PESANAN DITOLAK: ' . $siap['pesan']];
        }

        return ['content' => self::PESANAN_SIAP . "\n" . $siap['ringkasan'] . "\n"
            . 'Pesanan BELUM dibuat: sistem membuat nota + tautan bayar saat balasanmu dikirim dan menempelkannya di bawah. '
            . 'Tulis balasan pengantar singkat saja (mis. "baik kak, ini rincian pesanan & tautan pembayarannya ya 🙏"), '
            . 'jangan menulis ulang rincian, nomor nota, atau tautan.'];
    }

    /** Awalan hasil buat_pesanan yang lolos pagar — SaranAiService membacanya dari jejak. */
    public const PESANAN_SIAP = 'PESANAN SIAP (lolos pemeriksaan):';

    private function skemaInfoPesananWa(): array
    {
        return [
            'name'        => 'info_pesanan',
            'description' => 'Pesanan ERP pelanggan WhatsApp ini (90 hari terakhir, maksimal 5): nomor nota, barang, '
                . 'tahap (pembayaran/produksi/packing/siap diambil/kirim/selesai), status bayar & sisa tagihan, '
                . 'diambil atau dikirim, kurir & resi. Pakai untuk "sudah jadi?", "sudah bisa diambil?", "resinya?", '
                . '"sudah masuk pembayarannya?".',
            'input_schema' => ['type' => 'object', 'properties' => (object) []],
        ];
    }

    /** SO milik pelanggan chat ini + SO tertaut ke chat (pesanan marketplace yang dibahas di WA). */
    private function infoPesananWa(?CrmConversation $percakapan): array
    {
        if (! $percakapan) {
            return ['content' => 'Tidak ada percakapan — tidak ada data pesanan.'];
        }

        $tertaut = app(\App\Modules\CRM\Services\LabelPesananService::class)->idTertaut($percakapan);
        if (! $percakapan->customer_id && ! $tertaut) {
            return ['content' => 'Nomor ini belum terhubung ke pelanggan ERP dan belum ada pesanan yang tertaut. '
                . 'Jangan menebak: tanya nomor nota / nomor pesanan atau nama pemesan, lalu lempar ke admin.'];
        }

        $pesanan = \App\Modules\Sales\Models\SalesOrder::query()
            ->where(fn ($q) => $q
                ->when($percakapan->customer_id, fn ($w) => $w->where('customer_id', $percakapan->customer_id))
                ->orWhereIn('id', $tertaut ?: [0]))
            ->whereNotIn('status', ['void', 'cancelled'])
            ->where('order_date', '>=', now()->subDays(90)->toDateString())
            ->with('items.product:id,name,sku')
            ->latest('id')->limit(5)->get();

        if ($pesanan->isEmpty()) {
            return ['content' => 'Pelanggan ini belum punya pesanan dalam 90 hari terakhir. Bila ia menyebut pesanan, '
                . 'tanya nomor notanya lalu lempar ke admin.'];
        }

        $progres = app(\App\Modules\Sales\Services\OrderProgressService::class);
        $baris = $pesanan->map(function ($so) use ($progres) {
            $p = $progres->for($so);
            $tahap = collect($p['steps'])->firstWhere('key', $p['current']);
            $barang = $so->items->map(fn ($i) => rtrim(rtrim(number_format((float) $i->qty, 2, ',', '.'), '0'), ',') . '× '
                . ($i->description ?: ($i->product?->name ?? 'Produk')))->implode('; ');
            $sisa = $so->status === 'draft' ? null : $so->sisaTagihan();

            return '- ' . $so->order_number . ($so->customer_po_number ? ' (no. marketplace ' . $so->customer_po_number . ')' : '')
                . ' | tanggal ' . optional($so->order_date)->format('d/m/Y')
                . ' | ' . ($so->status === 'draft' ? 'DRAFT, belum dikonfirmasi admin' : 'tahap ' . ($tahap['label'] ?? '—') . (($tahap['note'] ?? '') ? ' (' . $tahap['note'] . ')' : ''))
                . ' | pembayaran ' . ($p['payment']['label'] ?? '—') . ($sisa !== null && $sisa > 0 ? ', sisa Rp' . number_format($sisa, 0, ',', '.') : '')
                . ' | ' . ($so->delivery_method === 'ambil_toko' ? 'diambil di toko' : 'dikirim' . (($p['courier'] ?? null) ? ' ' . $p['courier'] : ''))
                . (($p['tracking_number'] ?? null) ? ' | resi ' . $p['tracking_number'] : '')
                . ' | total Rp' . number_format((float) $so->grand_total, 0, ',', '.')
                . " | {$barang}";
        });

        return ['content' => "Pesanan pelanggan ini (terbaru dulu; sebut status apa adanya, jangan menjanjikan tanggal):\n" . $baris->implode("\n")];
    }

    private function skemaInfoPesanan(): array
    {
        return [
            'name'        => 'info_pesanan',
            'description' => 'Pesanan Shopee pembeli ini (30 hari terakhir): status, waktu bayar, barang, '
                . 'ready/pre-order, butuh desain atau tidak, dan provinsi tujuan (Jawa / luar Jawa). '
                . 'Pakai untuk pertanyaan kapan dikirim, packing kayu, dan apa pun tentang pesanannya.',
            'input_schema' => ['type' => 'object', 'properties' => (object) []],
        ];
    }

    private function infoPesanan(?CrmConversation $percakapan): array
    {
        if (! $percakapan || ! $percakapan->shopee() || ! ctype_digit((string) $percakapan->contact_key)) {
            return ['content' => 'Bukan percakapan Shopee — tidak ada data pesanan.'];
        }

        $pesanan = ShopeeOrder::with('items')
            ->where('shop_id', (int) $percakapan->business_number_id)
            ->where('buyer_user_id', (int) $percakapan->contact_key)
            ->where('dibuat_shopee_at', '>=', now()->subDays(30))
            ->orderByDesc('dibuat_shopee_at')->limit(5)->get();

        if ($pesanan->isEmpty()) {
            return ['content' => 'Pembeli ini belum punya pesanan dalam 30 hari terakhir (mungkin masih bertanya sebelum order).'];
        }

        $provinsi = $this->provinsi($percakapan, $pesanan->pluck('order_sn')->all());
        $pola = app(DesainSkill::class)->polaSku();
        $baris = [];

        foreach ($pesanan as $o) {
            $barang = $o->items->map(function ($i) use ($pola) {
                $p = $i->product_id ? Product::find($i->product_id) : null;
                $sku = (string) ($i->sku ?: $p?->sku);
                $sifat = [$p?->isPreorder() ? 'pre-order' : 'ready'];
                if ($sku !== '' && collect($pola)->contains(fn ($x) => \Illuminate\Support\Str::is(str_replace('%', '*', $x), $sku))) {
                    $sifat[] = 'butuh desain';
                }

                return "{$i->qty}× " . ($sku ?: $i->nama) . ' (' . trim($i->nama . ' ' . $i->nama_model) . '; ' . implode(', ', $sifat) . ')';
            })->implode('; ');

            $prov = $provinsi[$o->order_sn] ?? null;
            $baris[] = "- {$o->order_sn} | status Shopee {$o->status} | dibuat " . $o->dibuat_shopee_at?->format('d/m H:i')
                . ' (' . $this->waktuPesanan($o) . ') | pembayaran ' . $this->statusBayar($o) . " | {$barang}"
                . ($prov ? ' | tujuan ' . $prov . (in_array(strtolower($prov), self::PROVINSI_JAWA, true) ? ' (Jawa)' : ' (LUAR JAWA)') : '');
        }

        return ['content' => "Pesanan pembeli ini (terbaru dulu):\n" . implode("\n", $baris)];
    }

    /** Dihitung di sini supaya aturan jam 12.00 tidak bergantung pada model membandingkan jam. */
    private function waktuPesanan(ShopeeOrder $o): string
    {
        $t = $o->dibuat_shopee_at;
        if (! $t || ! $t->isSameDay(now())) {
            return 'hari sebelumnya';
        }

        return ($t->hour < 12 ? 'sebelum' : 'setelah') . ' jam 12.00 hari ini';
    }

    /** COD tidak punya waktu bayar: tanpa cabang ini pesanan COD terbaca belum dibayar. */
    private function statusBayar(ShopeeOrder $o): string
    {
        if ($o->status === 'UNPAID') {
            return 'belum dibayar';
        }
        if ($t = data_get($o->raw, 'pay_time')) {
            return 'dibayar ' . Carbon::createFromTimestamp((int) $t, config('app.timezone'))->format('d/m H:i');
        }

        return data_get($o->raw, 'cod') ? 'COD, bayar saat barang diterima' : 'tidak ada waktu bayar';
    }

    /**
     * Provinsi tujuan, diambil langsung dari Shopee saat dibutuhkan — alamat pembeli
     * sengaja TIDAK disimpan di shopee_orders. Gagal = kosong, agen tinggal tak tahu.
     * Tanpa izin data sensitif Shopee menyamarkannya ("****"): itu juga dianggap tak tahu,
     * bukan luar Jawa.
     *
     * @return array<string, string> order_sn => provinsi
     */
    private function provinsi(CrmConversation $percakapan, array $sn): array
    {
        $shop = ShopeeShop::where('env', $this->shopee->env())->where('shop_id', (int) $percakapan->business_number_id)->first();
        if (! $shop || ! $sn) {
            return [];
        }

        try {
            $r = $this->shopee->panggil($shop, 'GET', '/api/v2/order/get_order_detail', [
                'order_sn_list' => implode(',', $sn), 'response_optional_fields' => 'recipient_address',
            ]);
        } catch (\Throwable $e) {
            report($e);
            return [];
        }

        return collect($r['order_list'] ?? [])
            ->mapWithKeys(fn ($o) => [(string) $o['order_sn'] => trim((string) data_get($o, 'recipient_address.state'))])
            ->filter(fn (string $p) => $p !== '' && ! str_contains($p, '*'))->all();
    }

    private function skemaUsulkan(bool $wa = false): array
    {
        $butir = $wa
            ? 'Kode butir daftar tanya-jawab yang dipakai, mis. "A1", "D1". "-" bila tidak ada yang cocok. "Z1" untuk ucapan penutup (wajib dibalas). "Z2" bila pesan tak perlu dibalas (notifikasi, OTP, spam, kurir, pemasok).'
            : 'Kode butir daftar tanya-jawab yang dipakai, mis. "A1", "F2". "-" bila tidak ada yang cocok. "I1" untuk ucapan penutup (wajib dibalas). "I2" bila pesan tak perlu dibalas.';

        return [
            'name'        => 'usulkan_balasan',
            'description' => 'WAJIB dipanggil tepat sekali di akhir: saran balasan untuk admin. Admin yang '
                . 'memutuskan mengirimnya. Panggil sesudah info yang perlu (stok/pesanan) sudah dicek.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'butir' => ['type' => 'string', 'description' => $butir],
                    'teks' => ['type' => 'string', 'description' => 'Saran balasan untuk pembeli: satu baris pendek gaya admin di HP. Boleh kosong bila tak perlu dibalas, atau bila dilempar tanpa balasan sementara.'],
                    'lempar_ke' => ['type' => 'string', 'enum' => ['tidak', 'desain', 'admin', 'owner'], 'description' => 'Siapa yang harus menangani: tidak (cukup saran ini), desain (desain untuk produk yang sudah ada, ke Admin Desain), owner (produk custom: bentuk, ukuran, atau bahan lain), atau admin (selain itu, ke Admin Layanan).'],
                    'alasan' => ['type' => 'string', 'description' => 'Satu kalimat untuk admin: kenapa butir ini / kenapa dilempar.'],
                ],
                'required' => ['butir', 'teks', 'lempar_ke', 'alasan'],
            ],
        ];
    }

    private function usulkan(array $input): array
    {
        return ['content' => 'Saran tersimpan. Selesai — jangan menulis apa pun lagi.', '_selesai' => true];
    }

    /* ------------------------------------------------------ lempar ke manusia */

    private function skemaLempar(): array
    {
        return [
            'name'        => 'lempar_ke_manusia',
            'description' => 'Serahkan percakapan ini ke tim. Pakai saat pelanggan menawar harga, '
                . 'saat pertanyaannya di luar pengetahuanmu, saat pelanggan kecewa, atau saat '
                . 'pembahasannya masuk ke keputusan yang mengikat. Ini jalan keluar yang sah — '
                . 'jauh lebih baik daripada mengarang jawaban. Sesudah memanggilnya, tulis satu '
                . 'kalimat pendek ke pelanggan bahwa kamu sambungkan ke tim.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'alasan' => [
                        'type'        => 'string',
                        'description' => 'Kenapa dilempar, satu kalimat. Dibaca tim, bukan pelanggan.',
                    ],
                ],
                'required' => ['alasan'],
            ],
        ];
    }

    private function lempar(array $input, ?CrmConversation $percakapan): array
    {
        $alasan = trim((string) ($input['alasan'] ?? '')) ?: 'tidak disebutkan';

        /*
         * Di mode uji percakapannya tidak ada, dan itu bukan galat: yang sedang
         * dinilai adalah KEPUTUSAN melempar, bukan efek sampingnya.
         */
        /*
         * Yang menandai "ini butuh manusia" adalah unread, BUKAN label.
         * Label percakapan diketik CS sendiri (Desain, Tanya Harga, Cetak);
         * agen yang menimpanya saat melempar akan menghapus kategori yang
         * baru saja dipasang orangnya — dan agen yang merusak catatan orang
         * lain adalah agen yang dimatikan.
         */
        if ($percakapan) {
            $percakapan->forceFill([
                'unread_count' => $percakapan->unread_count + 1,
            ])->save();
        }

        return [
            'content' => 'Sudah dilempar ke tim (alasan: ' . $alasan . '). '
                . 'Sekarang tulis satu kalimat pendek ke pelanggan bahwa kamu sambungkan ke tim. '
                . 'Jangan menjanjikan kapan tim membalas.',
            '_lempar' => true,
        ];
    }
}
