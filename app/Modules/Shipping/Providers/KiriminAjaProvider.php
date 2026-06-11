<?php

namespace App\Modules\Shipping\Providers;

use App\Models\ShippingSetting;
use App\Modules\Shipping\Contracts\ShippingProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Adapter KiriminAja (provider ke-2, Fase 6). Kredensial dari tabel shipping_settings
 * (provider='kiriminaja'). Auth: Bearer token. Area model: kecamatan/kelurahan ID
 * (BEDA dari Biteship yang pakai area_id tunggal).
 *
 * Endpoint paths diambil dari SDK resmi `kiriminaja/php` (src/Repositories/*):
 *   Express : POST v6.1/shipping_price · v6.1/request_pickup · v3/cancel_shipment · tracking
 *   Instant : POST v4/instant/pricing · v4/instant/pickup/request ·
 *             GET v4/instant/tracking/{id} · DELETE v4/instant/pickup/void/{id}
 *   Area    : POST province · city · kecamatan · kelurahan · v2/get_address_by_name
 *   Saldo   : GET v6.2/credit/balance
 *
 * CATATAN: dibangun dari spec SDK; respons di-parse defensif (multi-key fallback) dan
 * belum diuji live (butuh API key). Live-test akan mengonfirmasi bentuk respons.
 */
class KiriminAjaProvider implements ShippingProvider
{
    public const SANDBOX_BASE_URL    = 'https://tdev.kiriminaja.com';
    public const PRODUCTION_BASE_URL = 'https://client.kiriminaja.com';

    private const EP_PRICE_EXPRESS   = '/api/mitra/v6.1/shipping_price';
    private const EP_REQUEST_EXPRESS = '/api/mitra/v6.1/request_pickup';
    private const EP_TRACKING        = '/api/mitra/tracking';
    private const EP_CANCEL          = '/api/mitra/v3/cancel_shipment';
    private const EP_PRICE_INSTANT   = '/api/mitra/v4/instant/pricing';
    private const EP_REQUEST_INSTANT = '/api/mitra/v4/instant/pickup/request';
    private const EP_TRACK_INSTANT   = '/api/mitra/v4/instant/tracking/';   // + orderId
    private const EP_CANCEL_INSTANT  = '/api/mitra/v4/instant/pickup/void/'; // + orderId
    private const EP_BALANCE         = '/api/mitra/v6.2/credit/balance';
    private const EP_ADDR_BY_NAME    = '/api/mitra/v2/get_address_by_name';

    /** Katalog kurir express fallback (dipakai saat belum ada pilihan tersimpan). */
    public const DEFAULT_COURIERS = [
        'jne'       => 'JNE',
        'jnt'       => 'J&T Express',
        'sicepat'   => 'SiCepat',
        'anteraja'  => 'AnterAja',
        'pos'       => 'POS Indonesia',
        'tiki'      => 'TIKI',
        'ninja'     => 'Ninja Xpress',
        'lion'      => 'Lion Parcel',
        'wahana'    => 'Wahana',
        'ide'       => 'ID Express',
        'sap'       => 'SAP Express',
        'jnt_cargo' => 'J&T Cargo',
        'sentral'   => 'Sentral Cargo',
    ];

    /** Layanan instant yang didukung KiriminAja. */
    public const INSTANT_SERVICES = [
        'gosend'       => 'GoSend',
        'grab_express' => 'GrabExpress',
    ];

    private ShippingSetting $setting;

    public function __construct(?ShippingSetting $setting = null)
    {
        $this->setting = $setting ?: ShippingSetting::for('kiriminaja');
    }

    public function key(): string
    {
        return 'kiriminaja';
    }

    public function isReady(): bool
    {
        return $this->setting->isConfigured();
    }

    /**
     * Kode kurir terpilih di Settings (config['couriers']). Kosong = semua katalog.
     * @return string[]
     */
    public function enabledCourierCodes(): array
    {
        $codes = $this->setting->config['couriers'] ?? [];
        return is_array($codes) ? array_values(array_filter($codes)) : [];
    }

    /** Base URL efektif: base_url manual menang; else sandbox/prod sesuai is_production. */
    private function baseUrl(): string
    {
        if (!empty($this->setting->base_url)) return rtrim($this->setting->base_url, '/');
        return $this->setting->is_production ? self::PRODUCTION_BASE_URL : self::SANDBOX_BASE_URL;
    }

    private function http()
    {
        // KiriminAja: Authorization: Bearer {api_key}
        return Http::withToken((string) $this->setting->api_key)
            ->acceptJson()
            ->timeout(25)
            ->baseUrl($this->baseUrl());
    }

    /**
     * Cek ongkir. Memilih express atau instant via $payload['mode'] (default 'express').
     * Area KiriminAja pakai kecamatan ID — kirim lewat origin_kiriminaja_id / destination_kiriminaja_id
     * (express) atau koordinat lat/long (instant). Tanpa itu → error jelas (bukan call API salah id).
     */
    public function rates(array $payload): array
    {
        if (!$this->isReady()) {
            return ['success' => false, 'rates' => [], 'error' => 'KiriminAja belum dikonfigurasi (API key kosong / nonaktif).'];
        }

        return ($payload['mode'] ?? 'express') === 'instant'
            ? $this->ratesInstant($payload)
            : $this->ratesExpress($payload);
    }

    private function ratesExpress(array $payload): array
    {
        $origin = $payload['origin_kiriminaja_id'] ?? null;       // kecamatan id asal
        $dest   = $payload['destination_kiriminaja_id'] ?? null;  // kecamatan id tujuan
        if (!$origin || !$dest) {
            return ['success' => false, 'rates' => [], 'error' => 'Area KiriminAja (kecamatan ID asal/tujuan) belum dipetakan.'];
        }

        $couriers = $payload['couriers'] ?? null;
        $courierList = $couriers
            ? array_map('trim', explode(',', $couriers))
            : ($this->enabledCourierCodes() ?: array_keys(self::DEFAULT_COURIERS));

        // Berat & nilai barang: utamakan flat (weight/item_value), fallback agregat dari items[].
        $items     = $payload['items'] ?? [];
        $sumWeight = collect($items)->sum(fn ($i) => (int) ($i['weight'] ?? 0) * max(1, (int) ($i['quantity'] ?? 1)));
        $sumValue  = collect($items)->sum(fn ($i) => (float) ($i['value'] ?? 0) * max(1, (int) ($i['quantity'] ?? 1)));

        $body = [
            'origin'      => $origin,
            'destination' => $dest,
            'weight'      => max(1, (int) ($payload['weight'] ?? ($sumWeight ?: 1000))),
            'item_value'  => (int) ($payload['item_value'] ?? $sumValue),
            'insurance'   => (int) ($payload['insurance'] ?? 0),
            'courier'     => array_values($courierList),
        ];

        try {
            $res  = $this->http()->post(self::EP_PRICE_EXPRESS, $body);
            $data = $res->json() ?? [];
            if (!$res->successful() || ($data['status'] ?? false) === false) {
                return ['success' => false, 'rates' => [], 'error' => $data['text'] ?? $data['message'] ?? ('HTTP ' . $res->status())];
            }

            // Respons KiriminAja: results[] berisi service per kurir (key di-parse defensif).
            $list = $data['results'] ?? $data['data'] ?? [];
            $rates = collect($list)->map(fn ($r) => [
                'provider'     => 'kiriminaja',
                'courier_code' => $r['service'] ?? $r['courier'] ?? '',
                'courier_name' => $r['name'] ?? strtoupper($r['service'] ?? ''),
                'service_code' => $r['service_type'] ?? $r['type'] ?? ($r['service'] ?? ''),
                'service_name' => $r['service_name'] ?? $r['service_type'] ?? ($r['name'] ?? ''),
                'price'        => (float) ($r['cost'] ?? $r['price'] ?? $r['final_price'] ?? 0),
                'etd'          => $r['etd'] ?? $r['estimation'] ?? ($r['duration'] ?? '-'),
                'raw'          => $r,
            ])->filter(fn ($x) => $x['price'] > 0)->values()->all();

            return ['success' => true, 'rates' => $rates, 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('KiriminAja rates gagal', ['error' => $e->getMessage()]);
            return ['success' => false, 'rates' => [], 'error' => $e->getMessage()];
        }
    }

    private function ratesInstant(array $payload): array
    {
        // Terima bentuk nested ['lat','long','address'] ATAU flat (origin_latitude/dst dari payload cek-ongkir).
        $o = $payload['origin'] ?? array_filter([
            'lat'  => $payload['origin_latitude']  ?? null,
            'long' => $payload['origin_longitude'] ?? null,
        ], fn ($v) => $v !== null);
        $d = $payload['destination'] ?? array_filter([
            'lat'  => $payload['destination_latitude']  ?? null,
            'long' => $payload['destination_longitude'] ?? null,
        ], fn ($v) => $v !== null);
        if (empty($o['lat']) || empty($d['lat'])) {
            return ['success' => false, 'rates' => [], 'error' => 'Instant butuh koordinat lat/long asal & tujuan.'];
        }

        $body = [
            'service'     => $payload['services'] ?? array_keys(self::INSTANT_SERVICES),
            'item_price'  => (int) ($payload['item_value'] ?? 0),
            'origin'      => ['lat' => $o['lat'], 'long' => $o['long'], 'address' => $o['address'] ?? ''],
            'destination' => ['lat' => $d['lat'], 'long' => $d['long'], 'address' => $d['address'] ?? ''],
            'weight'      => max(1, (int) ($payload['weight'] ?? 1000)),
            'vehicle'     => $payload['vehicle'] ?? 'motor',
            'timezone'    => $payload['timezone'] ?? 'Asia/Jakarta',
        ];

        try {
            $res  = $this->http()->post(self::EP_PRICE_INSTANT, $body);
            $data = $res->json() ?? [];
            if (!$res->successful() || ($data['status'] ?? false) === false) {
                return ['success' => false, 'rates' => [], 'error' => $data['text'] ?? $data['message'] ?? ('HTTP ' . $res->status())];
            }

            $list = $data['results'] ?? $data['data'] ?? [];
            $rates = collect($list)->map(fn ($r) => [
                'provider'     => 'kiriminaja',
                'courier_code' => $r['service'] ?? '',
                'courier_name' => self::INSTANT_SERVICES[$r['service'] ?? ''] ?? strtoupper($r['service'] ?? ''),
                'service_code' => $r['service_type'] ?? 'instant',
                'service_name' => $r['service_name'] ?? 'Instant',
                'price'        => (float) ($r['price'] ?? $r['cost'] ?? 0),
                'etd'          => $r['etd'] ?? 'Same day',
                'raw'          => $r,
            ])->filter(fn ($x) => $x['price'] > 0)->values()->all();

            return ['success' => true, 'rates' => $rates, 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('KiriminAja instant rates gagal', ['error' => $e->getMessage()]);
            return ['success' => false, 'rates' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Cari area → kecamatan/kelurahan id. KiriminAja: POST v2/get_address_by_name {search}.
     * Normalisasi ke bentuk seragam {id, name, postal_code, ...}.
     */
    public function searchAreas(string $query): array
    {
        if (!$this->isReady()) {
            return ['success' => false, 'areas' => [], 'error' => 'KiriminAja belum dikonfigurasi.'];
        }

        try {
            $res  = $this->http()->post(self::EP_ADDR_BY_NAME, ['search' => $query]);
            $data = $res->json() ?? [];
            if (!$res->successful() || ($data['status'] ?? false) === false) {
                return ['success' => false, 'areas' => [], 'error' => $data['text'] ?? ('HTTP ' . $res->status())];
            }

            $list = $data['datas'] ?? $data['data'] ?? $data['results'] ?? [];
            $areas = collect($list)->map(fn ($a) => [
                'id'          => $a['kecamatan_id'] ?? $a['subdistrict_id'] ?? $a['id'] ?? '',
                'name'        => $a['name'] ?? trim(($a['kecamatan'] ?? '') . ', ' . ($a['kabupaten'] ?? '')),
                'postal_code' => $a['zipcode'] ?? $a['postal_code'] ?? null,
                'province'    => $a['provinsi'] ?? $a['province'] ?? null,
                'city'        => $a['kabupaten'] ?? $a['city'] ?? null,
                'district'    => $a['kecamatan'] ?? $a['district'] ?? null,
                'subdistrict' => $a['kelurahan'] ?? $a['subdistrict'] ?? null,
                'raw'         => $a,
            ])->values()->all();

            return ['success' => true, 'areas' => $areas, 'error' => null];
        } catch (\Throwable $e) {
            return ['success' => false, 'areas' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Booking. $payload['mode'] = 'express' (default) | 'instant'.
     * Express → v6.1/request_pickup; Instant → v4/instant/pickup/request.
     * @return array{success:bool, order_id:?string, tracking_id:?string, raw:array, error:?string}
     */
    public function createOrder(array $payload): array
    {
        if (!$this->isReady()) {
            return ['success' => false, 'order_id' => null, 'tracking_id' => null, 'raw' => [], 'error' => 'KiriminAja belum dikonfigurasi.'];
        }

        $isInstant = ($payload['mode'] ?? 'express') === 'instant';
        $endpoint  = $isInstant ? self::EP_REQUEST_INSTANT : self::EP_REQUEST_EXPRESS;
        // Caller (SalesDeliveryController) sudah menyusun body sesuai skema KiriminAja.
        $body = $payload['body'] ?? $payload;

        try {
            $res  = $this->http()->post($endpoint, $body);
            $data = $res->json() ?? [];
            if (!$res->successful() || ($data['status'] ?? false) === false) {
                return ['success' => false, 'order_id' => null, 'tracking_id' => null, 'raw' => $data,
                        'error' => $data['text'] ?? $data['message'] ?? ('HTTP ' . $res->status())];
            }

            // Resi/AWB: KiriminAja kembalikan di details[].awb / order_id (parse defensif).
            $details = $data['details'][0] ?? $data['data'][0] ?? $data['details'] ?? $data['data'] ?? [];
            $orderId = $data['order_id'] ?? ($details['order_id'] ?? null);
            $awb     = $details['awb'] ?? ($details['airwaybill'] ?? ($data['awb'] ?? null));

            return ['success' => true, 'order_id' => $orderId, 'tracking_id' => $awb, 'raw' => $data, 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('KiriminAja createOrder gagal', ['error' => $e->getMessage()]);
            return ['success' => false, 'order_id' => null, 'tracking_id' => null, 'raw' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Tracking. Mode 'express' → POST tracking {order_id}; 'instant' → GET tracking/{orderId}.
     * (Param $mode opsional agar tetap kompatibel dgn kontrak ShippingProvider::track.)
     */
    public function track(string $orderId, string $mode = 'express'): array
    {
        if (!$this->isReady()) {
            return ['success' => false, 'status' => null, 'history' => [], 'raw' => [], 'error' => 'KiriminAja belum dikonfigurasi.'];
        }

        try {
            // Instant: GET v4/instant/tracking/{orderId}. Express: POST tracking {order_id}.
            $res = $mode === 'instant'
                ? $this->http()->get(self::EP_TRACK_INSTANT . rawurlencode($orderId))
                : $this->http()->post(self::EP_TRACKING, ['order_id' => $orderId]);
            $data = $res->json() ?? [];
            if (!$res->successful() || ($data['status'] ?? true) === false) {
                return ['success' => false, 'status' => null, 'history' => [], 'raw' => $data, 'error' => $data['text'] ?? ('HTTP ' . $res->status())];
            }

            $d = $data['data'] ?? $data['details'] ?? $data;
            return [
                'success' => true,
                'status'  => $d['status'] ?? ($d['history'][0]['status'] ?? null),
                'waybill' => $d['awb'] ?? null,
                'history' => $d['history'] ?? ($d['manifest'] ?? []),
                'raw'     => $data,
                'error'   => null,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'status' => null, 'history' => [], 'raw' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Batalkan booking. Mode 'express' → POST v3/cancel_shipment {awb, reason} (query);
     * 'instant' → DELETE v4/instant/pickup/void/{orderId}. Sesuai SDK resmi (awb + reason,
     * BUKAN order_id). $orderId di sini = AWB/order id resi yang dibatalkan.
     */
    public function cancel(string $orderId, string $reason = 'Dibatalkan dari ERP', string $mode = 'express'): array
    {
        if (!$this->isReady()) {
            return ['success' => false, 'error' => 'KiriminAja belum dikonfigurasi.'];
        }
        try {
            if ($mode === 'instant') {
                $res = $this->http()->delete(self::EP_CANCEL_INSTANT . rawurlencode($orderId));
            } else {
                // SDK: cancel_shipment dgn awb + reason sebagai query string.
                $res = $this->http()->post(self::EP_CANCEL . '?' . http_build_query([
                    'awb'    => $orderId,
                    'reason' => $reason,
                ]));
            }
            $data = $res->json() ?? [];
            $ok   = $res->successful() && ($data['status'] ?? false) !== false;
            return ['success' => $ok, 'raw' => $data, 'error' => $ok ? null : ($data['text'] ?? $data['message'] ?? 'gagal')];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** Saldo deposit KiriminAja (prepaid). GET v6.2/credit/balance. */
    public function balance(): array
    {
        if (!$this->isReady()) {
            return ['success' => false, 'balance' => 0, 'error' => 'KiriminAja belum dikonfigurasi.'];
        }
        try {
            $res  = $this->http()->get(self::EP_BALANCE);
            $data = $res->json() ?? [];
            if (!$res->successful() || ($data['status'] ?? false) === false) {
                return ['success' => false, 'balance' => 0, 'error' => $data['text'] ?? ('HTTP ' . $res->status())];
            }
            $balance = $data['balance'] ?? ($data['data']['balance'] ?? 0);
            return ['success' => true, 'balance' => (float) $balance, 'error' => null];
        } catch (\Throwable $e) {
            return ['success' => false, 'balance' => 0, 'error' => $e->getMessage()];
        }
    }
}
