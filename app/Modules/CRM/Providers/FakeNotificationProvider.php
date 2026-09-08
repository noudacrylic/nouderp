<?php

namespace App\Modules\CRM\Providers;

use App\Modules\CRM\Contracts\NotificationProvider;
use App\Modules\CRM\Support\PhoneNumber;
use App\Modules\CRM\Support\TemplateResmi;

/**
 * Jalur notifikasi palsu: merangkai teks selengkapnya, lalu mencatatnya —
 * tak sebutir paket pun keluar.
 *
 * Nilainya bukan sekadar "tidak mengirim": ia menyimpan TEKS JADI, sehingga
 * kalimat yang kelak diterima pelanggan lewat WAHA bisa dibaca dan dikoreksi
 * sekarang, sebelum ada nomor sungguhan yang tertaut. Kesalahan kalimat baru
 * ketahuan setelah terkirim adalah kesalahan yang tak bisa ditarik kembali.
 */
class FakeNotificationProvider implements NotificationProvider
{
    /** Semua notifikasi yang "terkirim", terbaru di belakang. @var array<int,array> */
    public array $terkirim = [];

    /** Bila diisi, panggilan berikutnya gagal dengan pesan ini. */
    public ?string $gagalDengan = null;

    /** Bila menyala, panggilan berikutnya menjawab 'tahan' (sesi mati). */
    public bool $tahan = false;

    public function key(): string
    {
        return 'fake';
    }

    public function isReady(): bool
    {
        return true;
    }

    public function kirimNotifikasi(array $payload): array
    {
        $teks = TemplateResmi::render(
            (string) ($payload['template'] ?? ''),
            (array) ($payload['body'] ?? []),
            $payload['url_lacak'] ?? null
        );

        $catatan = [
            'to'        => PhoneNumber::normalize($payload['to'] ?? null),
            'template'  => $payload['template'] ?? null,
            'body'      => (array) ($payload['body'] ?? []),
            'url_lacak' => $payload['url_lacak'] ?? null,
            'teks'      => $teks,
        ];

        if ($this->tahan) {
            return ['success' => false, 'message_id' => null, 'tahan' => true, 'error' => 'Sesi WhatsApp sedang tidak siap (palsu).'];
        }

        if ($this->gagalDengan !== null) {
            return ['success' => false, 'message_id' => null, 'tahan' => false, 'error' => $this->gagalDengan];
        }

        $this->terkirim[] = $catatan;

        return ['success' => true, 'message_id' => 'fake-notif-' . count($this->terkirim), 'tahan' => false, 'error' => null];
    }

    public function statusJalur(): array
    {
        return ['siap' => true, 'status' => 'WORKING', 'keterangan' => null];
    }
}
