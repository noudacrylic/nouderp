<?php

namespace App\Models;

use App\Modules\CRM\Support\PeranWaha;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Setting per-provider chat (pola singleton-per-provider, sama seperti ShippingSetting).
 * Ambil via CrmSetting::for('apicoid').
 */
class CrmSetting extends Model
{
    protected $fillable = [
        'provider', 'is_enabled', 'api_key', 'base_url',
        'webhook_secret', 'default_phone_number_id', 'config',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'config'     => 'array',
    ];

    /** Kunci HMAC & API key jangan pernah ikut terbawa ke JSON/log. */
    protected $hidden = ['api_key', 'webhook_secret'];

    public const DEFAULT_BASE_URL = [
        'apicoid' => 'https://chat.api.co.id/api/v1/public',
        // WAHA di-self-host di server yang sama; alamatnya tak boleh keluar localhost.
        'waha'    => 'http://127.0.0.1:3000',
    ];

    public static function for(string $provider): self
    {
        return static::firstOrCreate(
            ['provider' => $provider],
            ['base_url' => self::DEFAULT_BASE_URL[$provider] ?? null]
        );
    }

    public function effectiveBaseUrl(): string
    {
        return rtrim($this->base_url ?: $this->bawaanBaseUrl(), '/');
    }

    /**
     * Alamat bawaan. Untuk WAHA nilainya diambil dari config lebih dulu supaya
     * bisa digeser lewat .env tanpa menyentuh baris di database — berguna saat
     * container-nya dipindah port pada mesin lokal.
     */
    private function bawaanBaseUrl(): string
    {
        if ($this->provider === 'waha') {
            return (string) config('crm.waha.base_url', self::DEFAULT_BASE_URL['waha']);
        }

        return (string) (self::DEFAULT_BASE_URL[$this->provider] ?? '');
    }

    /**
     * Token rahasia di PATH webhook — jalur verifikasi kedua.
     *
     * Dibuat karena api.co.id menandatangani webhook (X-Webhook-Signature) tapi
     * tidak memperlihatkan signing secret-nya di mana pun, sehingga HMAC tak
     * bisa diverifikasi. Tanpa jalur ini pilihannya cuma dua dan keduanya buruk:
     * menolak semua webhook, atau menerima payload tanpa verifikasi sama sekali.
     *
     * Token acak 40 karakter di dalam URL HTTPS = pola yang sudah dipakai ERP
     * ini untuk Telegram (`/telegram/webhook/{secret}`) dan QRISLY. Begitu
     * signing secret vendor ketemu, HMAC otomatis kembali jadi penjaga utama.
     */
    public function webhookToken(): string
    {
        $token = (string) ($this->config['webhook_url_token'] ?? '');

        if ($token === '') {
            $token = Str::random(40);
            $this->config = array_merge((array) $this->config, ['webhook_url_token' => $token]);
            $this->save();
        }

        return $token;
    }

    /** Bikin token baru (URL lama langsung mati). */
    public function regenerateWebhookToken(): string
    {
        $this->config = array_merge((array) $this->config, ['webhook_url_token' => Str::random(40)]);
        $this->save();

        return $this->config['webhook_url_token'];
    }

    /* ------------------------------------------------- sesi WAHA per peran */

    /**
     * Satu container WAHA melayani beberapa nomor. Yang dibagi bersama adalah
     * SAMBUNGANNYA (alamat + API key, kolom di baris ini); yang berdiri
     * sendiri per peran adalah nama sesi beserta jejak pemeriksaannya.
     *
     * Kredensialnya SENGAJA tidak diduplikasi per peran. Kunci yang sama
     * disalin ke dua tempat akan menyimpang cepat atau lambat, lalu satu peran
     * berhenti bekerja dengan galat "kunci ditolak" yang tak masuk akal karena
     * peran sebelahnya jelas-jelas jalan.
     *
     * @return array{session?:string, last_status?:string, last_checked_at?:string, alerted_at?:?string, pernah_tertaut?:bool}
     */
    public function sesi(string $peran): array
    {
        return (array) data_get($this->config, 'sesi.' . $peran, []);
    }

    /** Nama sesi di WAHA untuk peran ini (isian layar menang atas bawaan config). */
    public function namaSesi(string $peran): string
    {
        return (string) (($this->sesi($peran)['session'] ?? null) ?: PeranWaha::bawaanSesi($peran));
    }

    /** Tambal sebagian isi sesi satu peran; kunci yang tak disebut dibiarkan. */
    public function simpanSesi(string $peran, array $nilai): void
    {
        $config = (array) $this->config;
        $sesi   = is_array($config['sesi'] ?? null) ? $config['sesi'] : [];

        $sesi[$peran]    = array_merge($this->sesi($peran), $nilai);
        $config['sesi']  = $sesi;
        $this->config    = $config;

        $this->save();
    }

    /**
     * Catat hasil pemeriksaan sesi. SATU-SATUNYA penulis bentuk ini — dulu
     * layar Pengaturan dan pemeriksa berkala menuliskannya sendiri-sendiri
     * dengan komentar "bentuk kuncinya sama persis", yang berarti dua tempat
     * harus diingat bersamaan setiap kali bentuknya berubah.
     *
     * 'pernah_tertaut' hanya pernah bergerak SATU arah. Ia bukan cerminan
     * keadaan sekarang melainkan jawaban atas "nomor ini sudah pernah dipasang
     * atau belum" — dan itulah yang memutuskan apakah diamnya sesi layak
     * diperingatkan. Sesi yang memang belum pernah dipakai tidak boleh
     * membunyikan alarm, karena alarm yang berbunyi tanpa bisa ditindaklanjuti
     * akan berhenti dipercaya justru saat ia betulan perlu.
     */
    public function catatStatusSesi(string $peran, array $status): void
    {
        $nilai = [
            'last_status'     => (string) ($status['status'] ?? ''),
            'last_checked_at' => now()->toIso8601String(),
        ];

        if (($status['siap'] ?? false) === true) {
            $nilai['pernah_tertaut'] = true;
        }

        $this->simpanSesi($peran, $nilai);
    }

    /** Nomor peran ini sudah pernah benar-benar tertaut? */
    public function pernahTertaut(string $peran): bool
    {
        return (bool) ($this->sesi($peran)['pernah_tertaut'] ?? false);
    }

    public function isConfigured(): bool
    {
        return $this->is_enabled && ! empty($this->api_key);
    }
}
