<?php

namespace App\Modules\Notifications\Services;

use App\Models\ErpNotification;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Satu pintu untuk mengabari orang ERP: menulis notifikasi DI DALAM ERP dan
 * mengirim web push, sekali panggil.
 *
 * Sebelum ini tiap pemanggil memilih salurannya sendiri, dan akibatnya bisa
 * ditebak: yang ditambahkan belakangan cuma memakai satu saluran, lalu ada
 * kabar yang muncul di lonceng tapi tidak sampai ke peramban — atau
 * sebaliknya. Dua saluran itu saling menambal, jadi keputusan memakai keduanya
 * tidak boleh diserahkan ke tiap tempat pemanggilan:
 *
 *  - web push sampai walau ERP tidak dibuka, tapi butuh izin peramban, mati
 *    kalau peramannya ditutup, dan sekali lewat ia hilang selamanya;
 *  - notifikasi dalam ERP tidak butuh izin apa pun dan menunggu sampai dibaca,
 *    tapi baru terlihat kalau ERP-nya dibuka.
 *
 * Tidak pernah melempar. Gagal mengabari tidak boleh menggagalkan peristiwa
 * yang memicunya — pesan pelanggan yang batal tersimpan jauh lebih mahal
 * daripada notifikasi yang tidak muncul.
 */
class PusatNotifikasi
{
    public function __construct(private WebPushNotifier $push)
    {
    }

    /**
     * Kabari satu orang.
     *
     * @param array{url?:string, tag?:string} $opts
     */
    public function keUser(?User $user, string $jenis, string $judul, string $isi, array $opts = []): void
    {
        if (! $user) {
            return;
        }

        $this->aman(function () use ($user, $jenis, $judul, $isi, $opts) {
            $this->tulis($user->id, $jenis, $judul, $isi, $opts);
            $this->push->notifyUser($user, $judul, $isi, $opts);
        });
    }

    /**
     * Kabari semua orang yang punya akses ke satu menu.
     *
     * `kecuali_user_id` melewati orang yang memicu peristiwanya — ia sedang
     * menatap layarnya sendiri, dan kabar yang memantul balik ke pengirimnya
     * adalah cara tercepat membuat orang mematikan notifikasi.
     *
     * @param array{url?:string, tag?:string, kecuali_user_id?:int} $opts
     */
    public function keAksesMenu(string $menuKey, string $jenis, string $judul, string $isi, array $opts = []): void
    {
        $kecuali = (int) ($opts['kecuali_user_id'] ?? 0);

        $this->aman(function () use ($menuKey, $jenis, $judul, $isi, $opts, $kecuali) {
            User::denganAksesMenu($menuKey)
                ->when($kecuali, fn ($q) => $q->where('id', '!=', $kecuali))
                ->select('id')
                ->chunkById(200, function ($users) use ($jenis, $judul, $isi, $opts) {
                    foreach ($users as $u) {
                        $this->tulis($u->id, $jenis, $judul, $isi, $opts);
                    }
                });

            $this->push->notifyMenuAccess($menuKey, $judul, $isi, $opts);
        });
    }

    /* ------------------------------------------------------------------ dalam */

    /**
     * Tulis satu baris — atau PERBARUI yang sepenanda dan belum dibaca.
     *
     * Peringkasan ini yang membuat loncengnya tetap terbaca. Pelanggan yang
     * mengirim lima pesan beruntun seharusnya menghasilkan satu baris berisi
     * kalimat terakhirnya, bukan lima baris yang mendorong kabar lain keluar
     * dari layar. Yang SUDAH dibaca tidak ikut diperbarui: itu peristiwa baru,
     * dan menandainya belum-dibaca lagi memang benar.
     */
    private function tulis(int $userId, string $jenis, string $judul, string $isi, array $opts): void
    {
        $tag = $opts['tag'] ?? null;
        $isi = mb_substr($isi, 0, 500);

        $data = [
            'jenis' => $jenis,
            'judul' => mb_substr($judul, 0, 160),
            'isi'   => $isi,
            'url'   => $opts['url'] ?? null,
        ];

        if ($tag) {
            $lama = ErpNotification::milik($userId)->belumDibaca()->where('tag', $tag)->latest('id')->first();

            if ($lama) {
                $lama->forceFill($data + ['updated_at' => now()])->save();

                return;
            }
        }

        ErpNotification::create($data + ['user_id' => $userId, 'tag' => $tag]);
    }

    private function aman(callable $kirim): void
    {
        try {
            $kirim();
        } catch (\Throwable $e) {
            Log::warning('Notifikasi ERP gagal: ' . $e->getMessage());
        }
    }
}
