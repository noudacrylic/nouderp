<?php

namespace App\Modules\CRM\Providers;

use App\Modules\CRM\Contracts\ChatProvider;
use App\Modules\CRM\Support\PhoneNumber;

/**
 * Driver palsu: mencatat, tidak pernah menyentuh jaringan.
 *
 * Dua peran, dan keduanya penting:
 *  1. PENGUJIAN — seluruh alur notifikasi, triase, dan kepemilikan chat bisa
 *     diuji tanpa satu pun pesan nyata, tanpa akun vendor, tanpa biaya.
 *  2. SAKLAR "JANGAN KIRIM" — saat config('crm.dry_run') menyala, ChatManager
 *     menyerahkan driver ini ke semua pemanggil. Jadi mematikan pengiriman
 *     bukan urusan percabangan if di banyak tempat, melainkan satu pertukaran
 *     driver: yang seharusnya terkirim tetap tercatat lengkap.
 *
 * Instance-nya sengaja disimpan sebagai singleton di container (lihat
 * ChatManager) supaya tes bisa memeriksa apa yang "terkirim".
 */
class FakeChatProvider implements ChatProvider
{
    /** Semua panggilan kirim, terbaru di belakang. @var array<int,array> */
    public array $sent = [];

    /** Bila diisi, panggilan kirim berikutnya gagal dengan pesan ini. */
    public ?string $failWith = null;

    /** Jawaban windowStatus() yang dipalsukan. */
    public bool $windowOpen = true;

    /** Endpoint webhook palsu; layar Pengaturan memakainya untuk uji tampilan. */
    public array $webhookEndpoints = [[
        'id'             => 'fake-webhook-1',
        'url'            => 'https://contoh.test/crm/webhook',
        'is_active'      => true,
        'failure_count'  => 0,
        'disabled_at'    => null,
        'disable_reason' => null,
        'events'         => ['message.received', 'message.sent'],
    ]];

    public function key(): string
    {
        return 'fake';
    }

    public function isReady(): bool
    {
        return true;
    }

    public function phoneNumbers(): array
    {
        return [
            'success' => true,
            'numbers' => [[
                'id'              => 'fake-number-1',
                'phone_number_id' => '000000000000000',
                'display'         => '+62 899-8844-666',
                'verified_name'   => 'Noud Acrylic',
                'quality_rating'  => 'GREEN',
                'is_primary'      => true,
                'waba_id'         => 'fake-waba',
            ]],
            'error' => null,
        ];
    }

    public function sendText(array $payload): array
    {
        return $this->record('text', $payload);
    }

    public function sendMedia(array $payload): array
    {
        return $this->record('media', $payload);
    }

    public function sendTemplate(array $payload): array
    {
        // Susun components lewat kelas asli, supaya bentuk payload yang diuji
        // di sini benar-benar bentuk yang akan dikirim ke api.co.id kelak.
        $payload['components'] = ApiCoIdProvider::buildTemplateComponents($payload);

        return $this->record('template', $payload);
    }

    public function webhooks(): array
    {
        return ['success' => true, 'endpoints' => $this->webhookEndpoints, 'error' => null];
    }

    public function enableWebhook(string $id): array
    {
        foreach ($this->webhookEndpoints as $i => $row) {
            if (($row['id'] ?? null) === $id) {
                $this->webhookEndpoints[$i]['is_active']      = true;
                $this->webhookEndpoints[$i]['disabled_at']    = null;
                $this->webhookEndpoints[$i]['disable_reason'] = null;
                $this->webhookEndpoints[$i]['failure_count']  = 0;

                return ['success' => true, 'error' => null];
            }
        }

        return ['success' => false, 'error' => "Endpoint {$id} tidak ditemukan."];
    }

    /** Berkas yang "diunggah" — tes memeriksa nama & mime-nya. */
    public array $uploaded = [];

    public function uploadMedia(string $absolutePath, string $mime, string $filename): array
    {
        $this->uploaded[] = ['path' => $absolutePath, 'mime' => $mime, 'filename' => $filename];

        return ['success' => true, 'media_id' => 'fake-media-' . count($this->uploaded), 'error' => null];
    }

    /** Template palsu; tes & layar bisa memilihnya tanpa jaringan. */
    public array $templateList = [[
        'id'        => 'tpl-1',
        'name'      => 'sapa_umum',
        'language'  => 'id',
        'category'  => 'UTILITY',
        'status'    => 'APPROVED',
        'body'      => 'Selamat siang, kami dari Noud Acrylic. Kami ingin melanjutkan pembahasan pesanan Anda. Mohon balas pesan ini ya.',
        'variables' => 0,
    ]];

    public function templates(): array
    {
        return ['success' => true, 'templates' => $this->templateList, 'error' => null];
    }

    /**
     * Template yang "diajukan" selama mode aman. Tercatat lengkap supaya
     * alurnya teruji, tapi tak sebutir pun sampai ke vendor.
     *
     * @var array<int,array<string,mixed>>
     */
    public array $templateDiajukan = [];

    public function buatTemplate(string $nama, string $kategori, string $body, array $variabel = [], string $bahasa = 'id'): array
    {
        $this->templateDiajukan[] = compact('nama', 'kategori', 'body', 'variabel', 'bahasa');

        return ['success' => true, 'id' => 'fake_' . $nama, 'status' => 'PENDING', 'error' => null];
    }

    public function windowStatus(string $identifier): array
    {
        return [
            'success'    => true,
            'is_open'    => $this->windowOpen,
            'expires_at' => $this->windowOpen ? now()->addHours(24)->toIso8601String() : null,
            'raw'        => [],
            'error'      => null,
        ];
    }

    /** Panggilan kirim terakhir, atau null bila belum ada. */
    public function lastSent(): ?array
    {
        return $this->sent ? $this->sent[array_key_last($this->sent)] : null;
    }

    /** Semua panggilan kirim dengan jenis tertentu ('text'|'media'|'template'). */
    public function sentOfKind(string $kind): array
    {
        return array_values(array_filter($this->sent, fn ($row) => $row['kind'] === $kind));
    }

    public function reset(): void
    {
        $this->sent     = [];
        $this->failWith = null;
    }

    private function record(string $kind, array $payload): array
    {
        if ($this->failWith !== null) {
            $error = $this->failWith;
            $this->failWith = null;

            return ['success' => false, 'message_id' => null, 'customer_id' => null, 'raw' => [], 'error' => $error];
        }

        $payload['to'] = PhoneNumber::normalize($payload['to'] ?? null);

        $this->sent[] = ['kind' => $kind] + $payload;

        return [
            'success'     => true,
            'message_id'  => 'fake-msg-' . count($this->sent),
            'customer_id' => 'fake-cust-' . ($payload['to'] ?? 'x'),
            'raw'         => [],
            'error'       => null,
        ];
    }
}
