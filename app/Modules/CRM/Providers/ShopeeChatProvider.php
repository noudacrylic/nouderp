<?php

namespace App\Modules\CRM\Providers;

use App\Modules\CRM\Contracts\ChatProvider;

/**
 * Jalur kirim percakapan kanal Shopee — Tahap A: BACA SAJA.
 *
 * Ada supaya ChatManager::untuk() punya jawaban untuk chat Shopee selain jalur WhatsApp.
 * Tanpa kelas ini percakapan Shopee jatuh ke provider WA, dan balasan CS berangkat sebagai
 * WhatsApp ke "nomor" yang sebenarnya user id Shopee. Semua kiriman ditolak dengan alasan
 * yang terbaca; balas lewat Seller Centre sampai Tahap B (send_message) dibangun.
 */
class ShopeeChatProvider implements ChatProvider
{
    private const ALASAN = 'Chat Shopee belum bisa dibalas dari ERP (baca saja) — balas lewat Seller Centre.';

    public function key(): string
    {
        return 'shopee';
    }

    public function isReady(): bool
    {
        return false;
    }

    public function phoneNumbers(): array
    {
        return ['success' => false, 'numbers' => [], 'error' => self::ALASAN];
    }

    public function sendText(array $payload): array
    {
        return $this->tolak();
    }

    public function sendMedia(array $payload): array
    {
        return $this->tolak();
    }

    public function sendInteraktif(array $payload): array
    {
        return $this->tolak();
    }

    public function sendTemplate(array $payload): array
    {
        return $this->tolak();
    }

    public function webhooks(): array
    {
        return ['success' => false, 'endpoints' => [], 'error' => self::ALASAN];
    }

    public function enableWebhook(string $id): array
    {
        return ['success' => false, 'error' => self::ALASAN];
    }

    public function uploadMedia(string $absolutePath, string $mime, string $filename): array
    {
        return ['success' => false, 'media_id' => null, 'error' => self::ALASAN];
    }

    public function templates(): array
    {
        return ['success' => false, 'templates' => [], 'error' => self::ALASAN];
    }

    public function buatTemplate(string $nama, string $kategori, string $body, array $variabel = [], string $bahasa = 'id', array $tombol = []): array
    {
        return ['success' => false, 'id' => null, 'status' => null, 'error' => self::ALASAN];
    }

    public function windowStatus(string $identifier): array
    {
        return ['success' => false, 'is_open' => false, 'expires_at' => null, 'raw' => [], 'error' => self::ALASAN];
    }

    public function profilKontak(string $identifier): array
    {
        return ['success' => false, 'name' => null, 'error' => self::ALASAN];
    }

    private function tolak(): array
    {
        return ['success' => false, 'message_id' => null, 'customer_id' => null, 'raw' => [], 'error' => self::ALASAN];
    }
}
