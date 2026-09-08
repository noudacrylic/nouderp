<?php

namespace App\Modules\CRM\Providers;

use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Contracts\NotificationProvider;

/**
 * Jalur notifikasi lewat template resmi Meta — pembungkus tipis di atas
 * ChatProvider yang sudah ada.
 *
 * Perannya dua: (1) jalur bawaan selama WAHA belum dinyalakan, sehingga
 * menambah lapis notifikasi ini tidak mengubah apa pun yang sudah berjalan;
 * (2) jaring pengaman berbayar saat sesi WAHA mati berkepanjangan (Tahap 3
 * memanggil jalur ini setelah pesan tertahan lewat batas).
 *
 * Tidak menyimpan kredensial apa pun sendiri: ia selalu meminta provider ke
 * ChatManager, jadi saklar "jangan kirim" tetap berlaku otomatis di sini.
 */
class NotifikasiResmiProvider implements NotificationProvider
{
    public function __construct(private ChatManager $chat)
    {
    }

    public function key(): string
    {
        return 'resmi';
    }

    public function isReady(): bool
    {
        return $this->chat->provider()->isReady();
    }

    public function kirimNotifikasi(array $payload): array
    {
        $hasil = $this->chat->provider()->sendTemplate([
            'to'                => $payload['to'] ?? null,
            'template'          => $payload['template'] ?? '',
            'language'          => $payload['language'] ?? 'id',
            'body'              => (array) ($payload['body'] ?? []),
            /*
             * Tombol URL dinamis hanya menerima POTONGAN AKHIR url-nya; sisanya
             * sudah tertanam di template saat disetujui Meta. Pemanggil
             * menyerahkan URL penuh (itu yang dibutuhkan jalur teks WAHA), jadi
             * pemotongan terjadi di sini — bukan di pemanggil, yang tidak boleh
             * tahu jalur mana yang sedang dipakai.
             */
            'url_button_suffix' => self::potonganUrl($payload['url_lacak'] ?? null),
        ]);

        return [
            'success'    => (bool) ($hasil['success'] ?? false),
            'message_id' => $hasil['message_id'] ?? null,
            'tahan'      => false,   // jalur resmi tak punya sesi yang bisa putus
            'error'      => $hasil['error'] ?? null,
        ];
    }

    /** 'https://noudakrilik.com/pesanan/abc-123' → 'abc-123'. */
    public static function potonganUrl(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        $potong = substr((string) strrchr(rtrim($url, '/'), '/'), 1);

        return $potong !== '' ? $potong : null;
    }

    public function statusJalur(): array
    {
        return $this->isReady()
            ? ['siap' => true, 'status' => 'SIAP', 'keterangan' => null]
            : ['siap' => false, 'status' => 'BELUM_DIATUR', 'keterangan' => 'Provider chat resmi belum dikonfigurasi.'];
    }
}
