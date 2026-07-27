<?php

namespace App\Modules\Shipping\Providers;

use App\Models\ShippingSetting;
use App\Modules\Shipping\Contracts\ShippingProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Adapter RajaOngkir (Komerce) — CEK ONGKIR saja (rate-only).
 * Kredensial di shipping_settings(provider='rajaongkir'): api_key = "Shipping Cost" key,
 * config = ['couriers' => string[]]. Asal ongkir (origin) diambil dari GUDANG penjualan
 * default (warehouses.rajaongkir_origin_id) — lihat Warehouse::rajaongkirOriginId().
 *
 * Base PRODUKSI https://rajaongkir.komerce.id/api/v1, header `key`. Asal & tujuan pakai
 * destination_id database RajaOngkir (bukan area_id/postal Biteship). Booking/cetak resi
 * TIDAK didukung di plan Starter (butuh Enterprise + Komship) → booking manual.
 * Kuota Starter 100 hit/hari → hasil tarif di-cache 6 jam per asal+tujuan+berat+kurir.
 */
class RajaOngkirProvider implements ShippingProvider
{
    public const DEFAULT_BASE = 'https://rajaongkir.komerce.id/api/v1';
    /**
     * Kurir default bila belum diatur di Settings.
     * CATATAN: 'anteraja' sengaja TIDAK didefault — RajaOngkir mengembalikan tarif
     * AnterAja flat (~Rp11.800) yang tidak ikut berat (terbukti 1/5/15 kg sama),
     * sehingga paket berat berpotensi merugi. Bisa diaktifkan manual di Settings.
     */
    public const DEFAULT_COURIERS = ['jne', 'jnt', 'sicepat', 'pos', 'tiki', 'ninja', 'lion', 'sap', 'ide', 'wahana'];

    private ShippingSetting $setting;

    public function __construct(?ShippingSetting $setting = null)
    {
        $this->setting = $setting ?: ShippingSetting::for('rajaongkir');
    }

    public function key(): string
    {
        return 'rajaongkir';
    }

    public function isReady(): bool
    {
        return $this->setting->isConfigured() && $this->originId() !== null;
    }

    private function base(): string
    {
        return $this->setting->effectiveBaseUrl() ?: self::DEFAULT_BASE;
    }

    /**
     * Asal ongkir diambil dari GUDANG penjualan default (kolom rajaongkir_origin_id).
     * Fallback ke config lama shipping_settings demi kompatibilitas transisi.
     */
    private function originId(): ?int
    {
        $fromWarehouse = \App\Core\Inventory\Warehouse::rajaongkirOriginId();
        if ($fromWarehouse) {
            return $fromWarehouse;
        }

        return ((int) ($this->setting->config['origin_id'] ?? 0)) ?: null;
    }

    /** @return string[] */
    public function couriers(): array
    {
        $c = $this->setting->config['couriers'] ?? [];
        return !empty($c) ? array_values($c) : self::DEFAULT_COURIERS;
    }

    private function http()
    {
        return Http::withHeaders(['key' => (string) $this->setting->api_key])
            ->acceptJson()->timeout(20);
    }

    /** Cari wilayah → destination_id RajaOngkir. */
    public function searchAreas(string $query): array
    {
        try {
            $res = $this->http()->get($this->base() . '/destination/domestic-destination', [
                'search' => $query, 'limit' => 10, 'offset' => 0,
            ]);
            if (!$res->successful()) {
                return ['success' => false, 'areas' => [], 'error' => 'RajaOngkir: HTTP ' . $res->status()];
            }
            $areas = collect($res->json('data') ?? [])->map(fn ($d) => [
                'id'          => (string) ($d['id'] ?? ''),
                'name'        => $d['label'] ?? '',
                'postal_code' => $d['zip_code'] ?? '',
                'raw'         => $d,
            ])->all();

            return ['success' => true, 'areas' => $areas, 'error' => null];
        } catch (\Throwable $e) {
            return ['success' => false, 'areas' => [], 'error' => 'RajaOngkir: ' . $e->getMessage()];
        }
    }

    public function rates(array $payload): array
    {
        if (!$this->isReady()) {
            return ['success' => false, 'rates' => [], 'error' => 'RajaOngkir belum dikonfigurasi (API key & alamat asal).'];
        }
        // Kurir instant (Grab/GoSend) tidak ada di RajaOngkir rate-only → arahkan ke manual/WA.
        if (($payload['mode'] ?? null) === 'instant') {
            return ['success' => false, 'rates' => [], 'error' => 'Kurir instant tidak tersedia via RajaOngkir. Hitung manual via WhatsApp.'];
        }

        $destId = $payload['destination_area_id'] ?? null;
        if (empty($destId)) {
            return ['success' => false, 'rates' => [], 'error' => 'Alamat tujuan belum dipilih.'];
        }

        $weight = 0;
        foreach ($payload['items'] ?? [] as $it) {
            $weight += ((int) ($it['weight'] ?? 0)) * max(1, (int) ($it['quantity'] ?? 1));
        }
        $weight = max(1, $weight);

        $originId = $this->originId();
        $couriers = implode(':', $this->couriers());
        $cacheKey = "rajaongkir:rates:{$originId}:{$destId}:{$weight}:" . md5($couriers);

        try {
            $rates = Cache::remember($cacheKey, now()->addHours(6), function () use ($originId, $destId, $weight, $couriers) {
                $res = $this->http()->asForm()->post($this->base() . '/calculate/domestic-cost', [
                    'origin'      => $originId,
                    'destination' => $destId,
                    'weight'      => $weight,
                    'courier'     => $couriers,
                    'price'       => 'lowest',
                ]);
                if (!$res->successful()) {
                    throw new \RuntimeException('HTTP ' . $res->status() . ' ' . mb_substr($res->body(), 0, 200));
                }
                return collect($res->json('data') ?? [])->map(fn ($r) => [
                    'provider'     => 'rajaongkir',
                    'courier_code' => $r['code'] ?? '',
                    'courier_name' => $r['name'] ?? '',
                    'service_code' => $r['service'] ?? '',
                    'service_name' => trim(($r['service'] ?? '') . ' — ' . ($r['description'] ?? ''), ' —'),
                    'price'        => (int) ($r['cost'] ?? 0),
                    'etd'          => $r['etd'] ?? '',
                    'raw'          => $r,
                ])->all();
            });

            return ['success' => true, 'rates' => $rates, 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('RajaOngkir rates gagal: ' . $e->getMessage());
            return ['success' => false, 'rates' => [], 'error' => 'RajaOngkir: ' . $e->getMessage()];
        }
    }

    // Booking/tracking TIDAK didukung di plan Starter (butuh Enterprise + Komship). Booking manual.
    public function createOrder(array $payload): array
    {
        return ['success' => false, 'order_id' => null, 'tracking_id' => null, 'raw' => [],
            'error' => 'Buat resi/AWB butuh langganan RajaOngkir Enterprise + Komship. Untuk saat ini booking manual.'];
    }

    public function track(string $orderId): array
    {
        return ['success' => false, 'error' => 'Tracking via RajaOngkir butuh plan Enterprise.'];
    }

    public function cancel(string $orderId): array
    {
        return ['success' => false, 'error' => 'Tidak didukung.'];
    }
}
