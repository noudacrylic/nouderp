<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\ProductionStandardTime;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Keterampilan karyawan produksi (keputusan owner 24 Nov 2026): satu orang dianggap MENGUASAI
 * satu kelompok pekerjaan bila pernah mengerjakannya dengan waktu baik — minimal 3 OP ≥ 1 poin
 * dan minimal separuh SEMUA OP-nya di kelompok itu ≥ 1 poin (seluruh riwayat). Tidak hilang karena
 * lama tak dikerjakan, tapi bisa dicabut bila belakangan banyak lambat; rapor menampilkan kapan
 * terakhir dikerjakan. Dihitung dari OP yang DINILAI di Performa (riwayat
 * sejak waktu standar pertama berlaku), jadi tidak ada data baru yang perlu dicatat.
 */
class KeterampilanService
{
    public const MIN_OP_BAIK = 3;
    public const MIN_PORSI_BAIK = 0.5;

    /**
     * Kelompok per divisi: kunci => [label, pola regex SKU]. SKU tanpa kelompok (mis. AF = cuma
     * potong) tidak dihitung untuk keterampilan, tetap dinilai di Performa.
     */
    public const KELOMPOK = [
        2 => [
            'bungkus-kd' => ['label' => 'Membungkus Knockdown', 'pola' => ['/^TBKD-/', '/^TB-.*T1P/', '/^RP-/', '/^KN-/', '/^TS-/']],
            'rakit'      => ['label' => 'Merakit Produk',       'pola' => ['/^AM-/', '/^KS-/', '/^TO-/', '/^TB-/']],
            'rakit-bc'   => ['label' => 'Merakit BC',           'pola' => ['/^BC-/']],
            'tekuk'      => ['label' => 'Menekuk Akrilik',      'pola' => ['/^TH-/', '/^NM-/']],
            'magnet'     => ['label' => 'Pasang Magnet',        'pola' => ['/^FF-/']],
            'stiker'     => ['label' => 'Tempel Stiker',        'pola' => ['/^KOIN/', '/^AS-/']],
        ],
    ];

    public function __construct(private PenilaianKinerjaService $pk) {}

    /** Kelompok satu SKU (pola pertama yang cocok, urut KELOMPOK) atau null. */
    public static function kelompokSku(int $departmentId, ?string $sku): ?string
    {
        foreach (self::KELOMPOK[$departmentId] ?? [] as $kunci => $k) {
            foreach ($k['pola'] as $pola) {
                if ($sku && preg_match($pola, strtoupper($sku))) return $kunci;
            }
        }

        return null;
    }

    /**
     * Keterampilan satu orang per akhir bulan $sampai.
     *
     * @return array{kelompok: array<string, array>, dikuasai: int, total: int}
     */
    public function orang(int $departmentId, int $executorId, Carbon $sampai): array
    {
        $daftar = self::KELOMPOK[$departmentId] ?? [];
        $hasil = [];
        foreach ($daftar as $kunci => $k) {
            $hasil[$kunci] = ['label' => $k['label'], 'n' => 0, 'baik' => 0, 'dikuasai_sejak' => null, 'terakhir' => null, 'op' => []];
        }

        foreach ($this->opDinilai($departmentId, $sampai) as $o) {
            if (!collect($o['pelaksana'])->contains('id', $executorId)) continue;
            $baik = $o['poin'] >= 1;
            $kel = collect($o['rincian'])->map(fn ($r) => self::kelompokSku($departmentId, $r['sku']))->filter()->unique();
            foreach ($kel as $kunci) {
                $h = &$hasil[$kunci];
                $h['n']++;
                $h['baik'] += $baik ? 1 : 0;
                $h['terakhir'] = $o['selesai'];
                $h['op'][] = ['op' => $o['op'], 'op_id' => $o['op_id'], 'selesai' => $o['selesai'], 'rasio' => $o['rasio'], 'poin' => $o['poin'],
                    'isi' => collect($o['rincian'])->pluck('sku')->implode(', ')];
                // Syarat dinilai atas SELURUH riwayat: banyak OP lambat sesudahnya bisa mencabutnya.
                // `sejak` = saat syarat terakhir kali mulai terpenuhi (null bila kini tak terpenuhi).
                $memenuhi = $h['baik'] >= self::MIN_OP_BAIK && $h['baik'] / $h['n'] >= self::MIN_PORSI_BAIK;
                if (!$memenuhi) {
                    $h['dikuasai_sejak'] = null;
                } elseif (!$h['dikuasai_sejak']) {
                    $h['dikuasai_sejak'] = $o['selesai'];
                }
                unset($h);
            }
        }

        foreach ($hasil as &$h) {
            $h['status'] = $h['dikuasai_sejak'] ? 'dikuasai' : ($h['n'] ? 'belajar' : 'belum');
            $h['op'] = array_reverse($h['op']); // terbaru di atas
        }
        unset($h);

        return [
            'kelompok' => $hasil,
            'dikuasai' => collect($hasil)->where('status', 'dikuasai')->count(),
            'total'    => count($hasil),
        ];
    }

    /** OP berstatus dinilai sejak bulan standar pertama berlaku s/d akhir $sampai, urut selesai. */
    private function opDinilai(int $departmentId, Carbon $sampai): Collection
    {
        $mulai = ProductionStandardTime::where('department_id', $departmentId)->min('effective_from');
        if (!$mulai) return collect();

        $ops = collect();
        for ($b = Carbon::parse($mulai)->startOfMonth(); $b->lte($sampai); $b->addMonth()) {
            $ops = $ops->merge($this->pk->opBulan($departmentId, $b->copy())['op']->where('status', 'dinilai'));
        }

        return $ops->sortBy(fn ($o) => $o['selesai']?->timestamp ?? 0)->values();
    }
}
