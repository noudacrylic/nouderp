<?php

namespace App\Modules\Marketplace\Shopee\Services;

use App\Modules\Marketplace\Shopee\Models\ShopeeApiLog;
use App\Modules\Marketplace\Shopee\Models\ShopeeSetting;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use Illuminate\Support\Facades\Http;

/**
 * Klien Shopee Open Platform API v2.
 *
 * Tanda tangan = HMAC-SHA256(partner_key) atas:
 *   - panggilan publik (auth)  : partner_id + path + timestamp
 *   - panggilan level toko     : partner_id + path + timestamp + access_token + shop_id
 * Token: access_token berlaku ±4 jam, refresh_token 30 hari dan BERGANTI tiap diperbarui —
 * selalu simpan pasangan baru. Panggilan level toko memperbarui sendiri bila access_token
 * tinggal < 5 menit. Tiap panggilan dicatat di shopee_api_logs (tanpa token/kunci).
 * Kunci app & lingkungan (test/live) dari ShopeeSetting (Pengaturan › Integrasi › Shopee).
 */
class ShopeeClient
{
    public const HOST = [
        'test' => 'https://partner.test-stable.shopeemobile.com',
        'live' => 'https://partner.shopeemobile.com',
    ];

    public const MASA_REFRESH_HARI = 30;

    private ?ShopeeSetting $setting = null;

    public function setting(): ShopeeSetting
    {
        return $this->setting ??= ShopeeSetting::singleton();
    }

    public function siap(): bool
    {
        return $this->setting()->siap();
    }

    public function env(): string
    {
        return $this->setting()->envAktif();
    }

    public function host(): string
    {
        return self::HOST[$this->env()];
    }

    private function partnerId(): int
    {
        return $this->setting()->partnerId();
    }

    public function tandaTangan(string $dasar): string
    {
        return hash_hmac('sha256', $dasar, $this->setting()->partnerKey());
    }

    /** Tautan halaman otorisasi toko di Shopee; setelah login Shopee kembali ke $redirect?code=&shop_id=. */
    public function urlOtorisasi(string $redirect): string
    {
        $path = '/api/v2/shop/auth_partner';
        $ts   = time();

        return $this->host() . $path . '?' . http_build_query([
            'partner_id' => $this->partnerId(),
            'timestamp'  => $ts,
            'sign'       => $this->tandaTangan($this->partnerId() . $path . $ts),
            'redirect'   => $redirect,
        ]);
    }

    /** Tukar kode otorisasi dengan token, simpan/perbarui toko. */
    public function tukarKode(string $code, int $shopId, ?int $userId = null): ShopeeShop
    {
        $data = $this->panggilPublik('/api/v2/auth/token/get', [
            'code' => $code, 'shop_id' => $shopId, 'partner_id' => $this->partnerId(),
        ], $shopId);

        $shop = ShopeeShop::firstOrNew(['shop_id' => $shopId, 'env' => $this->env()]);
        $this->simpanToken($shop, $data);
        $shop->fill(['terhubung_oleh' => $userId, 'terhubung_at' => now(), 'galat_terakhir' => null])->save();

        try {
            $info = $this->panggil($shop, 'GET', '/api/v2/shop/get_shop_info');
            $shop->update(['nama' => $info['shop_name'] ?? $shop->nama]);
        } catch (ShopeeException) {
            // Nama toko hanya pelengkap; koneksi tetap sah.
        }

        return $shop;
    }

    /** Perbarui access_token memakai refresh_token (refresh_token ikut berganti). */
    public function perbarui(ShopeeShop $shop): ShopeeShop
    {
        try {
            $data = $this->panggilPublik('/api/v2/auth/access_token/get', [
                'refresh_token' => $shop->refresh_token, 'shop_id' => $shop->shop_id, 'partner_id' => $this->partnerId(),
            ], $shop->shop_id);
        } catch (ShopeeException $e) {
            $shop->update(['galat_terakhir' => $e->getMessage()]);
            throw $e;
        }

        $this->simpanToken($shop, $data);
        $shop->galat_terakhir = null;
        $shop->save();

        return $shop;
    }

