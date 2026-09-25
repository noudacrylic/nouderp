<?php

namespace App\Modules\CRM;

use App\Modules\CRM\Contracts\ChatProvider;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Providers\ApiCoIdProvider;
use App\Modules\CRM\Providers\FakeChatProvider;
use App\Modules\CRM\Providers\WahaChatProvider;

/**
 * Resolver provider chat. Satu-satunya tempat di seluruh ERP yang boleh
 * menyebut nama vendor — sisanya cukup mengenal ChatProvider.
 *
 * SAKLAR "JANGAN KIRIM": selama config('crm.dry_run') menyala, provider yang
 * diserahkan SELALU driver palsu, apa pun isi pengaturan. Sengaja dibuat begitu
 * agar tidak mungkin ada satu jalur pun yang lolos mengirim tanpa sengaja —
 * lebih aman daripada mengandalkan setiap pemanggil memeriksa saklarnya sendiri.
 *
 * SEJAK TAHAP 7 ADA DUA JALUR HIDUP BERSAMAAN, dan yang memilih di antaranya
 * adalah PERCAKAPAN — lihat untuk(). Bukan satu saklar global, dan itu
 * keputusan yang disengaja: satu saklar berarti saat ia digeser, seluruh
 * thread lama ikut pindah jalur, sehingga balasan atas chat yang masuk ke
 * nomor A berangkat dari nomor B. Percakapan tahu nomor mana yang dipakai
 * pelanggan menghubungi kita; saklar global tidak.
 */
class ChatManager
{
    /** @return array<string, class-string<ChatProvider>> */
    private const PROVIDERS = [
        'apicoid' => ApiCoIdProvider::class,
        'waha'    => WahaChatProvider::class,
        'fake'    => FakeChatProvider::class,
    ];

    public function provider(): ChatProvider
    {
        if ($this->isDryRun()) {
            return $this->fake();
        }

        $key   = (string) config('crm.driver', 'apicoid');
        $class = self::PROVIDERS[$key] ?? ApiCoIdProvider::class;

        return $class === FakeChatProvider::class ? $this->fake() : app($class);
    }

    /**
     * Provider untuk SATU percakapan — dipilih dari kanalnya.
     *
     * Kanalnya dicatat saat percakapan lahir, dari jalur mana pesan pertama
     * pelanggan datang, dan tidak pernah berubah sesudahnya. Itulah kenapa ia
     * jadi dasar pemilihan: pelanggan menulis ke nomor tertentu dan berhak
     * menerima jawaban dari nomor yang sama. Jalur yang salah bukan cuma
     * membingungkan — pesannya mendarat sebagai percakapan BARU dari nomor
     * asing, terputus dari yang sedang ia bicarakan, dan tak bisa ditarik.
     *
     * Saklar dry_run tetap di atas segalanya: ia diperiksa di provider() dan
     * di sini, jadi tak ada jalan memutar lewat percakapan.
     */
    public function untuk(CrmConversation $percakapan): ChatProvider
    {
        if ($this->isDryRun()) {
            return $this->fake();
        }

        return $percakapan->cermin()
            ? app(WahaChatProvider::class)
            : $this->provider();
    }

    /**
     * Jalur percakapan ini siap dipakai mengirim?
     *
     * Dipakai layar untuk memutuskan menampilkan kotak ketik. Sengaja TIDAK
     * memeriksa status sesi lewat jaringan — itu satu panggilan API per baris
     * daftar. Sesi yang mati ketahuan saat mengirim, dengan pesan yang
     * menyebut apa yang harus dilakukan.
     */
    public function siapUntuk(CrmConversation $percakapan): bool
    {
        return ! $this->isDryRun() && $this->untuk($percakapan)->isReady();
    }

    /** Saklar global "jangan kirim". */
    public function isDryRun(): bool
    {
        return (bool) config('crm.dry_run', true);
    }

    /**
     * Siap dipakai: ada provider terkonfigurasi DAN saklar jangan-kirim mati.
     * Pemanggil memakainya untuk memutuskan menampilkan peringatan di layar.
     */
    public function isLive(): bool
    {
        return ! $this->isDryRun() && $this->provider()->isReady();
    }

    /**
     * Driver palsu sebagai singleton — tes (dan layar diagnostik) memeriksa
     * instance yang SAMA dengan yang dipakai kode yang diuji.
     */
    public function fake(): FakeChatProvider
    {
        if (! app()->bound(FakeChatProvider::class)) {
            app()->instance(FakeChatProvider::class, new FakeChatProvider());
        }

        return app(FakeChatProvider::class);
    }
}
