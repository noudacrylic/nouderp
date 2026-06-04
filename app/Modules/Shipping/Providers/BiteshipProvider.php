<?php

namespace App\Modules\Shipping\Providers;

use App\Models\ShippingSetting;
use App\Modules\Shipping\Contracts\ShippingProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Adapter Biteship. Kredensial dari tabel shipping_settings (provider='biteship').
 * Docs: https://biteship.com/id/docs
 */
class BiteshipProvider implements ShippingProvider
{
    /**
     * Katalog kurir fallback (dipakai kalau fetch live /v1/couriers gagal,
     * dan sebagai default "semua kurir" saat belum ada pilihan tersimpan).
     */
    public const DEFAULT_COURIERS = [
        'jne'      => 'JNE',
        'jnt'      => 'J&T Express',
        'sicepat'  => 'SiCepat',
        'anteraja' => 'AnterAja',
        'pos'      => 'POS Indonesia',
        'tiki'     => 'TIKI',
        'ninja'    => 'Ninja Xpress',
        'lion'     => 'Lion Parcel',
        'wahana'   => 'Wahana',
        'ide'      => 'ID Express',
        'sap'      => 'SAP Express',
        'rpx'      => 'RPX',
        'jet'      => 'JET Express',
        'first'    => 'First Logistics',
        'sentral'  => 'Sentral Cargo',
        'gojek'    => 'GoSend (Gojek)',
        'grab'     => 'GrabExpress',
        'lalamove' => 'Lalamove',
        'deliveree'=> 'Deliveree',
        'paxel'    => 'Paxel',
        'borzo'    => 'Borzo',
    ];

    /** Kurir instant/on-demand (same-day) Biteship — butuh koordinat. */
    public const INSTANT_COURIER_CODES = ['grab', 'gojek', 'lalamove', 'deliveree', 'borzo'];

    private ShippingSetting $setting;

    public function __construct(?ShippingSetting $setting = null)
    {
        $this->setting = $setting ?: ShippingSetting::for('biteship');
    }

    public function key(): string
    {
        return 'biteship';
    }

    public function isReady(): bool
    {
        return $this->setting->isConfigured();
    }

    /**
     * Kode kurir yang dipilih user di Settings (config['couriers']).
     * Kosong = belum diatur → caller pakai semua katalog sebagai default.
     * @return string[]
     */
    public function enabledCourierCodes(): array
    {
        $codes = $this->setting->config['couriers'] ?? [];
        return is_array($codes) ? array_values(array_filter($codes)) : [];
    }

