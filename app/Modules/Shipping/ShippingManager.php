<?php

namespace App\Modules\Shipping;

use App\Modules\Shipping\Contracts\ShippingProvider;
use App\Modules\Shipping\Providers\BiteshipProvider;
use App\Modules\Shipping\Providers\JubelioShipmentProvider;
use App\Modules\Shipping\Providers\KiriminAjaProvider;
use App\Modules\Shipping\Providers\RajaOngkirProvider;

/**
 * Resolver kurir → provider. Menggabungkan ongkir lintas provider yang aktif.
 *
 * AKTIF (1 Agu 2026): Jubelio Shipment — satu-satunya provider, dipilih karena bisa
 * terbit resi tanpa badan usaha DAN menyediakan layanan CARGO (J&T Cargo dkk).
 * RajaOngkir dimatikan lewat Pengaturan (kelasnya tetap ada) supaya operator tidak
 * disodori tarif ganda untuk kurir yang sama. Biteship & KiriminAja tetap dinonaktifkan
 * (butuh badan usaha / verifikasi macet).
 *
 * Etalase memakai provider aktif PERTAMA (CheckoutController::rateProviderKey), jadi
 * berganti agregator cukup lewat Pengaturan tanpa menyentuh kode.
 */
class ShippingManager
{
    /** @return array<string, class-string<ShippingProvider>> */
    private const PROVIDERS = [
        'rajaongkir'       => RajaOngkirProvider::class,
        // Jubelio Shipment: terbit resi tanpa badan usaha (termasuk J&T Cargo). Ikut
        // aktif hanya bila client id/secret sudah diisi — isReady() yang menjaga.
        'jubelio_shipment' => JubelioShipmentProvider::class,
        // 'biteship'   => BiteshipProvider::class,   // dinonaktifkan (wajib badan usaha)
        // 'kiriminaja' => KiriminAjaProvider::class, // dinonaktifkan (verifikasi macet)
    ];

    /** Provider yang aktif & terkonfigurasi. @return ShippingProvider[] */
    public function activeProviders(): array
    {
        $out = [];
        foreach (self::PROVIDERS as $key => $class) {
            $p = app($class);
            if ($p->isReady()) {
                $out[] = $p;
            }
        }
        return $out;
    }

    public function provider(string $key): ?ShippingProvider
    {
        $class = self::PROVIDERS[$key] ?? null;
        return $class ? app($class) : null;
    }

    public function hasActiveProvider(): bool
    {
        return count($this->activeProviders()) > 0;
    }

    /** Label tampilan per provider (untuk pemilih area di form). */
    public const LABELS = [
        'rajaongkir'       => 'RajaOngkir',
        'jubelio_shipment' => 'Jubelio Shipment',
        'biteship'         => 'Biteship',
        'kiriminaja'       => 'KiriminAja',
    ];

    /** Key provider aktif pertama (fallback untuk pencarian area default). */
    public function defaultProviderKey(): ?string
    {
        $active = $this->activeProviders();
        return $active ? $active[0]->key() : null;
    }

    /**
     * Provider aktif sebagai [code => label] untuk pemilih area di form.
     * Kosong = belum ada provider aktif (form jatuh ke mode 1-provider biteship).
     * @return array<string,string>
     */
    public function activeProviderOptions(): array
    {
        $out = [];
        foreach ($this->activeProviders() as $p) {
            $out[$p->key()] = self::LABELS[$p->key()] ?? ucfirst($p->key());
        }
        return $out;
    }

    /**
     * Cek ongkir gabungan dari semua provider aktif (dedup by provider+service).
     * @return array{success:bool, rates:array, errors:array<string>}
     */
    public function rates(array $payload): array
    {
        $providers = $this->activeProviders();

        // Filter ke satu provider bila diminta (mis. area tujuan spesifik milik provider tsb).
        $only = $payload['provider'] ?? null;
        if ($only) {
            $providers = array_values(array_filter($providers, fn ($p) => $p->key() === $only));
        }

        if (empty($providers)) {
            return ['success' => false, 'rates' => [], 'errors' => ['Belum ada kurir aktif. Aktifkan & isi API key di Settings → Integrasi.']];
        }

        $rates  = [];
        $errors = [];
        foreach ($providers as $p) {
            $r = $p->rates($payload);
            if ($r['success']) {
                $rates = array_merge($rates, $r['rates']);
            } else {
                $errors[] = $p->key() . ': ' . ($r['error'] ?? 'gagal');
            }
        }

        // Urut termurah dulu
        usort($rates, fn ($a, $b) => $a['price'] <=> $b['price']);

        return ['success' => !empty($rates), 'rates' => $rates, 'errors' => $errors];
    }
}
