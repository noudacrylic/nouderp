<?php

namespace App\Services;

use App\Models\Customer;
use App\Modules\CRM\Contracts\NotificationProvider;
use App\Modules\CRM\Providers\WahaProvider;
use App\Modules\CRM\Support\PhoneNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * "Lupa PIN?" mandiri di toko online (9 Nov 2026): kode 6 angka dikirim lewat WhatsApp
 * NOTIFIKASI (WAHA) ke nomor akun, lalu pembeli memasukkan kode + PIN baru. Kepemilikan
 * nomor dibuktikan dengan menerima kodenya — padanan tombol "Reset PIN toko online" di CRM.
 *
 * Kode tak pernah disimpan polos (hash di cache), berlaku 10 menit, hangus setelah 5 kali
 * salah; permintaan kode dibatasi 3 kali per nomor per jam (rem per IP ada di route).
 */
class StorefrontPinResetService
{
    public const TEMPLATE      = 'kode_reset_pin';
    public const MENIT_BERLAKU = 10;
    public const MAKS_SALAH    = 5;
    public const MAKS_MINTA    = 3;   // per nomor per jam

    /** @return array{ok: bool, status: int, pesan: string} */
    public function minta(string $hp): array
    {
        $kunci = PhoneNumber::normalize($hp);
        if (! $kunci || $this->akun($hp)->isEmpty()) {
            // pin-status sudah memberi tahu nomor mana yang ber-PIN, jadi pesan jujur tak membuka apa pun.
            return $this->hasil(false, 404, 'Nomor ini belum punya PIN. Cukup buat PIN baru saat checkout.');
        }

        $jumlah = (int) Cache::get("pinreset:minta:$kunci", 0);
        if ($jumlah >= self::MAKS_MINTA) {
            return $this->hasil(false, 429, 'Kode sudah diminta beberapa kali. Coba lagi 1 jam lagi, atau hubungi kami via WhatsApp.');
        }

        $kode = (string) random_int(100000, 999999);
        $kirim = $this->pengirim()->kirimNotifikasi([
            'to' => $kunci, 'template' => self::TEMPLATE, 'language' => 'id', 'body' => [$kode],
        ]);
        if (! ($kirim['success'] ?? false)) {
            Log::warning('[Storefront] kode reset PIN gagal dikirim', ['error' => $kirim['error'] ?? null]);
            return $this->hasil(false, 503, 'Kode gagal dikirim. Silakan hubungi kami via WhatsApp untuk reset PIN.');
        }

        Cache::put("pinreset:minta:$kunci", $jumlah + 1, now()->addHour());
        Cache::put("pinreset:kode:$kunci", ['hash' => Hash::make($kode), 'salah' => 0], now()->addMinutes(self::MENIT_BERLAKU));

        return $this->hasil(true, 200, 'Kode dikirim ke WhatsApp nomor ini. Berlaku ' . self::MENIT_BERLAKU . ' menit.');
    }

    /** @return array{ok: bool, status: int, pesan: string} */
    public function simpan(string $hp, string $kode, string $pin): array
    {
        $kunci = PhoneNumber::normalize($hp);
        $simpan = $kunci ? Cache::get("pinreset:kode:$kunci") : null;
        if (! $simpan) {
            return $this->hasil(false, 422, 'Kode sudah kedaluwarsa. Minta kode baru.');
        }

        if (! Hash::check($kode, $simpan['hash'])) {
            $simpan['salah']++;
            if ($simpan['salah'] >= self::MAKS_SALAH) {
                Cache::forget("pinreset:kode:$kunci");
                return $this->hasil(false, 422, 'Kode salah terlalu sering. Minta kode baru.');
            }
            Cache::put("pinreset:kode:$kunci", $simpan, now()->addMinutes(self::MENIT_BERLAKU));
            return $this->hasil(false, 422, 'Kode salah.');
        }

        foreach ($this->akun($hp) as $c) {
            $c->forceFill(['web_order_pin' => Hash::make($pin)])->save();
        }
        Cache::forget("pinreset:kode:$kunci");

        return $this->hasil(true, 200, 'PIN baru tersimpan.');
    }

    /** Akun toko online ber-PIN untuk nomor ini (sama dengan lookup/checkout). */
    private function akun(string $hp)
    {
        return Customer::denganNomor($hp)->filter(fn (Customer $c) => filled($c->web_order_pin))->values();
    }

    /** Selalu nomor NOTIFIKASI (WAHA) — kode tak boleh berangkat dari nomor utama. */
    protected function pengirim(): NotificationProvider
    {
        return app()->bound('storefront.pin.pengirim') ? app('storefront.pin.pengirim') : new WahaProvider();
    }

    private function hasil(bool $ok, int $status, string $pesan): array
    {
        return ['ok' => $ok, 'status' => $status, 'pesan' => $pesan];
    }
}
