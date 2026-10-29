<?php

namespace App\Modules\CRM\Providers;

use App\Modules\CRM\Contracts\ChatProvider;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeClient;
use App\Modules\Marketplace\Shopee\Services\ShopeeException;
use Illuminate\Support\Facades\Storage;

/**
 * Jalur kirim percakapan kanal Shopee — Tahap B: teks & gambar lewat sellerchat/send_message.
 *
 * Ada supaya ChatManager::untuk() punya jawaban untuk chat Shopee selain jalur WhatsApp.
 * Tanpa kelas ini percakapan Shopee jatuh ke provider WA, dan balasan CS berangkat sebagai
 * WhatsApp ke "nomor" yang sebenarnya user id Shopee.
 *
 * Bentuk payload sama dengan provider WA: 'to' = user id pembeli (contact_key),
 * 'phone_number_id' = shop_id (business_number_id). Id pesan dikembalikan berawalan
 * `shopee:` — SAMA dengan yang dipakai ShopeeChatService saat menyalin, jadi sinkron tiap
 * menit mengenali balasan ini sebagai sudah ada dan tidak menggandakannya.
 *
 * Shopee tidak mengenal caption, dokumen, tombol, maupun template: gambar dikirim polos
 * (CrmReplyService mengirim teksnya sebagai pesan terpisah), sisanya ditolak dengan alasan.
 */
class ShopeeChatProvider implements ChatProvider
{
    private const BUKAN_SHOPEE = 'Fitur ini tidak ada di chat Shopee.';

    /** Batas upload_image Shopee. */
    private const MAKS_GAMBAR = 10 * 1024 * 1024;

    public function __construct(private ShopeeClient $client)
    {
    }

    public function key(): string
    {
        return 'shopee';
    }

    public function isReady(): bool
    {
        return $this->client->siap() && $this->toko(null) !== null;
    }

    public function sendText(array $payload): array
    {
        $teks = trim((string) ($payload['text'] ?? ''));
        if ($teks === '') {
            return $this->gagal('Pesan kosong.');
        }

        return $this->kirimPesan($payload, 'text', ['text' => $teks]);
    }

    /**
     * Hanya gambar. Berkasnya dibaca dari disk kita ('berkas_disk' + 'berkas_path'), diunggah
     * ke Shopee, lalu URL hasilnya dikirim — Shopee tidak mengambil sendiri dari URL kita.
     */
    public function sendMedia(array $payload): array
    {
        if (($payload['type'] ?? '') !== 'image') {
            return $this->gagal('Chat Shopee hanya bisa mengirim gambar (JPG/PNG) — berkas lain kirim lewat Seller Centre.');
        }

        $disk = $payload['berkas_disk'] ?? null;
        $path = $payload['berkas_path'] ?? null;
        if (!$disk || !$path || !Storage::disk($disk)->exists($path)) {
            return $this->gagal('Berkas gambar tidak ditemukan di penyimpanan ERP.');
        }
        if (Storage::disk($disk)->size($path) > self::MAKS_GAMBAR) {
            return $this->gagal('Gambar melebihi batas Shopee 10 MB.');
        }

        $shop = $this->toko($payload['phone_number_id'] ?? null);
        if (!$shop) {
            return $this->gagal('Toko Shopee belum terhubung di Pengaturan › Integrasi › Shopee.');
        }

        try {
            $unggah = $this->client->unggah($shop, '/api/v2/sellerchat/upload_image',
                Storage::disk($disk)->get($path), (string) ($payload['nama_berkas'] ?? basename($path)));
        } catch (ShopeeException $e) {
            return $this->gagal('Gagal mengunggah gambar ke Shopee: ' . $e->getMessage());
        }

        $url = (string) ($unggah['url'] ?? '');
        if ($url === '') {
            return $this->gagal('Shopee tidak mengembalikan alamat gambar.');
        }

        return $this->kirimPesan($payload, 'image', ['image_url' => $url], $shop);
    }

