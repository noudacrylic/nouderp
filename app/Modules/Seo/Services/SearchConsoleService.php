<?php

namespace App\Modules\Seo\Services;

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
        $k = $this->kredensial();
        return $k && !empty($k['client_email']) && !empty($k['private_key']);
    }

    public function emailAkun(): ?string
    {
        return $this->kredensial()['client_email'] ?? null;
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
                        $row->fill(['sumber' => 'gsc', 'seo_jenis_id' => $this->cocokkanJenis($kata, $jenis)]);
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
            $k = $this->kredensial();
            if (!$k) throw new \RuntimeException('Kunci service account Search Console belum dipasang.');

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