    /**
     * Daftar kurir yang tersedia di akun Biteship (live dari /v1/couriers,
     * di-dedup ke level kurir). Fallback ke katalog statis bila gagal.
     * @return array{success:bool, couriers:array<string,string>, error:?string}
     */
    public function availableCouriers(): array
    {
        if (!$this->isReady()) {
            return ['success' => false, 'couriers' => self::DEFAULT_COURIERS, 'error' => 'Biteship belum dikonfigurasi.'];
        }

        try {
            $res  = $this->http()->get('/v1/couriers');
            $data = $res->json() ?? [];

            if (!$res->successful() || ($data['success'] ?? false) === false) {
                return ['success' => false, 'couriers' => self::DEFAULT_COURIERS, 'error' => $data['error'] ?? ('HTTP ' . $res->status())];
            }

            $couriers = [];
            foreach ($data['couriers'] ?? [] as $c) {
                $code = $c['courier_code'] ?? null;
                if ($code && !isset($couriers[$code])) {
                    $couriers[$code] = $c['courier_name'] ?? strtoupper($code);
                }
            }
            ksort($couriers);

            return ['success' => true, 'couriers' => $couriers ?: self::DEFAULT_COURIERS, 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('Biteship couriers gagal', ['error' => $e->getMessage()]);
            return ['success' => false, 'couriers' => self::DEFAULT_COURIERS, 'error' => $e->getMessage()];
        }
    }

    private function http()
    {
        // Biteship memakai API key langsung di header Authorization (bukan Bearer).
        return Http::withHeaders([
            'Authorization' => (string) $this->setting->api_key,
            'Content-Type'  => 'application/json',
        ])->timeout(25)->baseUrl($this->setting->effectiveBaseUrl());
    }

    public function rates(array $payload): array
    {
        if (!$this->isReady()) {
            return ['success' => false, 'rates' => [], 'error' => 'Biteship belum dikonfigurasi (API key kosong / nonaktif).'];
        }

        $body = array_filter([
            'origin_postal_code'      => $payload['origin_postal_code'] ?? null,
            'destination_postal_code' => $payload['destination_postal_code'] ?? null,
            'origin_area_id'          => $payload['origin_area_id'] ?? null,
            'destination_area_id'     => $payload['destination_area_id'] ?? null,
            // Koordinat — wajib agar kurir instant (Grab/GoSend/Lalamove) ikut dihitung.
            'origin_latitude'         => $payload['origin_latitude'] ?? null,
            'origin_longitude'        => $payload['origin_longitude'] ?? null,
            'destination_latitude'    => $payload['destination_latitude'] ?? null,
            'destination_longitude'   => $payload['destination_longitude'] ?? null,
            'couriers'                => $payload['couriers'] ?? null,
            'items'                   => $payload['items'] ?? [],
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        // Default: pakai kurir terpilih di Settings; kalau belum ada pilihan → semua katalog.
        // mode: 'instant' → hanya kurir instant; 'regular' → kecuali instant; null → semua.
        if (empty($body['couriers'])) {
            $enabled = $this->enabledCourierCodes();
            $base    = !empty($enabled) ? $enabled : array_keys(self::DEFAULT_COURIERS);
            $mode    = $payload['mode'] ?? null;
            if ($mode === 'instant') {
                $list = array_values(array_intersect($base, self::INSTANT_COURIER_CODES));
                if (empty($list)) $list = self::INSTANT_COURIER_CODES; // paksa instant walau belum dicentang
            } elseif ($mode === 'regular') {
                $list = array_values(array_diff($base, self::INSTANT_COURIER_CODES));
            } else {
                $list = $base;
            }
            $body['couriers'] = implode(',', $list);
        }

        try {
            $res  = $this->http()->post('/v1/rates/couriers', $body);
            $data = $res->json() ?? [];

            if (!$res->successful() || ($data['success'] ?? false) === false) {
                return [
                    'success' => false,
                    'rates'   => [],
                    'error'   => $data['error'] ?? ('HTTP ' . $res->status()),
                ];
            }

            $rates = collect($data['pricing'] ?? [])->map(fn ($p) => [
                'provider'      => 'biteship',
                'courier_code'  => $p['courier_code'] ?? '',
                'courier_name'  => $p['courier_name'] ?? '',
                'service_code'  => $p['courier_service_code'] ?? '',
                'service_name'  => $p['courier_service_name'] ?? '',
                'price'         => (float) ($p['price'] ?? 0),
                'etd'           => $p['duration'] ?? ($p['shipment_duration_range'] ?? '-'),
                'raw'           => $p,
            ])->values()->all();

            return ['success' => true, 'rates' => $rates, 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('Biteship rates gagal', ['error' => $e->getMessage()]);
            return ['success' => false, 'rates' => [], 'error' => $e->getMessage()];
        }
    }

    public function searchAreas(string $query): array
    {
        if (!$this->isReady()) {
            return ['success' => false, 'areas' => [], 'error' => 'Biteship belum dikonfigurasi.'];
        }

        try {
            $res  = $this->http()->get('/v1/maps/areas', [
                'countries' => 'ID',
                'input'     => $query,
                'type'      => 'single',
            ]);
            $data = $res->json() ?? [];

            if (!$res->successful() || ($data['success'] ?? false) === false) {
                return ['success' => false, 'areas' => [], 'error' => $data['error'] ?? ('HTTP ' . $res->status())];
            }

            $areas = collect($data['areas'] ?? [])->map(fn ($a) => [
                'id'          => $a['id'] ?? '',
                'name'        => $a['name'] ?? '',
                'postal_code' => $a['postal_code'] ?? null,
                // Hierarki administratif (dipakai auto-isi provinsi/kota/kecamatan).
                'province'    => $a['administrative_division_level_1_name'] ?? null,
                'city'        => $a['administrative_division_level_2_name'] ?? null,
                'district'    => $a['administrative_division_level_3_name'] ?? null,
                'subdistrict' => $a['administrative_division_level_4_name'] ?? null,
                'raw'         => $a,
            ])->values()->all();

            return ['success' => true, 'areas' => $areas, 'error' => null];
        } catch (\Throwable $e) {
            return ['success' => false, 'areas' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Resolusi service_code dari courier_code + nama layanan (untuk SO lama yang
     * hanya simpan nama). Cocokkan ke /v1/couriers (exact atau nama tersimpan
     * mengandung nama layanan). Return null bila tak ketemu.
     */
    public function serviceCodeFor(string $courierCode, ?string $serviceName): ?string
    {
        $serviceName = trim((string) $serviceName);
        if (!$this->isReady() || $courierCode === '' || $serviceName === '') {
            return null;
        }

        try {
            $res  = $this->http()->get('/v1/couriers');
            $data = $res->json() ?? [];
            $needle = mb_strtolower($serviceName);

            foreach ($data['couriers'] ?? [] as $c) {
                if (($c['courier_code'] ?? '') !== $courierCode) {
                    continue;
                }
                $svcName  = mb_strtolower(trim($c['courier_service_name'] ?? ''));
                $svcCode  = $c['courier_service_code'] ?? null;
                $combined = mb_strtolower(trim(($c['courier_name'] ?? '') . ' ' . ($c['courier_service_name'] ?? '')));
                if ($svcName !== '' && ($needle === $svcName || $needle === $combined || str_contains($needle, $svcName))) {
                    return $svcCode;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Biteship serviceCodeFor gagal', ['error' => $e->getMessage()]);
        }

        return null;
    }

    public function createOrder(array $payload): array
    {
        if (!$this->isReady()) {
            return ['success' => false, 'order_id' => null, 'tracking_id' => null, 'raw' => [], 'error' => 'Biteship belum dikonfigurasi.'];
        }

        try {
            $res  = $this->http()->post('/v1/orders', $payload);
            $data = $res->json() ?? [];

            if (!$res->successful() || ($data['success'] ?? false) === false) {
                return [
                    'success'     => false,
                    'order_id'    => null,
                    'tracking_id' => null,
                    'raw'         => $data,
                    'error'       => $data['error'] ?? ('HTTP ' . $res->status()),
                ];
            }

            // Biteship: id = order id; resi di courier.waybill_id (fallback tracking_id).
            $orderId = $data['id'] ?? null;
            $waybill = $data['courier']['waybill_id']
                ?? ($data['courier']['tracking_id'] ?? ($data['courier']['booking_id'] ?? null));

            return [
                'success'     => true,
                'order_id'    => $orderId,
                'tracking_id' => $waybill,
                'raw'         => $data,
                'error'       => null,
            ];
        } catch (\Throwable $e) {
            Log::warning('Biteship createOrder gagal', ['error' => $e->getMessage()]);
            return ['success' => false, 'order_id' => null, 'tracking_id' => null, 'raw' => [], 'error' => $e->getMessage()];
        }
    }

    public function track(string $orderId): array
    {
        if (!$this->isReady()) {
            return ['success' => false, 'status' => null, 'history' => [], 'raw' => [], 'error' => 'Biteship belum dikonfigurasi.'];
        }

        try {
            $res  = $this->http()->get('/v1/orders/' . $orderId);
            $data = $res->json() ?? [];

            if (!$res->successful() || ($data['success'] ?? true) === false) {
                return ['success' => false, 'status' => null, 'history' => [], 'raw' => $data, 'error' => $data['error'] ?? ('HTTP ' . $res->status())];
            }

            return [
                'success' => true,
                'status'  => $data['status'] ?? ($data['courier']['history'][0]['status'] ?? null),
                'waybill' => $data['courier']['waybill_id'] ?? null,
                'history' => $data['courier']['history'] ?? ($data['history'] ?? []),
                'raw'     => $data,
                'error'   => null,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'status' => null, 'history' => [], 'raw' => [], 'error' => $e->getMessage()];
        }
    }

    public function cancel(string $orderId): array
    {
        return ['success' => false, 'error' => 'cancel belum diimplementasikan.'];
    }
}
