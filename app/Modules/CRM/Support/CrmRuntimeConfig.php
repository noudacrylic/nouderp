<?php

namespace App\Modules\CRM\Support;

use App\Models\CrmSetting;
use Illuminate\Support\Facades\Log;

/**
 * Menimpa config('crm.*') dengan nilai yang disimpan lewat layar Pengaturan CRM.
 *
 * ALASAN BENTUKNYA BEGINI: seluruh modul CRM (adapter, penjadwal jam sopan,
 * penyimpan lampiran, penyapu masa simpan, dan SAKLAR JANGAN-KIRIM) sudah
 * membaca config('crm.*') di belasan tempat. Kalau layar Pengaturan menaruh
 * nilainya di tempat lain, akan ada dua sumber kebenaran dan satu jalur yang
 * lupa memeriksa — persis kesalahan yang dulu dihindari dengan membuat saklar
 * jangan-kirim berupa pertukaran driver, bukan percabangan if.
 *
 * Jadi: satu titik, dipanggil sekali saat boot, menyiram nilai DB ke atas
 * config. Yang tidak diisi di DB tetap memakai nilai config/env.
 *
 * Kegagalan apa pun (tabel belum ada saat migrate pertama, DB tak terjangkau)
 * DIAM-DIAM diabaikan — nilai bawaan config sudah aman (dry_run = true), dan
 * gagal boot hanya karena pengaturan chat itu tidak sebanding.
 */
class CrmRuntimeConfig
{
    /** Kunci yang boleh ditimpa dari DB, beserta cara membacanya. */
    public const KEYS = [
        'dry_run'                   => 'bool',
        'allowed_recipients'        => 'list',
        'store_hours_text'          => 'string',
        'storefront_url'            => 'string',
        'store_open_hour'           => 'int',
        'store_close_hour'          => 'int',
        'max_media_bytes'           => 'int',
        'attachment_retention_days' => 'int',
        'notifikasi_driver'         => 'string',
        'notifikasi_aktif'          => 'peta',
    ];

    /**
     * Kunci yang letaknya di config BUKAN 'crm.<kunci>' begitu saja.
     * Bentuk datar dipertahankan di DB (satu larik config, gampang dibaca),
     * sedangkan config aslinya bersarang di bawah 'notifikasi'.
     */
    public const ALIAS = [
        'notifikasi_driver' => 'notifikasi.driver',
        'notifikasi_aktif'  => 'notifikasi.aktif',
    ];

    private static bool $applied = false;

    public static function apply(bool $force = false): void
    {
        if (self::$applied && ! $force) {
            return;
        }

        self::$applied = true;

        try {
            $setting = CrmSetting::query()->where('provider', 'apicoid')->first();
        } catch (\Throwable $e) {
            Log::debug('[CRM] pengaturan tidak terbaca, memakai config bawaan', ['error' => $e->getMessage()]);

            return;
        }

        if ($setting) {
            self::siram((array) $setting->config);
        }

        /*
         * Baris 'waha' menang untuk kunci yang memang miliknya (pilihan jalur
         * notifikasi). Layarnya sudah terpisah dari api.co.id, jadi nilainya
         * ditulis di sana — sedangkan nilai lama yang terlanjur mengendap di
         * baris apicoid tetap dihormati selama baris waha belum pernah
         * disimpan, kalau tidak jalur yang dulu dipilih diam-diam kembali ke
         * 'resmi' begitu kode ini naik.
         */
        try {
            $waha = CrmSetting::query()->where('provider', 'waha')->first();
        } catch (\Throwable $e) {
            return;
        }

        if ($waha) {
            self::siram(array_intersect_key((array) $waha->config, array_flip(self::KUNCI_WAHA)));
        }
    }

    /** Kunci yang boleh datang dari baris 'waha'. */
    private const KUNCI_WAHA = ['notifikasi_driver'];

    private static function siram(array $config): void
    {
        foreach ($config as $key => $value) {
            if (! isset(self::KEYS[$key]) || $value === null) {
                continue;
            }

            config(['crm.' . (self::ALIAS[$key] ?? $key) => self::cast($key, $value)]);
        }
    }

    /** Nilai efektif satu kunci (DB bila ada, kalau tidak config/env). */
    public static function get(string $key)
    {
        self::apply();

        return config('crm.' . (self::ALIAS[$key] ?? $key));
    }

    private static function cast(string $key, $value)
    {
        return match (self::KEYS[$key]) {
            'bool'   => (bool) $value,
            'int'    => (int) $value,
            'list'   => array_values(array_filter(array_map('trim', (array) $value))),
            // Peta jenis => bool. Nilainya datang dari checkbox, jadi bisa
            // berupa '1'/'0'/true/false — dinormalkan di sini sekali saja.
            'peta'   => array_map(fn ($v) => (bool) $v, (array) $value),
            default  => (string) $value,
        };
    }

    /** Dipakai tes: paksa baca ulang. */
    public static function forget(): void
    {
        self::$applied = false;
    }
}
