<?php

namespace App\Modules\Shipping\Providers;

use App\Models\ShippingSetting;
use App\Modules\Shipping\Contracts\ShippingProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Adapter Jubelio Shipment (agregator kurir milik Jubelio) — dibangun dari
 * "API Contract Jubelio Shipment v1.8".
 *
 * Alasan dipakai: J&T Cargo (dan kurir CARGO lain) bisa terbit resi TANPA badan usaha,
 * sesuatu yang menghambat Biteship & KiriminAja. Lihat dokumen di "exel marketplace/".
 *
 * Beda mendasar dari provider lain:
 * - Auth 2 langkah: client_id + client_secret ditukar jadi bearer token berumur 24 jam
 *   (`expires_in` detik), jadi tokennya di-cache, bukan diminta tiap request.
 * - Kurir diidentifikasi ANGKA (`courier_id` + `courier_service_id`), bukan kode huruf
 *   seperti 'jne'/'reg'. Nilai itu disimpan apa adanya ke kolom courier/service code.
 * - Alamat memakai `area_id` (kode kelurahan 10 digit) + `zipcode`; zipcode WAJIB.
 *
 * CATATAN: ditulis dari kontrak API, BELUM diuji dengan kredensial sungguhan
 * (client_id/secret menunggu onboarding dari PIC Jubelio). Respons di-parse defensif.
 */
class JubelioShipmentProvider implements ShippingProvider
{
    public const PRODUCTION_BASE_URL = 'https://api-shipment.jubelio.com';
    public const SANDBOX_BASE_URL    = 'https://api-shipment.sandbox.jubelio.com';

    private const EP_TOKEN      = '/auth/generate-token';
    private const EP_RATES_ALL  = '/rates/all';
    private const EP_RATES      = '/rates';
    private const EP_CREATE     = '/shipments/create';
    private const EP_CANCEL     = '/shipments/cancel';
    private const EP_AWB        = '/shipments/awb/';       // + awb
    private const EP_CATEGORIES = '/services/categories';
    private const EP_REGIONS    = '/regions';

    /** Kategori layanan bawaan kontrak v1.8 (dipakai bila /services/categories gagal). */
    public const DEFAULT_CATEGORIES = [
        1 => 'REGULER',
        2 => 'EKONOMI',
        3 => 'NEXTDAY',
        4 => 'INSTANT',
        5 => 'SAMEDAY',
        6 => 'CARGO',
    ];

    /**
     * Status pengiriman Jubelio → status internal SalesDelivery.
     * Dipakai webhook & tracking supaya satu kosakata dipakai seluruh ERP.
     */
    public const STATUS_MAP = [
        'WAITING'              => 'booked',
        'CONFIRMED_BY_COURIER' => 'booked',
        'ON_THE_WAY_PICK_UP'   => 'booked',
        'PICKED_UP'            => 'picked_up',
        'ON_DELIVERY'          => 'in_transit',
        'ON_HOLD'              => 'in_transit',
        'DELIVERED'            => 'delivered',
        'RETURNED'             => 'returned',
        'CANCELED'             => 'cancelled',
        'SHIPMENT_ISSUE'       => 'failed',
    ];

    private ShippingSetting $setting;

    public function __construct(?ShippingSetting $setting = null)
    {
        $this->setting = $setting ?: ShippingSetting::for('jubelio_shipment');
    }

    public function key(): string
    {
        return 'jubelio_shipment';
    }

    /** Butuh DUA kredensial, jadi tidak cukup memakai isConfigured() bawaan. */
    public function isReady(): bool
    {
        return (bool) $this->setting->is_enabled
            && $this->clientId() !== ''
            && $this->clientSecret() !== '';
    }

    public function clientId(): string
    {
        return trim((string) ($this->setting->config['client_id'] ?? ''));
    }

    /** client_secret disimpan di kolom api_key agar seragam dengan provider lain. */
    private function clientSecret(): string
    {
        return trim((string) $this->setting->api_key);
    }

    public function baseUrl(): string
    {
        $manual = trim((string) $this->setting->base_url);
        if ($manual !== '') {
            return rtrim($manual, '/');
        }

        return $this->setting->is_production ? self::PRODUCTION_BASE_URL : self::SANDBOX_BASE_URL;
    }

