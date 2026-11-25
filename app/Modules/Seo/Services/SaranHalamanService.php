<?php

namespace App\Modules\Seo\Services;

use App\Modules\Assistant\Services\ClaudeClient;
use App\Modules\Seo\Models\SeoSaranHalaman;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Laporan "tampil tapi tak diklik": halaman yang sering tampil di Google dengan posisi lumayan
 * (≤ POSISI_MAKS) tapi CTR rendah. Judul/deskripsi yang sedang tayang dibaca langsung dari
 * halamannya, lalu Claude Sonnet 5.5 mengusulkan judul & deskripsi baru yang memuat kata yang
 * benar-benar dicari orang di halaman itu.
 */
class SaranHalamanService
{
    public const KUNCI_ANTREAN = 'seo.saran.diminta';
    private const TAYANG_MIN = 30;
    private const POSISI_MAKS = 15.0;
    private const CTR_MAKS = 3.0;   // persen
    private const BATAS_HALAMAN = 15;

    public function __construct(private SearchConsoleService $gsc, private ClaudeClient $claude) {}

    /** @return array{halaman: int, diusulkan: int} */
    public function susun(): array
    {
        $kandidat = collect($this->gsc->analitik(['page'], null, 500))
            ->filter(fn ($r) => ($r['impressions'] ?? 0) >= self::TAYANG_MIN && ($r['position'] ?? 99) <= self::POSISI_MAKS
                && ($r['ctr'] ?? 0) * 100 < self::CTR_MAKS)
            ->sortByDesc('impressions')->take(self::BATAS_HALAMAN)->values();

        $halaman = [];
        foreach ($kandidat as $r) {
            $url = (string) $r['keys'][0];
            $kueri = collect($this->gsc->analitik(['query'], $url, 8))->map(fn ($q) => [
                'kata' => (string) $q['keys'][0], 'tayang' => (int) $q['impressions'], 'klik' => (int) $q['clicks'], 'posisi' => round((float) $q['position'], 1),
            ])->values()->all();
            [$judul, $deskripsi] = $this->bacaMeta($url);
            [$jenis, $produkId] = $this->kenali($url);

            $row = SeoSaranHalaman::firstOrNew(['url' => $url]);
            $row->fill([
                'jenis' => $jenis, 'store_product_id' => $produkId, 'tayang' => (int) $r['impressions'], 'klik' => (int) $r['clicks'],
                'posisi' => round((float) $r['position'], 2), 'kueri' => $kueri, 'judul_sekarang' => $judul, 'deskripsi_sekarang' => $deskripsi,
            ]);
            // Sudah dipakai & judul yang tayang masih yang dipakai → tunggu hasilnya terlihat di GSC.
            // Diabaikan → tetap diabaikan. Selain itu usulkan (lagi).
            $tunggu = $row->status === 'dipakai' && $row->judul_usulan === $judul;
            if (!$tunggu && $row->status !== 'diabaikan') $row->status = 'baru';
            $row->save();
            if ($row->status === 'baru') $halaman[] = $row;
        }

        $usul = $halaman ? $this->usulkan($halaman) : [];
        $n = 0;
        foreach ($halaman as $row) {
            if (!isset($usul[$row->url])) continue;
            $u = $usul[$row->url];
            $row->update([
                'judul_usulan' => mb_substr(trim((string) ($u['judul'] ?? '')), 0, 300) ?: null,
                'deskripsi_usulan' => mb_substr(trim((string) ($u['deskripsi'] ?? '')), 0, 500) ?: null,
                'alasan' => mb_substr(trim((string) ($u['alasan'] ?? '')), 0, 500) ?: null,
            ]);
            $n++;
        }

        // Halaman yang tak lagi bermasalah keluar dari laporan (kecuali yang sudah dipakai — riwayat).
        SeoSaranHalaman::whereNotIn('url', $kandidat->map(fn ($r) => $r['keys'][0]))->where('status', '!=', 'dipakai')->delete();

        return ['halaman' => count($kandidat), 'diusulkan' => $n];
    }

    /** Pakai usulan untuk halaman produk: isi meta_title & meta_description Produk Store. */
    public function pakai(SeoSaranHalaman $s, string $judul, string $deskripsi): void
    {
        if (!$s->store_product_id) throw new \RuntimeException('Hanya halaman produk yang bisa dipakai langsung dari sini.');
        DB::table('store_products')->where('id', $s->store_product_id)->update([
            'meta_title' => mb_substr($judul, 0, 255), 'meta_description' => mb_substr($deskripsi, 0, 500), 'updated_at' => now(),
        ]);
        $s->update(['judul_usulan' => $judul, 'deskripsi_usulan' => $deskripsi, 'status' => 'dipakai', 'dipakai_at' => now()]);
    }