    /**
     * Tandai percakapan sudah dibaca di Shopee (sampai pesan $idPesanTerakhir).
     * Pembeli melihat status "Dibaca" — pemanggil yang memutuskan kapan.
     *
     * @return array{success:bool, error:?string}
     */
    public function tandaiDibaca(string $shopId, string $conversationId, string $idPesanTerakhir): array
    {
        $shop = $this->toko($shopId);
        if (!$shop) {
            return ['success' => false, 'error' => 'Toko Shopee belum terhubung di Pengaturan › Integrasi › Shopee.'];
        }

        try {
            $this->client->panggil($shop, 'POST', '/api/v2/sellerchat/read_conversation', [
                'conversation_id'      => (int) $conversationId,
                'last_read_message_id' => $this->idShopee($idPesanTerakhir),
            ]);
        } catch (ShopeeException $e) {
            return ['success' => false, 'error' => 'Shopee menolak: ' . $e->getMessage()];
        }

        return ['success' => true, 'error' => null];
    }

    public function sendInteraktif(array $payload): array
    {
        return $this->gagal(self::BUKAN_SHOPEE);
    }

    public function sendTemplate(array $payload): array
    {
        return $this->gagal(self::BUKAN_SHOPEE);
    }

    public function phoneNumbers(): array
    {
        return ['success' => false, 'numbers' => [], 'error' => self::BUKAN_SHOPEE];
    }

    public function webhooks(): array
    {
        return ['success' => false, 'endpoints' => [], 'error' => self::BUKAN_SHOPEE];
    }

    public function enableWebhook(string $id): array
    {
        return ['success' => false, 'error' => self::BUKAN_SHOPEE];
    }

    public function uploadMedia(string $absolutePath, string $mime, string $filename): array
    {
        return ['success' => false, 'media_id' => null, 'error' => self::BUKAN_SHOPEE];
    }

    public function templates(): array
    {
        return ['success' => false, 'templates' => [], 'error' => self::BUKAN_SHOPEE];
    }

    public function buatTemplate(string $nama, string $kategori, string $body, array $variabel = [], string $bahasa = 'id', array $tombol = []): array
    {
        return ['success' => false, 'id' => null, 'status' => null, 'error' => self::BUKAN_SHOPEE];
    }

    /** Chat Shopee tak berjendela — selalu terbuka. */
    public function windowStatus(string $identifier): array
    {
        return ['success' => true, 'is_open' => true, 'expires_at' => null, 'raw' => [], 'error' => null];
    }

    public function profilKontak(string $identifier): array
    {
        return ['success' => false, 'name' => null, 'error' => self::BUKAN_SHOPEE];
    }

    /* ---------------------------------------------------------------- bantuan */

    private function kirimPesan(array $payload, string $jenis, array $isi, ?ShopeeShop $shop = null): array
    {
        $tujuan = (int) ($payload['to'] ?? 0);
        if ($tujuan <= 0) {
            return $this->gagal('User id pembeli Shopee tidak valid.');
        }

        $shop ??= $this->toko($payload['phone_number_id'] ?? null);
        if (!$shop) {
            return $this->gagal('Toko Shopee belum terhubung di Pengaturan › Integrasi › Shopee.');
        }

        try {
            $r = $this->client->panggil($shop, 'POST', '/api/v2/sellerchat/send_message', [
                'to_id' => $tujuan, 'message_type' => $jenis, 'content' => $isi,
            ]);
        } catch (ShopeeException $e) {
            return $this->gagal('Shopee menolak pesan: ' . $e->getMessage());
        }

        $id = (string) ($r['message_id'] ?? '');

        return [
            'success'     => true,
            'message_id'  => $id !== '' ? 'shopee:' . $id : null,
            'customer_id' => isset($r['conversation_id']) ? (string) $r['conversation_id'] : null,
            'raw'         => $r,
            'error'       => null,
        ];
    }

    /** Toko di lingkungan aktif; shop_id kosong = satu-satunya toko yang terhubung. */
    private function toko(?string $shopId): ?ShopeeShop
    {
        $toko = ShopeeShop::where('env', $this->client->env())
            ->when($shopId, fn ($q) => $q->where('shop_id', (int) $shopId))
            ->orderByDesc('terhubung_at')
            ->first();

        return $toko && !$toko->perluHubungkanUlang() ? $toko : null;
    }

    private function idShopee(string $id): string
    {
        return str_starts_with($id, 'shopee:') ? substr($id, 7) : $id;
    }

    private function gagal(string $pesan): array
    {
        return ['success' => false, 'message_id' => null, 'customer_id' => null, 'raw' => [], 'error' => $pesan];
    }
}