    /** Rahasia webhook (Dashboard Jubelio Shipment → Setting → Developer → Webhook). */
    public function webhookSecret(): string
    {
        return (string) $this->setting->webhook_token;
    }

    /**
     * Kategori layanan yang dicentang di Pengaturan (mis. REGULER + CARGO).
     * Kosong = tampilkan semua kategori yang dikembalikan Jubelio.
     * @return int[]
     */
    public function enabledCategoryIds(): array
    {
        $ids = $this->setting->config['categories'] ?? [];

        return is_array($ids) ? array_values(array_map('intval', array_filter($ids))) : [];
    }

    /* ===================================================================
       Auth
       =================================================================== */

    private function cacheKey(): string
    {
        return 'jubelio_shipment_token_' . md5($this->baseUrl() . '|' . $this->clientId());
    }

    /** Buang token tersimpan — dipanggil saat kredensial diganti atau kena 401. */
    public function forgetToken(): void
    {
        Cache::forget($this->cacheKey());
    }

    /**
     * Bearer token, di-cache sampai sedikit sebelum kedaluwarsa.
     * @return array{success:bool, token:?string, error:?string}
     */
    public function token(bool $fresh = false): array
    {
        if (!$this->isReady()) {
            return ['success' => false, 'token' => null, 'error' => 'Jubelio Shipment belum dikonfigurasi (client id/secret kosong atau nonaktif).'];
        }

        if ($fresh) {
            $this->forgetToken();
        }

        $cached = Cache::get($this->cacheKey());
        if (is_string($cached) && $cached !== '') {
            return ['success' => true, 'token' => $cached, 'error' => null];
        }

        try {
            $res = Http::acceptJson()->timeout(25)
                ->post($this->baseUrl() . self::EP_TOKEN, [
                    'client_id'     => $this->clientId(),
                    'client_secret' => $this->clientSecret(),
                ]);

            $data  = $res->json() ?? [];
            $token = $data['token'] ?? ($data['data']['token'] ?? null);

            if (!$res->successful() || !$token) {
                return ['success' => false, 'token' => null, 'error' => $this->errorFrom($data, $res->status())];
            }

            // Beri margin 60 detik supaya token tidak kedaluwarsa di tengah request.
            $ttl = max(60, (int) ($data['expires_in'] ?? 86400) - 60);
            Cache::put($this->cacheKey(), $token, $ttl);

            return ['success' => true, 'token' => $token, 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('Jubelio Shipment token gagal', ['error' => $e->getMessage()]);

            return ['success' => false, 'token' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Panggil endpoint ber-token. Sekali retry dengan token baru bila kena 401 —
     * token bisa dicabut dari sisi Jubelio sebelum masa berlakunya habis.
     *
     * @return array{ok:bool, data:mixed, status:int, error:?string}
     */
    private function call(string $method, string $path, array $body = [], array $query = []): array
    {
        $attempt = function (bool $fresh) use ($method, $path, $body, $query) {
            $tok = $this->token($fresh);
            if (!$tok['success']) {
                return ['ok' => false, 'data' => [], 'status' => 0, 'error' => $tok['error']];
            }

            $req = Http::withToken($tok['token'])->acceptJson()->timeout(30);
            $url = $this->baseUrl() . $path;

            $res = $method === 'GET' ? $req->get($url, $query) : $req->post($url, $body);
            $data = $res->json() ?? [];

            return [
                'ok'     => $res->successful(),
                'data'   => $data,
                'status' => $res->status(),
                'error'  => $res->successful() ? null : $this->errorFrom($data, $res->status()),
            ];
        };

        try {
            $out = $attempt(false);
            if (!$out['ok'] && $out['status'] === 401) {
                $out = $attempt(true);
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('Jubelio Shipment request gagal', ['path' => $path, 'error' => $e->getMessage()]);

            return ['ok' => false, 'data' => [], 'status' => 0, 'error' => $e->getMessage()];
        }
    }

    private function errorFrom($data, int $status): string
    {
        if (is_array($data)) {
            $msg = $data['message'] ?? ($data['error'] ?? null);
            if (is_string($msg) && $msg !== '') {
                return $msg . ' (HTTP ' . $status . ')';
            }
        }

        return 'HTTP ' . $status;
    }

    /* ===================================================================
       Diagnostik untuk halaman Pengaturan
       =================================================================== */

    /**
     * Uji kredensial: minta token baru lalu ambil daftar kategori layanan.
     * @return array{success:bool, message:string, categories:array<int,string>}
     */
    public function testConnection(): array
    {
        $tok = $this->token(true);
        if (!$tok['success']) {
            return ['success' => false, 'message' => $tok['error'] ?? 'Gagal mengambil token.', 'categories' => []];
        }

        $cat = $this->serviceCategories();

        return [
            'success'    => true,
            'message'    => $cat['success']
                ? 'Token berhasil diambil dan ' . count($cat['categories']) . ' kategori layanan terbaca.'
                : 'Token berhasil diambil, tetapi daftar kategori gagal dibaca: ' . ($cat['error'] ?? '-'),
            'categories' => $cat['categories'],
        ];
    }

    /** @return array{success:bool, categories:array<int,string>, error:?string} */
    public function serviceCategories(): array
    {
        $res = $this->call('GET', self::EP_CATEGORIES);
        if (!$res['ok']) {
            return ['success' => false, 'categories' => self::DEFAULT_CATEGORIES, 'error' => $res['error']];
        }

        $rows = is_array($res['data']) ? ($res['data']['data'] ?? $res['data']) : [];
        $out  = [];
        foreach ((array) $rows as $r) {
            $id = (int) ($r['service_category_id'] ?? 0);
            if ($id > 0) {
                $out[$id] = (string) ($r['name'] ?? $id);
            }
        }

        return ['success' => true, 'categories' => $out ?: self::DEFAULT_CATEGORIES, 'error' => null];
    }

    /* ===================================================================
       ShippingProvider
       =================================================================== */

    public function rates(array $payload): array
    {
        if (!$this->isReady()) {
            return ['success' => false, 'rates' => [], 'error' => 'Jubelio Shipment belum dikonfigurasi.'];
        }

        $items  = $this->mapItems($payload['items'] ?? []);
        $weight = $this->totalWeight($items);

        // Asal ongkir: dari payload bila pemanggil menyebut gudang (Cek Ongkir internal),
        // selain itu jatuh ke gudang penjualan default — etalase tidak mengenal gudang,
        // sama seperti perilaku RajaOngkir sebelumnya.
        if (empty($payload['origin_postal_code'])) {
            $wh = \App\Core\Inventory\Warehouse::shippingOrigin();
            if ($wh) {
                $payload['origin_postal_code'] = $payload['origin_postal_code'] ?? $wh->postal_code;
                $payload['origin_jubelio_id']  = $payload['origin_jubelio_id'] ?? $wh->jubelio_area_id;
                $payload['origin_latitude']    = $payload['origin_latitude'] ?? $wh->latitude;
                $payload['origin_longitude']   = $payload['origin_longitude'] ?? $wh->longitude;
            }
        }

        // Utamakan area_id versi Jubelio; kamus wilayah tiap agregator berbeda, jadi
        // area_id Biteship TIDAK boleh dipakai di sini (akan ditolak / salah tarif).
        $body = [
            'origin'      => $this->addressPoint(
                $payload['origin_jubelio_id'] ?? null,
                $payload['origin_postal_code'] ?? null,
                $payload['origin_latitude'] ?? null,
                $payload['origin_longitude'] ?? null,
            ),
            'destination' => $this->addressPoint(
                $payload['destination_jubelio_id'] ?? null,
                $payload['destination_postal_code'] ?? null,
                $payload['destination_latitude'] ?? null,
                $payload['destination_longitude'] ?? null,
            ),
            'weight'      => $weight,
            'items'       => $items,
            'total_value' => (float) ($payload['total_value'] ?? 0),
        ];

        if (empty($body['origin']['zipcode']) || empty($body['destination']['zipcode'])) {
            return ['success' => false, 'rates' => [], 'error' => 'Kode pos asal & tujuan wajib diisi untuk Jubelio Shipment.'];
        }

        $pkg = array_filter([
            'length' => (float) ($payload['package_length'] ?? 0) ?: null,
            'width'  => (float) ($payload['package_width'] ?? 0) ?: null,
            'height' => (float) ($payload['package_height'] ?? 0) ?: null,
        ]);
        if ($pkg) {
            $body['package_detail'] = $pkg;
        }

        // /rates/all mengembalikan semua kurir sekaligus. /rates (tunggal) mewajibkan
        // service_category_id, jadi hanya dipakai bila pemanggil meminta 1 kategori.
        $categoryId = (int) ($payload['service_category_id'] ?? 0);
        if ($categoryId > 0) {
            $body['service_category_id'] = $categoryId;
            $res = $this->call('POST', self::EP_RATES, $body);
        } else {
            $res = $this->call('POST', self::EP_RATES_ALL, $body);
        }

        if (!$res['ok']) {
            return ['success' => false, 'rates' => [], 'error' => $res['error']];
        }

        $rows    = is_array($res['data']) ? ($res['data']['data'] ?? $res['data']) : [];
        $allowed = $this->enabledCategoryIds();
        $rates   = [];

        foreach ((array) $rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            if ($allowed && !in_array((int) ($r['service_category_id'] ?? 0), $allowed, true)
                && !in_array($this->categoryIdByName($r['courier_service_category'] ?? ''), $allowed, true)) {
                continue;
            }

            $rates[] = [
                'provider'     => $this->key(),
                // Jubelio memakai ID numerik; disimpan sebagai string agar muat di kolom kode.
                'courier_code' => (string) ($r['courier_id'] ?? ''),
                'courier_name' => (string) ($r['courier_name'] ?? ''),
                'service_code' => (string) ($r['courier_service_id'] ?? ''),
                'service_name' => (string) ($r['courier_service_name'] ?? ($r['courier_service_code'] ?? '')),
                'price'        => (float) ($r['final_rates'] ?? ($r['rates'] ?? 0)),
                'etd'          => $this->etaText($r['eta_from'] ?? null, $r['eta_to'] ?? null),
                'raw'          => $r,
            ];
        }

        usort($rates, fn ($a, $b) => $a['price'] <=> $b['price']);

        return ['success' => true, 'rates' => $rates, 'error' => null];
    }

    public function searchAreas(string $query): array
    {
        if (!$this->isReady()) {
            return ['success' => false, 'areas' => [], 'error' => 'Jubelio Shipment belum dikonfigurasi.'];
        }

        $res = $this->call('GET', self::EP_REGIONS, [], ['name' => $query]);
        if (!$res['ok']) {
            return ['success' => false, 'areas' => [], 'error' => $res['error']];
        }

        $rows  = is_array($res['data']) ? ($res['data']['data'] ?? $res['data']) : [];
        $areas = [];
        foreach ((array) $rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $areas[] = [
                'id'          => (string) ($r['area_id'] ?? ''),
                'name'        => (string) ($r['name'] ?? ''),
                'postal_code' => $r['zipcode'] ?? null,
                'province'    => $r['province'] ?? null,
                'city'        => $r['city'] ?? null,
                'district'    => $r['district'] ?? null,
                'subdistrict' => $r['area'] ?? null,
                'raw'         => $r,
            ];
        }

        return ['success' => true, 'areas' => $areas, 'error' => null];
    }

    /**
     * Terbitkan resi.
     *
     * Payload memakai kunci internal yang sama dengan provider lain (origin_… dan
     * destination_…), diterjemahkan di sini supaya pemanggil tidak perlu tahu bentuk
     * API Jubelio.
     */
    public function createOrder(array $payload): array
    {
        if (!$this->isReady()) {
            return ['success' => false, 'order_id' => null, 'tracking_id' => null, 'raw' => [], 'error' => 'Jubelio Shipment belum dikonfigurasi.'];
        }

        $courierId = (int) ($payload['courier_code'] ?? 0);
        $serviceId = (int) ($payload['service_code'] ?? 0);
        if ($courierId <= 0 || $serviceId <= 0) {
            return ['success' => false, 'order_id' => null, 'tracking_id' => null, 'raw' => [], 'error' => 'courier_id / courier_service_id Jubelio tidak valid. Pilih ulang kurir lewat Cek Ongkir.'];
        }

        $body = [
            'ref_no'         => $payload['reference'] ?? '[auto]',
            'courier_id'     => $courierId,
            'courier_service_id' => $serviceId,
            'origin'         => array_filter([
                'name'    => $payload['origin_contact_name'] ?? null,
                'email'   => $payload['origin_email'] ?? null,
                'phone'   => $payload['origin_contact_phone'] ?? null,
                'address' => $payload['origin_address'] ?? null,
                'area_id' => $payload['origin_area_id'] ?? null,
                'coordinate' => $this->coordinate($payload['origin_latitude'] ?? null, $payload['origin_longitude'] ?? null),
                'zipcode' => $payload['origin_postal_code'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
            'destination'    => array_filter([
                'name'    => $payload['destination_contact_name'] ?? null,
                'email'   => $payload['destination_email'] ?? null,
                'phone'   => $payload['destination_contact_phone'] ?? null,
                'address' => $payload['destination_address'] ?? null,
                'area_id' => $payload['destination_area_id'] ?? null,
                'coordinate' => $this->coordinate($payload['destination_latitude'] ?? null, $payload['destination_longitude'] ?? null),
                'zipcode' => $payload['destination_postal_code'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
            'items'          => $this->mapItems($payload['items'] ?? [], true),
        ];

        if (!empty($payload['shipping_insurance'])) {
            $body['shipping_insurance'] = (float) $payload['shipping_insurance'];
        }
        if (array_key_exists('is_cod', $payload)) {
            $body['is_cod'] = (bool) $payload['is_cod'];
        }

        $pkg = array_filter([
            'length' => (float) ($payload['package_length'] ?? 0) ?: null,
            'width'  => (float) ($payload['package_width'] ?? 0) ?: null,
            'height' => (float) ($payload['package_height'] ?? 0) ?: null,
        ]);
        if ($pkg) {
            $body['package_detail'] = $pkg;
        }

        $res = $this->call('POST', self::EP_CREATE, $body);
        if (!$res['ok']) {
            return ['success' => false, 'order_id' => null, 'tracking_id' => null, 'raw' => (array) $res['data'], 'error' => $res['error']];
        }

        $data = is_array($res['data']) ? ($res['data']['data'] ?? $res['data']) : [];
        $awb  = $data['awb'] ?? null;

        if (!$awb) {
            return ['success' => false, 'order_id' => null, 'tracking_id' => null, 'raw' => (array) $data, 'error' => 'Respons Jubelio tidak memuat nomor resi.'];
        }

        return [
            'success'     => true,
            // shipment_id dipakai sebagai order id internal; resi (awb) untuk tracking.
            'order_id'    => isset($data['shipment_id']) ? (string) $data['shipment_id'] : null,
            'tracking_id' => (string) $awb,
            'raw'         => (array) $data,
            'error'       => null,
        ];
    }

    /** $orderId di sini adalah nomor RESI (awb) — endpoint detail Jubelio memakai awb. */
    public function track(string $orderId): array
    {
        if (!$this->isReady()) {
            return ['success' => false, 'status' => null, 'history' => [], 'raw' => [], 'error' => 'Jubelio Shipment belum dikonfigurasi.'];
        }

        $res = $this->call('GET', self::EP_AWB . rawurlencode($orderId));
        if (!$res['ok']) {
            return ['success' => false, 'status' => null, 'history' => [], 'raw' => (array) $res['data'], 'error' => $res['error']];
        }

        $data = is_array($res['data']) ? ($res['data']['data'] ?? $res['data']) : [];

        return [
            'success'  => true,
            'status'   => $data['latest_status'] ?? null,
            'waybill'  => $data['awb'] ?? null,
            'history'  => $data['tracking'] ?? [],
            'raw'      => (array) $data,
            'error'    => null,
        ];
    }

    /** $orderId = nomor resi. Alasan pembatalan wajib menurut kontrak. */
    public function cancel(string $orderId, string $reason = 'Barang belum siap'): array
    {
        if (!$this->isReady()) {
            return ['success' => false, 'error' => 'Jubelio Shipment belum dikonfigurasi.'];
        }

        $res = $this->call('POST', self::EP_CANCEL, [
            'cancel_reason' => $reason,
            'awb_code'      => $orderId,
        ]);

        if (!$res['ok']) {
            // Dua penolakan yang wajar & perlu dibaca operator apa adanya:
            // kurir tidak mengizinkan pembatalan, atau jadwal pickup terlalu dekat.
            return ['success' => false, 'raw' => (array) $res['data'], 'error' => $res['error']];
        }

        $data = is_array($res['data']) ? ($res['data']['data'] ?? $res['data']) : [];

        return ['success' => true, 'raw' => (array) $data, 'error' => null];
    }

    /* ===================================================================
       Helper
       =================================================================== */

    /** Status internal untuk `latest_status` Jubelio (null bila tak dikenal). */
    public static function internalStatus(?string $jubelioStatus): ?string
    {
        return self::STATUS_MAP[strtoupper((string) $jubelioStatus)] ?? null;
    }

    /**
     * Tanda tangan webhook Jubelio Shipment.
     *
     * Rumusnya tidak lazim — secret dipakai DUA KALI: sebagai kunci HMAC sekaligus
     * disambung ke akhir payload (lihat contoh Node.js di kontrak v1.8). Salah satu
     * saja terlewat, semua webhook akan ditolak.
     */
    public function webhookSignature(string $rawBody): string
    {
        $secret = $this->webhookSecret();

        return hash_hmac('sha256', $rawBody . $secret, $secret);
    }

    public function verifyWebhook(string $rawBody, ?string $signature): bool
    {
        if ($this->webhookSecret() === '' || !$signature) {
            return false;
        }

        return hash_equals($this->webhookSignature($rawBody), $signature);
    }

    private function categoryIdByName(string $name): int
    {
        $flip = array_flip(array_map('strtoupper', self::DEFAULT_CATEGORIES));

        return (int) ($flip[strtoupper($name)] ?? 0);
    }

    /** Format koordinat Jubelio: "(lat,lng)". Null bila salah satu kosong. */
    private function coordinate($lat, $lng): ?string
    {
        if ($lat === null || $lng === null || $lat === '' || $lng === '') {
            return null;
        }

        return '(' . (float) $lat . ',' . (float) $lng . ')';
    }

    private function addressPoint(?string $areaId, $zipcode, $lat = null, $lng = null): array
    {
        return array_filter([
            'area_id'    => $areaId ?: null,
            'zipcode'    => $zipcode !== null && $zipcode !== '' ? (string) $zipcode : null,
            'coordinate' => $this->coordinate($lat, $lng),
        ], fn ($v) => $v !== null);
    }

    /**
     * Item internal → item Jubelio. Berat dalam GRAM (sama dengan internal).
     * $withIdentity=true menambahkan kode & nama produk (wajib saat terbit resi).
     */
    private function mapItems(array $items, bool $withIdentity = false): array
    {
        $out = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $row = [
                'quantity' => max(1, (int) ($it['quantity'] ?? 1)),
                'weight'   => max(1, (int) ($it['weight'] ?? 0)),
            ];
            // Ketiga dimensi WAJIB ADA di tiap item — skema Jubelio menolak dengan
            // VAL_ERR di /items/0 kalau salah satunya hilang. Nilai 0 diterima (diuji
            // langsung ke API produksi), jadi produk yang belum diukur tetap bisa
            // dicek ongkirnya; volumetriknya ditanggung package_detail bila diisi.
            foreach (['length', 'width', 'height'] as $dim) {
                $row[$dim] = (float) ($it[$dim] ?? 0);
            }
            if ($withIdentity) {
                $row['item_code'] = (string) ($it['description'] ?? ($it['sku'] ?? '-'));
                $row['item_name'] = mb_substr((string) ($it['name'] ?? 'Barang'), 0, 100);
                $row['category']  = (string) ($it['category'] ?? '-');
                $row['value']     = (float) ($it['value'] ?? 0);
            }
            $out[] = $row;
        }

        return $out;
    }

    /** Berat total paket (gram). Minimal 1000 g — Jubelio mewajibkan `weight` terisi. */
    private function totalWeight(array $items): int
    {
        $total = 0;
        foreach ($items as $it) {
            $total += (int) ($it['weight'] ?? 0) * max(1, (int) ($it['quantity'] ?? 1));
        }

        return max(1000, $total);
    }

    /** "2-3 hari" dari eta_from/eta_to; "-" bila tidak tersedia. */
    private function etaText(?string $from, ?string $to): string
    {
        if (!$from && !$to) {
            return '-';
        }

        try {
            $now  = now();
            $days = [];
            foreach ([$from, $to] as $t) {
                if ($t) {
                    $days[] = max(0, (int) ceil($now->diffInHours(\Illuminate\Support\Carbon::parse($t), false) / 24));
                }
            }
            $days = array_values(array_unique(array_filter($days, fn ($d) => $d > 0)));

            if (count($days) >= 2) {
                return min($days) . '-' . max($days) . ' hari';
            }
            if (count($days) === 1) {
                return $days[0] . ' hari';
            }
        } catch (\Throwable $e) {
            // format tanggal tak terduga → jatuh ke '-'
        }

        return '-';
    }
}
