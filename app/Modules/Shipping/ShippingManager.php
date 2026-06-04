<?php

namespace App\Modules\Shipping;

use App\Modules\Shipping\Contracts\ShippingProvider;
use App\Modules\Shipping\Providers\BiteshipProvider;
use App\Modules\Shipping\Providers\KiriminAjaProvider;

/**
 * Resolver kurir → provider. Biteship + KiriminAja (Fase 6).
 * Menggabungkan ongkir lintas provider yang aktif.
 */
class ShippingManager
{
    /** @return array<string, class-string<ShippingProvider>> */
    private const PROVIDERS = [
        'biteship'   => BiteshipProvider::class,
        'kiriminaja' => KiriminAjaProvider::class,
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

    /**
     * Cek ongkir gabungan dari semua provider aktif (dedup by provider+service).
     * @return array{success:bool, rates:array, errors:array<string>}
     */
    public function rates(array $payload): array
    {
        $providers = $this->activeProviders();
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
