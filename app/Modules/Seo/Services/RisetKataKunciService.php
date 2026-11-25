<?php

namespace App\Modules\Seo\Services;

use App\Modules\Assistant\Services\ClaudeClient;
use App\Modules\CRM\Models\CrmAgentRun;
use App\Modules\Seo\Models\SeoJenis;
use App\Modules\Seo\Models\SeoKataKunci;
use Illuminate\Support\Facades\Log;

/**
 * Riset kata kunci per jenis produk: Claude (Sonnet 5.5) + pencarian web melihat istilah yang
 * benar-benar dipakai calon pembeli di Google & marketplace, lalu menyusun daftar kata kunci
 * berbahasa Indonesia beserta niat & taksiran popularitasnya. Hasil masuk bank kata kunci;
 * kata yang sudah ada tidak ditimpa (manual/GSC tetap utuh), hanya jenisnya dilengkapi.
 *
 * Taksiran popularitas = penilaian AI dari bukti pencarian, BUKAN volume pencarian. Angka nyata
 * datang dari Search Console (SearchConsoleService).
 */
class RisetKataKunciService
{
    private const BATAS_PAUSE = 4;

    public function __construct(private ClaudeClient $claude) {}

    /** @return array{baru: int, ada: int, biaya_rp: int, cari: int} */
    public function riset(SeoJenis $jenis): array
    {
        $model = (string) config('services.anthropic.model_seo', 'claude-sonnet-5-5');
        $payload = [
            'model'         => $model,
            'max_tokens'    => 16000,
            'system'        => $this->system(),
            'output_config' => ['effort' => 'medium'],
            'fallbacks'     => 'default',
            'tools'         => [[
                'type' => 'web_search_20260209', 'name' => 'web_search', 'max_uses' => 6,
                'user_location' => ['type' => 'approximate', 'country' => 'ID', 'city' => 'Semarang', 'timezone' => 'Asia/Jakarta'],
            ]],
            'messages'      => [['role' => 'user', 'content' => $this->tugas($jenis)]],
        ];

        $masuk = $cache = $keluar = $cari = 0;
        $teks = '';
        for ($i = 0; $i < self::BATAS_PAUSE; $i++) {
            $r = $this->claude->messages($payload, 300, ['server-side-fallback-2026-07-01']);
            if (isset($r['_error'])) throw new \RuntimeException('Claude: ' . $r['_error']);

            $u = $r['usage'] ?? [];
            $masuk  += (int) ($u['input_tokens'] ?? 0) + (int) ($u['cache_creation_input_tokens'] ?? 0);
            $cache  += (int) ($u['cache_read_input_tokens'] ?? 0);
            $keluar += (int) ($u['output_tokens'] ?? 0);
            $cari   += (int) data_get($u, 'server_tool_use.web_search_requests', 0);

            if (($r['stop_reason'] ?? '') === 'refusal') throw new \RuntimeException('Claude menolak permintaan riset ini.');
            if (($r['stop_reason'] ?? '') === 'pause_turn') {
                // Pencarian web masih berjalan di server: lanjutkan giliran yang sama.
                $payload['messages'][] = ['role' => 'assistant', 'content' => $r['content'] ?? []];
                continue;
            }
            $teks = collect($r['content'] ?? [])->where('type', 'text')->pluck('text')->implode("\n");
            break;
        }

        $daftar = $this->urai($teks);
        if (!$daftar) {
            Log::warning('Riset kata kunci: jawaban tak terbaca', ['jenis' => $jenis->nama, 'teks' => mb_substr($teks, 0, 2000)]);
            throw new \RuntimeException('Jawaban AI tidak berisi daftar kata kunci yang bisa dibaca.');
        }

        $baru = $ada = 0;
        foreach ($daftar as $k) {
            $kata = SeoKataKunci::baku((string) ($k['kata'] ?? ''));
            if ($kata === '' || mb_strlen($kata) > 150) continue;
            $row = SeoKataKunci::where('kata', $kata)->first();
            if ($row) {
                $ada++;
                $row->fill(array_filter([
                    'seo_jenis_id' => $row->seo_jenis_id ?: $jenis->id,
                    'jenis_oleh'   => $row->seo_jenis_id ? null : 'riset',
                    'niat'         => $row->niat ?: $this->pilih($k['niat'] ?? null, array_keys(SeoKataKunci::NIAT)),
                    'perkiraan'    => $this->pilih($k['perkiraan'] ?? null, ['tinggi', 'sedang', 'rendah']) ?: $row->perkiraan,
                ]))->save();
                continue;
            }
            SeoKataKunci::create([
                'seo_jenis_id' => $jenis->id, 'jenis_oleh' => 'riset', 'kata' => $kata, 'sumber' => 'riset',
                'niat'         => $this->pilih($k['niat'] ?? null, array_keys(SeoKataKunci::NIAT)),
                'perkiraan'    => $this->pilih($k['perkiraan'] ?? null, ['tinggi', 'sedang', 'rendah']),
                'catatan'      => isset($k['alasan']) ? mb_substr((string) $k['alasan'], 0, 255) : null,
            ]);
            $baru++;
        }

        $biaya = (int) round(CrmAgentRun::hitungBiaya($model, $masuk, $cache, $keluar)
            + $cari * 0.01 * (float) config('crm.agen.kurs_usd', 16400));

        $jenis->update([
            'riset_diminta_at' => null,
            'riset_at'         => now(),
            'riset_ringkasan'  => "$baru kata baru, $ada sudah ada · $cari pencarian web",
            'riset_biaya_rp'   => $biaya,
        ]);

        return ['baru' => $baru, 'ada' => $ada, 'biaya_rp' => $biaya, 'cari' => $cari];
    }

