<?php

namespace App\Modules\Analysis\Services;

use App\Core\Inventory\Product;
use App\Models\ProductPrice;
use App\Modules\Analysis\Models\ProductChannelPrice;
use App\Modules\Marketplace\Jubelio\Services\JubelioClient;
use App\Modules\Marketplace\Jubelio\Services\JubelioProductSyncService;

/**
 * "Samakan semua marketplace dengan harga dasar" — satu tombol untuk pekerjaan yang selama
 * ini dilakukan berkali-kali: buka kanal Shopee, ketik harga, kirim; buka TikTok/Tokopedia,
 * ketik harga yang sama, kirim; begitu seterusnya.
 *
 * Yang dikerjakan berurutan:
 *   1. harga dasar ditulis ke master harga produk (web + ERP),
 *   2. harga tiap kanal marketplace DISALIN dari harga dasar itu — salinan, bukan tautan,
 *   3. salinannya langsung dikirim ke toko masing-masing lewat Jubelio.
 *
 * ── KENAPA SALINAN, BUKAN "IKUT HARGA DASAR" ──────────────────────────────
 *
 * Godaannya adalah mengosongkan harga kanal supaya barisnya berbunyi "ikut harga dasar" dan
 * ikut berubah sendiri setiap kali harga web diubah. Tapi yang menentukan harga di tokonya
 * bukan tabel kita — melainkan harga toko yang sudah terlanjur dipegang Jubelio. Kanal yang
 * pernah dikirimi harga khusus TIDAK kembali ke harga dasar hanya karena kolom di ERP
 * dikosongkan; ERP akan menampilkan harga dasar sementara tokonya menjual di harga lama,
 * dan tidak ada yang tahu sampai seseorang menarik harga marketplace.
 *
 * Karena itu yang disimpan adalah angka yang BENAR-BENAR dikirim, lengkap dengan `pushed_at`.
 * Konsekuensinya jujur: mengubah harga web besok tidak ikut mengubah marketplace sampai
 * tombol ini ditekan lagi — dan halaman Website memang menandai kanal yang harganya sudah
 * berbeda supaya perbedaan itu terbaca, bukan tersembunyi.
 */
class BasePriceRolloutService
{
    public function __construct(
        protected ChannelPricingService $pricing,
        protected JubelioProductSyncService $sync,
        protected JubelioClient $client,
    ) {
    }

    /**
     * Tulis harga jual asli produk. Mengikuti jalur yang sama dengan form harga di master
     * produk: baris `product_prices` yang sudah ada diperbarui (bukan dibuat baru dengan
     * satuan berbeda, yang akan menyisakan dua harga untuk satu produk), dan `base_price`
     * ikut disamakan supaya halaman HPP tidak menampilkan harga basi.
     */
    public function saveBasePrice(Product $product, float $price): void
    {
        $existing = ProductPrice::where('product_id', $product->id)
            ->where('channel', 'default')
            ->orderBy('id')
            ->first();

        if ($existing) {
            $existing->update(['price' => $price]);
        } else {
            ProductPrice::create([
                'product_id' => $product->id,
                'unit_name'  => $product->base_unit ?: 'pcs',
                'channel'    => 'default',
                'price'      => $price,
            ]);
        }

        $product->forceFill(['base_price' => $price])->save();
    }

    /** Harga dasar yang sedang berlaku — sumber yang sama dengan yang dibaca web & ERP. */
    public function currentBasePrice(Product $product): ?float
    {
        $row = ProductPrice::where('product_id', $product->id)
            ->where('channel', 'default')
            ->orderBy('id')
            ->value('price');

        return (float) ($row ?: $product->base_price) ?: null;
    }

    /**
     * Berlakukan harga dasar di seluruh kanal marketplace sekaligus kirim ke tokonya.
     *
     * @return array{
     *     price:float, ok:bool, pesan:string,
     *     terkirim:array<int,string>, gagal:array<int,string>,
     *     tanpa_toko:array<int,string>, ditimpa:array<int,string>, dasar:string,
     *     dicek:?array{ok:bool, sesuai:bool, per_toko:array<int,?float>, harga:?float, message:?string}
     * }
     */
    public function applyToMarketplaces(Product $product, float $price): array
    {
        $this->saveBasePrice($product, $price);

        $hasil = [
            'price'      => $price,
            'terkirim'   => [],
            'gagal'      => [],
            'tanpa_toko' => [],
            'ditimpa'    => [],
            'dasar'      => 'skipped',
            'dicek'      => null,
        ];

        // Toko yang benar-benar dikirimi, dikumpulkan lintas kanal: pengecekan baliknya
        // satu panggilan untuk seluruh produk, bukan satu per kanal.
        $tokoTerkirim = [];

        // Jubelio mati = jangan buang panggilan API satu per kanal hanya untuk mengumpulkan
        // tiga pesan "SKU tidak ditemukan" yang menyesatkan. Harganya tetap disimpan; yang
        // belum terjadi cuma pengirimannya, dan itu yang dikatakan apa adanya.
        $tersambung = $this->client->isReady();

        foreach ($this->pricing->channels()->reject(fn ($c) => $c['kind'] === 'internal') as $channel) {
            $row = ProductChannelPrice::firstOrNew([
                'product_id' => $product->id,
                'channel'    => $channel['key'],
            ]);

            // Harga khusus yang sengaja dibuat berbeda memang ditimpa — itu maunya tombol
            // ini. Tapi jangan diam-diam: angka lamanya ikut dilaporkan supaya orang tahu
            // apa yang barusan hilang.
            if ($row->price !== null && round((float) $row->price) !== round($price)) {
                $hasil['ditimpa'][] = $channel['label'] . ' (' . $this->rp((float) $row->price) . ')';
            }

            $row->price = $price;
            $row->save();

            if (!$tersambung) {
                continue;
            }
            if (empty($channel['store_ids'])) {
                $hasil['tanpa_toko'][] = $channel['label'];
                continue;
            }

            $kirim = $this->sync->pushStorePrice($product, $channel['store_ids'], $price);

            if ($kirim['ok']) {
                $row->forceFill(['pushed_price' => $price, 'pushed_at' => now()])->save();
                $hasil['terkirim'][] = $channel['label'];
                $tokoTerkirim        = array_merge($tokoTerkirim, $channel['store_ids']);
            } else {
                $hasil['gagal'][] = $channel['label'] . ': ' . $kirim['message'];
            }
        }

        if ($tersambung) {
            $hasil['dasar'] = $this->pushBasePrice($product);
        }

        // Dicek balik SESUDAH harga dasar ikut dikirim: toko yang tidak punya harga khusus
        // membaca harga dasar, jadi menanyakannya lebih awal akan melaporkan angka yang
        // sebentar lagi berubah sendiri.
        if ($tokoTerkirim) {
            $hasil['dicek'] = $this->sync->verifyStorePrice($product, $tokoTerkirim, $price);
        }

        // Beda saat dicek balik bukan kegagalan pengiriman — tapi juga bukan "beres", karena
        // yang tayang di tokonya belum tentu harga ini.
        $hasil['ok']    = empty($hasil['gagal']) && $tersambung
                          && !($hasil['dicek'] && $hasil['dicek']['ok'] && !$hasil['dicek']['sesuai']);
        $hasil['pesan'] = $this->ringkas($product, $hasil, $tersambung);

        return $hasil;
    }

