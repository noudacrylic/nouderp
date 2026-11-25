<?php

namespace App\Modules\Seo\Services;

use App\Modules\Assistant\Services\ClaudeClient;
use App\Modules\Seo\Models\SeoJenis;
use App\Modules\Seo\Models\SeoKataKunci;
use Illuminate\Support\Facades\Log;

/**
 * Kelompokkan kata kunci ke jenis produk dengan Claude Haiku: kata yang belum berjenis atau
 * hanya dikelompokkan alias (cocok-kata kasar, mis. "papan nama akrilik meja" → Papan Nama,
 * padahal Nama Meja). Kata yang jenisnya diubah orang (jenis_oleh = manual) tak disentuh.
 * Kata yang bukan produk kita → tetap tanpa jenis, tapi ditandai 'ai' supaya tak ditanya lagi.
 */
class KelompokKataKunciService
{
    private const PER_PANGGIL = 150;

    public function __construct(private ClaudeClient $claude) {}

    /** @return array{diperiksa: int, berubah: int} */
    public function kelompokkan(?int $batas = null): array
    {
        $kata = SeoKataKunci::where(fn ($w) => $w->where('jenis_oleh', 'alias')
                ->orWhere(fn ($x) => $x->whereNull('seo_jenis_id')->where(fn ($y) => $y->whereNull('jenis_oleh')->orWhere('jenis_oleh', '!=', 'ai'))))
            ->where(fn ($w) => $w->whereNull('jenis_oleh')->orWhere('jenis_oleh', '!=', 'manual'))
            ->orderByDesc('gsc_tayang')
            ->when($batas, fn ($q) => $q->limit($batas))
            ->get();
        if ($kata->isEmpty()) return ['diperiksa' => 0, 'berubah' => 0];

        $jenis = SeoJenis::where('aktif', true)->orderBy('urutan')->get();
        $peta = $jenis->mapWithKeys(fn ($j) => [mb_strtolower($j->nama) => $j->id]);
        $berubah = 0;

        foreach ($kata->chunk(self::PER_PANGGIL) as $potong) {
            $hasil = $this->tanya($jenis, $potong->pluck('kata')->all());
            foreach ($potong as $k) {
                if (!array_key_exists($k->kata, $hasil)) continue; // tak dijawab → coba lagi lain kali
                $id = $hasil[$k->kata] === null ? null : ($peta[mb_strtolower($hasil[$k->kata])] ?? null);
                if ($id !== $k->seo_jenis_id) $berubah++;
                $k->update(['seo_jenis_id' => $id, 'jenis_oleh' => 'ai']);
            }
        }

        return ['diperiksa' => $kata->count(), 'berubah' => $berubah];
    }

    /** @return array<string, string|null> kata => nama jenis (null = bukan produk kita) */
    private function tanya($jenis, array $kata): array
    {
        $daftarJenis = $jenis->map(fn ($j) => '- ' . $j->nama . ($j->petunjuk ? " ({$j->petunjuk})" : ''))->implode("\n");
        $r = $this->claude->messages([
            'model'         => (string) config('services.anthropic.model_seo_kelompok', 'claude-haiku-5-5'),
            'max_tokens'    => 16000,
            'output_config' => ['effort' => 'low'],
            'system'        => "Kamu mengelompokkan kata pencarian Google untuk toko produk akrilik Noud Acrylic (Semarang) ke jenis produknya.\n\n"
                . "Jenis produk:\n$daftarJenis\n\n"
                . "Aturan: pilih SATU jenis yang paling cocok dengan maksud pencari (contoh: \"papan nama akrilik meja\" = Nama Meja, \"akrilik meja\" untuk papan di meja = Nama Meja). "
                . "Kata umum tentang akrilik/toko akrilik tanpa produk tertentu → \"Umum / Toko Akrilik\". Kata yang bukan tentang produk akrilik kita (merek lain, bahan lain, topik lain) → null.\n"
                . "Jawab HANYA JSON: {\"hasil\":[{\"kata\":\"...\",\"jenis\":\"<nama jenis persis>\" atau null}]} untuk SETIAP kata, kata ditulis persis seperti diberikan.",
            'messages'      => [['role' => 'user', 'content' => "Kata pencarian:\n" . implode("\n", $kata)]],
        ], 120);
        if (isset($r['_error'])) throw new \RuntimeException('Claude: ' . $r['_error']);

        $teks = collect($r['content'] ?? [])->where('type', 'text')->pluck('text')->implode("\n");
        $a = strpos($teks, '{');
        $b = strrpos($teks, '}');
        $json = ($a !== false && $b > $a) ? json_decode(substr($teks, $a, $b - $a + 1), true) : null;
        if (!is_array($json['hasil'] ?? null)) {
            Log::warning('Kelompok kata kunci: jawaban tak terbaca', ['teks' => mb_substr($teks, 0, 1000)]);
            return [];
        }

        $boleh = array_flip($kata);
        $out = [];
        foreach ($json['hasil'] as $h) {
            $k = (string) ($h['kata'] ?? '');
            if (isset($boleh[$k])) $out[$k] = is_string($h['jenis'] ?? null) && $h['jenis'] !== '' ? $h['jenis'] : null;
        }
        return $out;
    }
}
