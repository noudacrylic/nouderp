<?php

namespace App\Modules\CRM;

use App\Modules\CRM\Contracts\ChatProvider;
use App\Modules\CRM\Providers\ApiCoIdProvider;
use App\Modules\CRM\Providers\FakeChatProvider;

/**
 * Resolver provider chat. Satu-satunya tempat di seluruh ERP yang boleh
 * menyebut nama vendor — sisanya cukup mengenal ChatProvider.
 *
 * SAKLAR "JANGAN KIRIM": selama config('crm.dry_run') menyala, provider yang
 * diserahkan SELALU driver palsu, apa pun isi pengaturan. Sengaja dibuat begitu
 * agar tidak mungkin ada satu jalur pun yang lolos mengirim tanpa sengaja —
 * lebih aman daripada mengandalkan setiap pemanggil memeriksa saklarnya sendiri.
 */
class ChatManager
{
    /** @return array<string, class-string<ChatProvider>> */
    private const PROVIDERS = [
        'apicoid' => ApiCoIdProvider::class,
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