    /**
     * Panggilan level toko. GET → parameter di query; POST → body JSON.
     *
     * @return array isi 'response' (atau seluruh JSON bila tak ada)
     */
    public function panggil(ShopeeShop $shop, string $method, string $path, array $params = []): array
    {
        if ($shop->perluHubungkanUlang()) {
            throw new ShopeeException('token_kedaluwarsa', 'Izin toko sudah kedaluwarsa — hubungkan ulang toko di Pengaturan › Integrasi › Shopee.');
        }
        if (!$shop->access_expires_at || $shop->access_expires_at->lt(now()->addMinutes(5))) {
            $this->perbarui($shop);
        }

        $ts    = time();
        $query = [
            'partner_id'   => $this->partnerId(),
            'timestamp'    => $ts,
            'access_token' => $shop->access_token,
            'shop_id'      => $shop->shop_id,
            'sign'         => $this->tandaTangan($this->partnerId() . $path . $ts . $shop->access_token . $shop->shop_id),
        ];
        $method = strtoupper($method);
        $url    = $this->host() . $path . '?' . http_build_query($method === 'GET' ? $query + $params : $query);

        $json = $this->kirim($method, $url, $method === 'GET' ? null : $params, $path, $shop->shop_id);

        return $json['response'] ?? $json;
    }

    private function panggilPublik(string $path, array $body, ?int $shopId): array
    {
        if (!$this->siap()) {
            throw new ShopeeException('belum_dikonfigurasi', 'Partner ID / Partner Key ' . strtoupper($this->env()) . ' belum diisi di Pengaturan › Integrasi › Shopee.');
        }
        $ts  = time();
        $url = $this->host() . $path . '?' . http_build_query([
            'partner_id' => $this->partnerId(),
            'timestamp'  => $ts,
            'sign'       => $this->tandaTangan($this->partnerId() . $path . $ts),
        ]);

        return $this->kirim('POST', $url, $body, $path, $shopId);
    }

    /** Kirim, catat, dan lempar ShopeeException bila Shopee mengembalikan 'error'. */
    private function kirim(string $method, string $url, ?array $body, string $path, ?int $shopId): array
    {
        $mulai = microtime(true);
        $http  = null;
        $json  = [];
        try {
            $req  = Http::timeout(30)->acceptJson();
            $res  = $method === 'GET' ? $req->get($url) : $req->asJson()->post($url, $body ?? []);
            $http = $res->status();
            $json = $res->json() ?? [];
        } catch (\Throwable $e) {
            $json = ['error' => 'koneksi_gagal', 'message' => $e->getMessage()];
        }

        $error = ($json['error'] ?? '') !== '' ? (string) $json['error'] : (($http && $http >= 400) ? 'http_' . $http : null);
        ShopeeApiLog::create([
            'shop_id'     => $shopId,
            'method'      => $method,
            'path'        => $path,
            'http_status' => $http,
            'error'       => $error ? mb_substr($error, 0, 120) : null,
            'message'     => $error ? mb_substr((string) ($json['message'] ?? ''), 0, 1000) : null,
            'request_id'  => $json['request_id'] ?? null,
            'durasi_ms'   => (int) round((microtime(true) - $mulai) * 1000),
        ]);

        if ($error) {
            throw new ShopeeException($error, (string) ($json['message'] ?? $error), $json['request_id'] ?? null);
        }

        return $json;
    }

    private function simpanToken(ShopeeShop $shop, array $data): void
    {
        $shop->access_token       = $data['access_token'] ?? null;
        $shop->refresh_token      = $data['refresh_token'] ?? $shop->refresh_token;
        $shop->access_expires_at  = now()->addSeconds((int) ($data['expire_in'] ?? 14400));
        $shop->refresh_expires_at = now()->addDays(self::MASA_REFRESH_HARI);
    }
}
