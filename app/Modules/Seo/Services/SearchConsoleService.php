<?php

namespace App\Modules\Seo\Services;

use App\Modules\Seo\Models\SeoGoogleAkun;
use App\Modules\Seo\Models\SeoJenis;
use App\Modules\Seo\Models\SeoKataKunci;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Google Search Console → bank kata kunci: kata pencarian yang benar-benar menampilkan /
 * mendatangkan pengunjung ke noudakrilik.com (28 hari, data GSC tertinggal ±2 hari).
 *
 * Akses lewat service account (berkas JSON kunci; emailnya ditambahkan sebagai pengguna di
 * properti GSC). JWT ditandatangani sendiri dengan openssl — tanpa pustaka Google.
 */
class SearchConsoleService
{
    public const KUNCI_STATUS = 'seo.gsc.terakhir';

    /** Kata pendek tambahan per jenis untuk mengelompokkan kata GSC (selain nama jenisnya). */
    private const ALIAS = [
        'Box Mahar'         => ['mahar', 'kotak mahar'],
        'Kotak Saran'       => ['kotak saran', 'kotak aspirasi', 'kotak pengaduan'],
        'Tempat Brosur'     => ['brosur', 'rak brosur'],
        'Box Charger'       => ['charger', 'charging station', 'tempat charger'],
        'Rak Pulpen'        => ['pulpen', 'tempat pulpen', 'tempat pensil'],
        'Tempat Kartu Nama' => ['kartu nama', 'name card'],
        'Nama Meja'         => ['nama meja', 'papan nama meja', 'name plate'],
        'Kotak Tisu'        => ['tisu', 'tissue'],
        'Frame Poster'      => ['frame poster', 'bingkai poster'],
        'Frame Foto'        => ['frame foto', 'bingkai foto', 'pigura'],
        'Tent Card/Holder'  => ['tent card', 'table tent', 'akrilik holder'],
        'Plakat Akrilik'    => ['plakat'],
        'Akrilik Sign / Papan Nama' => ['akrilik sign', 'signage', 'papan nama', 'neon box'],
        'Display Akrilik'   => ['display', 'standee', 'etalase'],
        'Box Akrilik Custom'=> ['box akrilik', 'kotak akrilik'],
        'Trophy Akrilik'    => ['trophy', 'trofi', 'piala'],
        'Gantungan Kunci Akrilik' => ['gantungan kunci', 'keychain'],
    ];

    public function tersambung(): bool
    {
        return $this->pakaiServiceAccount() || filled($this->akun()?->refresh_token);
    }

    public function emailAkun(): ?string
    {
        return $this->pakaiServiceAccount() ? ($this->kredensial()['client_email'] ?? null) : $this->akun()?->email;
    }

    private function pakaiServiceAccount(): bool
    {
        $k = $this->kredensial();
        return $k && !empty($k['client_email']) && !empty($k['private_key']);
    }

    private function akun(): ?SeoGoogleAkun
    {
        return SeoGoogleAkun::query()->first();
    }

    // ───────────── Login Google (OAuth) ─────────────

    private const SCOPE = 'openid email https://www.googleapis.com/auth/webmasters.readonly';

    /** OAuth client (ID + secret) sudah diisi di halaman? */
    public function klienSiap(): bool
    {
        $a = $this->akun();
        return $a && filled($a->client_id) && filled($a->client_secret);
    }

