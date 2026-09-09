<?php

namespace App\Modules\CRM\Services;

use App\Core\Inventory\Product;
use App\Core\Inventory\Services\StokTersediaService;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmOutboxMessage;
use App\Modules\CRM\Models\CrmStockWatch;
use App\Modules\CRM\Support\JenisNotifikasi;
use App\Models\StoreProductVariant;
use App\Modules\CRM\Support\PhoneNumber;
use Illuminate\Support\Collection;

/**
 * Titipan "kabari saya kalau stoknya ada": menandai, memeriksa, mengabari.
 *
 * Ini jenis kehilangan penjualan yang paling mudah diselamatkan — pembelinya
 * sudah mau, cuma datang di waktu yang salah. Selama janji itu hidup di kepala
 * admin, ia hampir tak pernah ditepati: barangnya masuk berminggu kemudian,
 * saat percakapannya sudah terkubur di dasar inbox.
 *
 * Seperti OrderNotificationService, tugasnya berhenti di ANTREAN — pengiriman
 * sungguhan urusan CrmOutboxSender. Itu yang membuat seluruh aturan di bawah
 * bisa diuji tanpa jaringan, dan membuat tombol periksa manual menempuh jalan
 * yang sama persis dengan pemicu otomatisnya.
 */
class StockWatchService
{
    public function __construct(private StokTersediaService $stok)
    {
    }

    /**
     * Tandai satu SKU untuk sebuah percakapan.
     *
     * Menandai ulang pasangan yang sama TIDAK melahirkan titipan kedua — ia
     * memperbarui jumlah yang ditunggu. Dua baris aktif untuk chat & produk
     * yang sama berarti pelanggan menerima kabar yang sama dua kali, dan
     * basis data pun menolaknya lewat indeks unik.
     */
    public function tandai(CrmConversation $chat, Product $product, float $qty = 1, ?int $userId = null): CrmStockWatch
    {
        $qty = max(1.0, $qty);

        $watch = CrmStockWatch::aktif()
            ->where('conversation_id', $chat->id)
            ->where('product_id', $product->id)
            ->first();

        if ($watch) {
            $watch->forceFill(['qty' => $qty])->save();

            return $watch;
        }

        return CrmStockWatch::create([
            'conversation_id' => $chat->id,
            'product_id'      => $product->id,
            'qty'             => $qty,
            // Nomor disalin sekarang: percakapan bisa berganti nama atau
            // dialihkan, tapi yang menitipkan tetap nomor ini.
            'recipient'       => PhoneNumber::normalize($chat->contact_key),
            'aktif'           => true,
            'created_by'      => $userId,
        ]);
    }

    /** Batalkan titipan tanpa mengabari (pelanggan berubah pikiran, salah tandai). */
    public function batalkan(CrmStockWatch $watch): void
    {
        $watch->lepas();
    }

    /** @return Collection<int,CrmStockWatch> titipan aktif milik satu percakapan */
    public function untukPercakapan(CrmConversation $chat): Collection
    {
        return CrmStockWatch::aktif()
            ->where('conversation_id', $chat->id)
            ->with('product:id,name,sku')
            ->orderBy('id')
            ->get();
    }