    /**
     * Harga dasar Jubelio (store_id -1) ikut disamakan — itu yang dipakai toko yang belum
     * dipetakan ke kanal manapun.
     *
     * Hanya untuk produk yang memang disinkronkan; produk lain bukan urusan Jubelio dan
     * memaksanya hanya menghasilkan panggilan API sia-sia beserta catatan gagal. Penandanya
     * dimatikan sesudah berhasil supaya cron tidak mengirim ulang hal yang sama.
     */
    protected function pushBasePrice(Product $product): string
    {
        if (!$product->sync_to_jubelio) {
            return 'skipped';
        }

        $hasil = $this->sync->pushPrice($product->refresh());

        if ($hasil !== 'failed') {
            $product->forceFill(['jubelio_price_pending' => false])->save();
        }

        return $hasil;
    }

    /** Ringkasan satu kalimat — dipakai flash halaman, siap dipakai ulang perintah massal. */
    protected function ringkas(Product $product, array $hasil, bool $tersambung): string
    {
        $harga  = $this->rp($hasil['price']);
        $bagian = [];

        // Yang ditimpa selalu disebut — juga saat pengirimannya gagal, karena penimpaannya
        // sudah terjadi di ERP dan angka lamanya tidak bisa dilihat lagi di mana pun.
        if ($hasil['ditimpa']) {
            $bagian[] = 'harga khusus yang ditimpa: ' . implode(', ', $hasil['ditimpa']);
        }

        if (!$tersambung) {
            return "{$product->name} — harga {$harga} disalin ke semua kanal marketplace, tapi Jubelio belum tersambung"
                . ' sehingga belum berlaku di tokonya (cek Pengaturan ▸ Integrasi, lalu tekan lagi)'
                . ($bagian ? '; ' . implode('; ', $bagian) : '') . '.';
        }

        if ($hasil['terkirim']) {
            array_unshift($bagian, 'terkirim ke ' . implode(', ', $hasil['terkirim']));
        }
        if ($hasil['tanpa_toko']) {
            $bagian[] = implode(', ', $hasil['tanpa_toko']) . ' belum punya toko Jubelio yang dipetakan';
        }
        if ($hasil['gagal']) {
            $bagian[] = 'gagal — ' . implode('; ', $hasil['gagal']);
        }
        if ($cek = $this->ringkasCek($hasil['dicek'] ?? null)) {
            $bagian[] = $cek;
        }

        return "{$product->name} — harga {$harga} diberlakukan di semua marketplace"
            . ($bagian ? ' (' . implode('; ', $bagian) . ').' : '.');
    }

    /**
     * Hasil pengecekan balik dalam satu potong kalimat.
     *
     * Beda angka TIDAK disebut sebagai "gagal": paling sering Jubelio cuma belum sempat
     * memproses, dan menuduhnya gagal membuat orang mengirim ulang hal yang sebenarnya
     * sudah benar. Yang dilaporkan angkanya, beserta dua kemungkinan sebabnya.
     */
    protected function ringkasCek(?array $cek): ?string
    {
        if (!$cek) {
            return null;
        }
        if (!$cek['ok']) {
            return 'belum bisa dicek balik ke Jubelio (' . $cek['message'] . ')';
        }
        if ($cek['sesuai']) {
            return 'dicek balik: Jubelio memang sudah memegang harga itu';
        }

        $dipegang = $cek['harga'] !== null ? $this->rp($cek['harga']) : 'harga yang berbeda antar-toko';

        return "dicek balik: Jubelio masih memegang {$dipegang} — biasanya belum sempat diproses,"
            . ' tarik harga marketplace sebentar lagi; kalau tetap beda, harganya diubah dari luar ERP';
    }

    protected function rp(float $value): string
    {
        return 'Rp' . number_format($value, 0, ',', '.');
    }
}