    /** Halaman persetujuan Google. access_type=offline + prompt=consent → selalu dapat refresh_token. */
    public function urlSambung(string $state, string $redirect): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $this->akun()?->client_id, 'redirect_uri' => $redirect, 'response_type' => 'code',
            'scope' => self::SCOPE, 'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /** Tukar kode dari Google → refresh token; kembalikan email akun yang tersambung. */
    public function tukarKode(string $kode, string $redirect, ?int $userId = null): string
    {
        $a = $this->akun() ?? throw new \RuntimeException('OAuth client belum diisi.');
        $resp = Http::asForm()->timeout(30)->post('https://oauth2.googleapis.com/token', [
            'code' => $kode, 'client_id' => $a->client_id, 'client_secret' => $a->client_secret,
            'redirect_uri' => $redirect, 'grant_type' => 'authorization_code',
        ]);
        if (!$resp->successful() || !$resp->json('refresh_token')) {
            throw new \RuntimeException('Google menolak sambungan: ' . ($resp->json('error_description') ?? $resp->json('error') ?? 'refresh token tidak diberikan'));
        }

        // id_token berisi email akun (JWT; cukup dibaca, sudah datang langsung dari Google lewat HTTPS).
        $bagian = explode('.', (string) $resp->json('id_token'));
        $klaim = json_decode((string) base64_decode(strtr($bagian[1] ?? '', '-_', '+/')), true) ?: [];

        $a->update(['refresh_token' => $resp->json('refresh_token'), 'email' => $klaim['email'] ?? null,
            'tersambung_at' => now(), 'user_id' => $userId]);
        Cache::forget('seo.gsc.token');

        return (string) ($klaim['email'] ?? 'akun Google');
    }

    public function putus(): void
    {
        $a = $this->akun();
        if ($a?->refresh_token) {
            Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/revoke', ['token' => $a->refresh_token]);
        }
        $a?->update(['refresh_token' => null, 'email' => null, 'tersambung_at' => null]);
        Cache::forget('seo.gsc.token');
    }

    /** Properti GSC yang bisa dibaca akun tersambung — untuk memastikan situs kita ada. */
    public function daftarSitus(): array
    {
        $resp = Http::withToken($this->token())->timeout(30)->get('https://www.googleapis.com/webmasters/v3/sites');
        if (!$resp->successful()) throw new \RuntimeException('Search Console: ' . ($resp->json('error.message') ?? 'HTTP ' . $resp->status()));
        return collect($resp->json('siteEntry') ?? [])->pluck('siteUrl')->all();
    }

    /** @return array{waktu?: string, baris?: int, baru?: int, galat?: string}|null */
    public function status(): ?array
    {
        return Cache::get(self::KUNCI_STATUS);
    }

    /**
     * Tarik kata pencarian 28 hari terakhir ke bank kata kunci. Metrik GSC lama dikosongkan dulu,
     * jadi kata yang tak lagi muncul tidak membawa angka basi.
     *
     * @return array{baris: int, baru: int}
     */
    public function tarik(): array
    {
        try {
            $sampai = now()->subDays(2)->toDateString();
            $rows = $this->kueri(now()->subDays(29)->toDateString(), $sampai);

            $baru = 0;
            DB::transaction(function () use ($rows, $sampai, &$baru) {
                SeoKataKunci::whereNotNull('gsc_tayang')->update(['gsc_tayang' => null, 'gsc_klik' => null, 'gsc_posisi' => null, 'gsc_sampai' => null]);
                $jenis = SeoJenis::where('aktif', true)->get();

                foreach ($rows as $r) {
                    $kata = SeoKataKunci::baku((string) ($r['keys'][0] ?? ''));
                    if ($kata === '' || mb_strlen($kata) > 150) continue;
                    $row = SeoKataKunci::firstOrNew(['kata' => $kata]);
                    if (!$row->exists) {
                        $jid = $this->cocokkanJenis($kata, $jenis);
                        $row->fill(['sumber' => 'gsc', 'seo_jenis_id' => $jid, 'jenis_oleh' => $jid ? 'alias' : null]);
                        $baru++;
                    }
                    $row->fill([
                        'gsc_tayang' => (int) ($r['impressions'] ?? 0),
                        'gsc_klik'   => (int) ($r['clicks'] ?? 0),
                        'gsc_posisi' => round((float) ($r['position'] ?? 0), 2),
                        'gsc_sampai' => $sampai,
                    ])->save();
                }
            });

            Cache::forever(self::KUNCI_STATUS, ['waktu' => now()->toDateTimeString(), 'baris' => count($rows), 'baru' => $baru]);
            return ['baris' => count($rows), 'baru' => $baru];
        } catch (\Throwable $e) {
            Cache::forever(self::KUNCI_STATUS, ['waktu' => now()->toDateTimeString(), 'galat' => mb_substr($e->getMessage(), 0, 300)]);
            throw $e;
        }
    }