    private function system(): string
    {
        return <<<TXT
Kamu peneliti SEO untuk Noud Acrylic, pembuat produk akrilik di Semarang yang menjual lewat noudakrilik.com dan Shopee ke seluruh Indonesia, banyak melayani pesanan instansi (sekolah, kantor, rumah sakit, pemerintahan) dan custom.

Tugasmu: menemukan kata kunci berbahasa Indonesia yang BENAR-BENAR diketik calon pembeli di Google untuk satu jenis produk. Pakai web_search untuk melihat hasil pencarian, judul toko/pesaing, saran "orang juga menelusuri", dan istilah di marketplace. Jangan mengarang istilah yang tidak kamu temukan buktinya.

Aturan daftar:
- 20–40 kata kunci, huruf kecil, bahasa sehari-hari pembeli (termasuk ejaan "acrylic"/"akrilik" bila keduanya dipakai).
- Campurkan: nama produk umum, variasi ukuran/bahan/fungsi, pemakaian (kantor, sekolah, nikahan…), niat beli ("harga", "jual", "pesan", "custom"), dan beberapa lokal (semarang/jawa tengah) bila relevan.
- Jangan masukkan nama merek/toko pesaing, dan jangan istilah yang bukan produk akrilik.
- niat: "beli" (mencari barang jadi/harga), "custom" (pesan sesuai desain/ukuran/logo), "lokal" (menyebut kota/daerah), "info" (cara, ide, contoh).
- perkiraan: "tinggi" / "sedang" / "rendah" menurut seberapa sering istilah itu muncul di bukti pencarianmu (bukan angka volume).

Jawaban akhir HANYA satu blok JSON:
{"kata_kunci":[{"kata":"...","niat":"beli|custom|lokal|info","perkiraan":"tinggi|sedang|rendah","alasan":"bukti singkat"}]}
TXT;
    }

    private function tugas(SeoJenis $jenis): string
    {
        $sudah = $jenis->kataKunci()->orderByDesc('gsc_tayang')->limit(40)->pluck('kata')->implode(', ');

        return "Jenis produk: {$jenis->nama}"
            . ($jenis->petunjuk ? "\nKeterangan: {$jenis->petunjuk}" : '')
            . ($sudah ? "\nSudah ada di bank (tak perlu diulang, cari yang lain): $sudah" : '');
    }

    /** Ambil blok JSON terakhir dari jawaban. */
    private function urai(string $teks): array
    {
        if (preg_match_all('/```(?:json)?\s*(\{.*?\})\s*```/s', $teks, $m) && $m[1]) {
            $json = json_decode(end($m[1]), true);
        } else {
            $a = strpos($teks, '{');
            $b = strrpos($teks, '}');
            $json = ($a !== false && $b > $a) ? json_decode(substr($teks, $a, $b - $a + 1), true) : null;
        }
        return is_array($json['kata_kunci'] ?? null) ? $json['kata_kunci'] : [];
    }

    private function pilih($nilai, array $boleh): ?string
    {
        $nilai = is_string($nilai) ? mb_strtolower(trim($nilai)) : null;
        return in_array($nilai, $boleh, true) ? $nilai : null;
    }
}