    /**
     * Periksa titipan, antrekan kabar untuk yang stoknya sudah cukup.
     *
     * Dipanggil DUA tempat yang sengaja memakai jalan yang sama: observer stok
     * (segera setelah transaksinya benar-benar tersimpan) dan perintah
     * terjadwal `crm:cek-stok-titipan` sebagai jaring pengaman. Observer bisa
     * tidak menyala untuk perubahan yang tak lewat ledger; cron menutup celah
     * itu tanpa perlu ada yang mengingat.
     *
     * @param  int[]|null  $productIds  batasi ke SKU tertentu; null = semuanya
     * @return array{diperiksa:int, dikabari:int, dilewati:int}
     */
    public function periksa(?array $productIds = null): array
    {
        $hasil = ['diperiksa' => 0, 'dikabari' => 0, 'dilewati' => 0];

        $watches = CrmStockWatch::aktif()
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds))
            ->with(['product', 'conversation'])
            ->get();

        if ($watches->isEmpty()) {
            return $hasil;
        }

        $hasil['diperiksa'] = $watches->count();

        $tersedia = $this->stok->untuk($watches->pluck('product_id')->unique());

        foreach ($watches as $watch) {
            $ada = (float) ($tersedia[$watch->product_id] ?? 0);

            // Pembeli yang menunggu 10 pcs tidak terbantu oleh kabar saat yang
            // masuk cuma 1 — ia datang, lalu pulang dengan tangan kosong.
            if ($ada < $watch->qty) {
                continue;
            }

            if ($this->kabari($watch)) {
                $hasil['dikabari']++;
            } else {
                $hasil['dilewati']++;
            }
        }

        return $hasil;
    }

    /**
     * Alamat halaman produk di etalase — jalan tercepat pelanggan meneruskan
     * niatnya sendiri.
     *
     * Kabar ini PEMBERITAHUAN, bukan ajakan membalas chat: yang dikirim lewat
     * jalur notifikasi tidak selalu dibaca admin yang sama, dan balasan atasnya
     * gampang menghilang. Karena itu jalan lanjutannya ditunjukkan terang-
     * terangan — halaman produknya, atau (bila SKU ini memang tak punya
     * halaman terbit) etalase depan, supaya kalimatnya tidak pernah berakhir
     * dengan tautan kosong.
     */
    private function tautanProduk(Product $product): string
    {
        $basis = rtrim((string) config('crm.storefront_url'), '/');

        $slug = StoreProductVariant::where('product_id', $product->id)
            ->whereHas('storeProduct', fn ($q) => $q->published())
            ->with('storeProduct:id,slug')
            ->get()
            ->map(fn ($v) => $v->storeProduct?->slug)
            ->filter()
            ->first();

        return $slug ? $basis . '/produk/' . $slug : $basis;
    }

    /**
     * Antrekan satu kabar & lepas tandanya.
     *
     * Tanda dilepas HANYA kalau barisnya benar-benar masuk antrean. Kalau
     * dilepas lebih dulu lalu antreannya gagal dibuat, titipannya lenyap tanpa
     * pernah ada yang dikabari — persis kegagalan yang fitur ini dibuat untuk
     * mencegah.
     */
    private function kabari(CrmStockWatch $watch): bool
    {
        $chat    = $watch->conversation;
        $product = $watch->product;

        if (! $chat || ! $product) {
            return false;
        }

        /*
         * Jenisnya sedang dimatikan → titipannya DIBIARKAN aktif, tidak
         * diantrekan sebagai 'dilewati'. Beda dari notifikasi pesanan, yang
         * peristiwanya lewat dan tak akan datang lagi: stok yang ada hari ini
         * masih ada besok, jadi menyimpan titipannya berarti pelanggan tetap
         * dikabari begitu jenis ini dinyalakan lagi. Mengantrekannya sebagai
         * 'dilewati' justru membakar kunci dedupe-nya — kabarnya tak akan
         * pernah bisa berangkat lagi.
         */
        if (! JenisNotifikasi::aktif(CrmOutboxMessage::EVENT_STOK_TERSEDIA)) {
            return false;
        }

        $nomor = $watch->recipient ?: PhoneNumber::normalize($chat->contact_key);

        if (! $nomor) {
            return false;
        }

        $baris = CrmOutboxMessage::antrekan('stok:watch:' . $watch->id, [
            'event'           => CrmOutboxMessage::EVENT_STOK_TERSEDIA,
            'conversation_id' => $chat->id,
            'recipient'       => $nomor,
            'template_name'   => CrmOutboxMessage::TEMPLATES[CrmOutboxMessage::EVENT_STOK_TERSEDIA],
            'template_body'   => [
                $chat->namaTampil(),
                $product->name,
                $this->tautanProduk($product),
                (string) config('crm.admin_phone'),
            ],
            'status'          => CrmOutboxMessage::STATUS_MENUNGGU,
            // Segera. Kabar stok memang bisa menunggu jam sopan, tapi
            // pengirimnya bukan yang memutuskan itu — CrmOutboxSender yang
            // menjadwal, dan untuk jenis ini "segera" memang jawabannya:
            // stok yang ditunggu orang lain bisa habis dalam sejam.
            'scheduled_at'    => null,
        ]);

        if (! $baris) {
            /*
             * Kunci dedupe sudah terpakai — kabarnya pernah diantrekan untuk
             * titipan ini. Tandanya tetap dilepas: membiarkannya aktif membuat
             * tiap putaran pemeriksaan mencoba ulang selamanya tanpa pernah
             * ada yang berubah.
             */
            $watch->lepas();

            return false;
        }

        $watch->lepas($baris->id);

        return true;
    }
}
