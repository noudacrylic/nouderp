<?php

namespace App\Modules\CRM\Services;

use App\Models\User;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Models\ErpNotification;
use App\Modules\Notifications\Services\PusatNotifikasi;

/**
 * Memberi tahu tim bahwa ada chat yang menunggu — pesan masuk, dan operan.
 *
 * Dua peristiwa, satu masalah yang sama: sebelum ini keduanya HANYA menaikkan
 * `unread_count`, yang cuma terlihat kalau kebetulan ada yang sedang membuka
 * Inbox. Chat yang masuk saat layar tertutup baru ketahuan waktu pelanggan
 * menagih — dan pada saat itu jendela 24 jam sudah termakan separuh.
 *
 * Penargetannya mengikuti cara tim ini benar-benar bekerja:
 *
 *  - Chat SUDAH dioper  → hanya pemiliknya. Menyiarkannya ke semua orang
 *    membuat kepemilikan tak ada artinya, dan tiap orang belajar mengabaikan
 *    notifikasi yang biasanya bukan urusannya.
 *  - Chat BELUM dioper  → semua yang punya akses Inbox. Tidak ada yang merasa
 *    bertanggung jawab atas chat tanpa pemilik, jadi kalau tidak disiarkan ia
 *    tidak akan diangkat siapa pun.
 *
 * Dua saluran sekaligus lewat PusatNotifikasi: baris di lonceng ERP (menunggu
 * sampai dibaca) dan web push (sampai walau ERP tidak dibuka). Keduanya
 * saling menambal — lihat alasannya di PusatNotifikasi.
 */
class NotifikasiChatService
{
    /** Kunci menu yang menentukan siapa "orang CRM". Sama dengan yang dipakai layarnya. */
    private const MENU = 'crm.inbox';

    public function __construct(private PusatNotifikasi $pusat)
    {
    }

    /**
     * Pesan masuk dari pelanggan.
     *
     * Penanda notifikasi (`tag`) memakai id percakapan, BUKAN id pesan. Ini
     * satu-satunya rem derau yang benar-benar bekerja: pelanggan yang mengirim
     * lima pesan beruntun menghasilkan satu notifikasi yang diperbarui lima
     * kali, bukan lima notifikasi yang menumpuk sampai orang mematikannya.
     */
    public function pesanMasuk(CrmConversation $percakapan, CrmMessage $pesan): void
    {
        $ringkas = $pesan->ringkas(120);

        $this->kirim(
            $percakapan,
            '💬 ' . $percakapan->namaTampil(),
            $ringkas !== '' ? $ringkas : 'Mengirim pesan baru.',
            'crm-chat-' . $percakapan->id
        );
    }

    /**
     * Percakapan dioper ke orang lain.
     *
     * Selalu ke penerimanya, apa pun keadaannya — inilah satu-satunya kabar
     * yang benar-benar personal di modul ini. Yang mengoper tidak dikabari;
     * ia baru saja melakukannya.
     */
    public function dioper(CrmConversation $percakapan, ?User $penerima, ?User $pengoper = null): void
    {
        if (! $penerima) {
            return;
        }

        $dari = $pengoper?->name ? ' oleh ' . $pengoper->name : '';

        $this->pusat->keUser(
            $penerima,
            ErpNotification::CHAT_DIOPER,
            '📥 Chat dioper ke Anda',
            $percakapan->namaTampil() . ' dioper' . $dari . '. Bolanya sekarang di Anda.',
            [
                'url' => $this->url($percakapan),
                'tag' => 'crm-oper-' . $percakapan->id,
            ]
        );
    }

    /* ------------------------------------------------------------------ dalam */

    private function kirim(CrmConversation $percakapan, string $judul, string $isi, string $tag): void
    {
        $opts = ['url' => $this->url($percakapan), 'tag' => $tag];

        if ($percakapan->owner_user_id) {
            $this->pusat->keUser($percakapan->owner, ErpNotification::CHAT_MASUK, $judul, $isi, $opts);

            return;
        }

        $this->pusat->keAksesMenu(self::MENU, ErpNotification::CHAT_MASUK, $judul, $isi, $opts);
    }

    private function url(CrmConversation $percakapan): string
    {
        /*
         * route() butuh APP_URL yang benar saat dipanggil dari CLI/webhook —
         * dan webhook memang jalan tanpa konteks peramban. Kalau sampai gagal,
         * notifikasinya tetap berangkat dengan tautan ke Inbox, bukan batal.
         */
        try {
            return route('crm.inbox.show', $percakapan->id);
        } catch (\Throwable $e) {
            return '/erp/crm';
        }
    }
}
