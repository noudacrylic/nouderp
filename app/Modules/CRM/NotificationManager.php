<?php

namespace App\Modules\CRM;

use App\Modules\CRM\Contracts\NotificationProvider;
use App\Modules\CRM\Providers\FakeNotificationProvider;
use App\Modules\CRM\Providers\NotifikasiResmiProvider;
use App\Modules\CRM\Providers\WahaProvider;

/**
 * Resolver jalur NOTIFIKASI — kembaran ChatManager, untuk peran yang berbeda.
 *
 * Sama seperti ChatManager, ini satu-satunya tempat di ERP yang boleh menyebut
 * nama jalur. Pemanggil (CrmOutboxSender, dan kelak tombol kirim ulang manual)
 * cukup mengenal NotificationProvider, sehingga berpindah dari template
 * berbayar ke WAHA — atau kembali lagi saat nomornya kena blokir — adalah satu
 * perubahan pengaturan, bukan perubahan kode.
 *
 * SAKLAR "JANGAN KIRIM" berlaku di sini juga, dan lewat jalan yang sedikit
 * berbeda per jalur: 'waha' ditukar driver palsu (WAHA tak punya mode uji
 * sendiri — sekali terkirim, terkirim sungguhan), sedangkan 'resmi' dibiarkan
 * apa adanya karena ChatManager di dalamnya SUDAH menukar dirinya sendiri.
 * Hasil akhirnya sama: tidak ada satu jalur pun yang lolos mengirim.
 */
class NotificationManager
{
    public function __construct(private ChatManager $chat)
    {
    }

    public function provider(): NotificationProvider
    {
        $driver = (string) config('crm.notifikasi.driver', 'resmi');

        if ($driver === 'fake') {
            return $this->fake();
        }

        if ($driver === 'waha') {
            return $this->chat->isDryRun() ? $this->fake() : app(WahaProvider::class);
        }

        return app(NotifikasiResmiProvider::class);
    }

    /** Jalur cadangan berbayar: dipakai Tahap 3 saat antrean WAHA tertahan terlalu lama. */
    public function resmi(): NotificationProvider
    {
        return app(NotifikasiResmiProvider::class);
    }

    /** Jalur aktif memakai WAHA? Dipakai layar untuk memilih pita peringatan. */
    public function pakaiWaha(): bool
    {
        return (string) config('crm.notifikasi.driver', 'resmi') === 'waha';
    }

    /** Singleton, supaya tes memeriksa instance yang SAMA dengan yang dipakai kode. */
    public function fake(): FakeNotificationProvider
    {
        if (! app()->bound(FakeNotificationProvider::class)) {
            app()->instance(FakeNotificationProvider::class, new FakeNotificationProvider());
        }

        return app(FakeNotificationProvider::class);
    }
}