    /** Judul & deskripsi yang sedang tayang, dibaca dari HTML halamannya. */
    private function bacaMeta(string $url): array
    {
        try {
            $html = (string) Http::timeout(20)->withHeaders(['User-Agent' => 'NoudERP-SEO/1.0'])->get($url)->body();
        } catch (\Throwable) {
            return [null, null];
        }
        $judul = preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m) ? html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5) : null;
        $desk = preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)["\']/i', $html, $m)
            || preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']description["\']/i', $html, $m)
            ? html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5) : null;
        return [$judul ? mb_substr($judul, 0, 300) : null, $desk ? mb_substr($desk, 0, 500) : null];
    }

    /** @return array{0: string, 1: ?int} jenis halaman & id Produk Store */
    private function kenali(string $url): array
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($path === '') return ['beranda', null];
        if (preg_match('#^produk/([^/?]+)$#', $path, $m)) {
            return ['produk', DB::table('store_products')->where('slug', $m[1])->value('id')];
        }
        if (str_starts_with($path, 'kategori/')) return ['kategori', null];
        return ['lain', null];
    }

    /** @param SeoSaranHalaman[] $halaman @return array<string, array{judul?: string, deskripsi?: string, alasan?: string}> */
    private function usulkan(array $halaman): array
    {
        $isi = collect($halaman)->map(fn (SeoSaranHalaman $h) => [
            'url' => $h->url, 'jenis' => $h->jenis, 'tayang' => $h->tayang, 'klik' => $h->klik, 'posisi' => $h->posisi,
            'judul_sekarang' => $h->judul_sekarang, 'deskripsi_sekarang' => $h->deskripsi_sekarang,
            'kata_dicari' => array_map(fn ($q) => "{$q['kata']} ({$q['tayang']} tayang, posisi {$q['posisi']})", $h->kueri ?? []),
        ])->values()->all();

        $r = $this->claude->messages([
            'model'         => (string) config('services.anthropic.model_seo', 'claude-sonnet-5-5'),
            'max_tokens'    => 16000,
            'output_config' => ['effort' => 'medium'],
            'fallbacks'     => 'default',
            'system'        => <<<TXT
Kamu penulis SEO untuk Noud Acrylic (noudakrilik.com), pembuat produk akrilik di Semarang; kirim ke seluruh Indonesia, melayani pesanan satuan, partai, dan custom (logo/nama/ukuran) untuk instansi, kantor, sekolah, dan pernikahan.

Halaman-halaman berikut sering TAMPIL di Google dengan posisi lumayan tapi JARANG DIKLIK. Tulis judul & deskripsi baru (yang tampil di hasil Google) supaya orang yang mencari kata-kata itu mau mengklik.

Aturan:
- Judul maksimal 60 karakter, deskripsi 120–155 karakter, bahasa Indonesia yang wajar.
- Kata yang paling banyak dicari untuk halaman itu harus ada di judul (sedekat mungkin ke depan) dan di deskripsi.
- Beri alasan klik yang jujur: custom nama/logo, bisa satuan, kirim seluruh Indonesia, produksi sendiri di Semarang. Jangan menjanjikan harga/angka yang tidak diberikan.
- Judul diakhiri " | Noud Acrylic" bila masih muat.
- DILARANG: "gratis ongkir" (pakai "potongan ongkir" bila perlu), "grosir", "kerajinan", "grafir", "cetak logo langsung", dan kata-kata yang menjelekkan stiker.
- Jangan ubah maksud halaman: halaman produk tetap tentang produk itu.

Jawab HANYA JSON: {"usulan":[{"url":"...","judul":"...","deskripsi":"...","alasan":"singkat: kata yang dikejar & kenapa judul lama kurang"}]}
TXT,
            'messages'      => [['role' => 'user', 'content' => json_encode($isi, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]],
        ], 180, ['server-side-fallback-2026-07-01']);
        if (isset($r['_error'])) throw new \RuntimeException('Claude: ' . $r['_error']);

        $teks = collect($r['content'] ?? [])->where('type', 'text')->pluck('text')->implode("\n");
        $a = strpos($teks, '{');
        $b = strrpos($teks, '}');
        $json = ($a !== false && $b > $a) ? json_decode(substr($teks, $a, $b - $a + 1), true) : null;
        if (!is_array($json['usulan'] ?? null)) {
            Log::warning('Saran halaman: jawaban tak terbaca', ['teks' => mb_substr($teks, 0, 1000)]);
            return [];
        }
        return collect($json['usulan'])->filter(fn ($u) => is_array($u) && isset($u['url']))->keyBy('url')->all();
    }
}