    /** Jenis produk untuk kata GSC baru: nama jenis / alias terpanjang yang termuat. */
    public function cocokkanJenis(string $kata, $jenis): ?int
    {
        $terbaik = null;
        $panjang = 0;
        foreach ($jenis as $j) {
            $kunci = array_merge([mb_strtolower($j->nama)], self::ALIAS[$j->nama] ?? []);
            foreach ($kunci as $k) {
                if (mb_strlen($k) > $panjang && preg_match('/\b' . preg_quote($k, '/') . '\b/u', $kata)) {
                    $terbaik = $j->id;
                    $panjang = mb_strlen($k);
                }
            }
        }
        return $terbaik;
    }

    // ───────────── API Google ─────────────

    /**
     * Search Analytics 28 hari (tertinggal 2 hari) dengan dimensi bebas, mis. ['page'] atau
     * ['query'] + filter halaman.
     */
    public function analitik(array $dimensi, ?string $halaman = null, int $batas = 1000): array
    {
        $situs = rawurlencode((string) config('services.gsc.situs'));
        $resp = Http::withToken($this->token())->timeout(60)
            ->post("https://www.googleapis.com/webmasters/v3/sites/$situs/searchAnalytics/query", array_filter([
                'startDate' => now()->subDays(29)->toDateString(), 'endDate' => now()->subDays(2)->toDateString(),
                'dimensions' => $dimensi, 'rowLimit' => $batas,
                'dimensionFilterGroups' => $halaman ? [['filters' => [['dimension' => 'page', 'operator' => 'equals', 'expression' => $halaman]]]] : null,
            ]));
        if (!$resp->successful()) {
            throw new \RuntimeException('Search Console: ' . ($resp->json('error.message') ?? 'HTTP ' . $resp->status()));
        }
        return $resp->json('rows') ?? [];
    }

    private function kueri(string $dari, string $sampai): array
    {
        $situs = rawurlencode((string) config('services.gsc.situs'));
        $resp = Http::withToken($this->token())->timeout(60)
            ->post("https://www.googleapis.com/webmasters/v3/sites/$situs/searchAnalytics/query", [
                'startDate' => $dari, 'endDate' => $sampai, 'dimensions' => ['query'], 'rowLimit' => 5000,
            ]);
        if (!$resp->successful()) {
            throw new \RuntimeException('Search Console: ' . ($resp->json('error.message') ?? 'HTTP ' . $resp->status()));
        }
        return $resp->json('rows') ?? [];
    }

    private function token(): string
    {
        return Cache::remember('seo.gsc.token', 3000, function () {
            // Login Google (OAuth): refresh token → access token.
            if (!$this->pakaiServiceAccount()) {
                $a = $this->akun();
                if (!$a?->refresh_token) throw new \RuntimeException('Search Console belum disambungkan.');
                $resp = Http::asForm()->timeout(30)->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'refresh_token', 'refresh_token' => $a->refresh_token,
                    'client_id' => $a->client_id, 'client_secret' => $a->client_secret,
                ]);
                if (!$resp->successful()) {
                    throw new \RuntimeException('Sambungan Google kedaluwarsa/dicabut — sambungkan ulang (' . ($resp->json('error') ?? 'HTTP ' . $resp->status()) . ').');
                }
                return (string) $resp->json('access_token');
            }

            $k = $this->kredensial();

            $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $kepala = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $isi = $b64(json_encode([
                'iss' => $k['client_email'], 'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
                'aud' => 'https://oauth2.googleapis.com/token', 'iat' => time(), 'exp' => time() + 3600,
            ]));
            if (!openssl_sign("$kepala.$isi", $ttd, $k['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new \RuntimeException('Kunci service account tidak bisa dipakai menandatangani.');
            }

            $resp = Http::asForm()->timeout(30)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => "$kepala.$isi." . $b64($ttd),
            ]);
            if (!$resp->successful()) {
                throw new \RuntimeException('Login Google gagal: ' . ($resp->json('error_description') ?? $resp->json('error') ?? 'HTTP ' . $resp->status()));
            }
            return (string) $resp->json('access_token');
        });
    }

    private function kredensial(): ?array
    {
        $path = (string) config('services.gsc.kredensial');
        if ($path === '' || !is_readable($path)) return null;
        $json = json_decode((string) file_get_contents($path), true);
        return is_array($json) ? $json : null;
    }
}
