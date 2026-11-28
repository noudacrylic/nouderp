<?php

namespace App\Modules\Production\Services;

use App\Modules\Analysis\Services\ProductionCalendarService;
use App\Modules\Production\Models\DepartmentExecutor;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Penilaian operator CNC (disetujui owner 28 Nov 2026), dari sinyal mesin:
 *
 * B. TANGGAP — tiap kejadian "mesin selesai memotong → memotong lagi":
 *    ≤ 3 mnt = 2 poin · 3–8 mnt = 1 poin · > 8 mnt = 0 poin. Nilai = rata-rata poin.
 *    - Hanya jam kerja reguler operator, selama ada pekerjaan (langkah di mesin itu / antrean CNC),
 *      dikurangi Pending beralasan di mesin itu. Selang tanpa sinyal → tidak dinilai.
 *    - ATURAN ANTRE: bila saat mesin selesai operator sedang melayani mesin lain yang selesai lebih
 *      dulu dan belum jalan lagi, waktu baru dihitung sejak mesin lain itu jalan lagi.
 *    - > 30 mnt → ditahan & masuk Meja Owner (skill pengawasan-cnc): wajar / abaikan / tidak wajar.
 * C. PENCATATAN (syarat) — porsi waktu mesin memotong yang tercatat di langkah OP berjalan di ERP.
 *    ≥ 95% aman; di bawahnya nilai dikalikan (porsi ÷ 95%).
 */
class PenilaianCncService
{
    public const POIN2_DETIK = 180;
    public const POIN1_DETIK = 480;
    public const CEK_DETIK = 1800;
    public const SYARAT_CATAT = 0.95;
    public const GABUNG_POTONG_DETIK = 20;

    public function __construct(private JedaMesinService $jeda, private PenilaianKinerjaService $pk) {}

    public static function poin(int $detik): int
    {
        return $detik <= self::POIN2_DETIK ? 2 : ($detik <= self::POIN1_DETIK ? 1 : 0);
    }

    /**
     * @return array{kejadian: Collection, nilai: array, pencatatan: array, mesin: Collection, dari: Carbon, sampai: Carbon}
     */
    public function bulan(DepartmentExecutor $operator, Carbon $bulan, ?Carbon $now = null): array
    {
        $now ??= now();
        $dari = $bulan->copy()->startOfMonth();
        $sampai = $bulan->copy()->endOfMonth()->min($now);
        $mesin = $operator->children()->whereNotNull('mesin_ip')->orderBy('name')->get(['id', 'name']);
        $kosong = ['kejadian' => collect(), 'nilai' => $this->ringkas(collect(), 1.0), 'pencatatan' => ['persen' => null, 'faktor' => 1.0, 'mesin' => []],
            'mesin' => $mesin, 'dari' => $dari, 'sampai' => $sampai];
        if ($mesin->isEmpty() || $sampai->lte($dari)) return $kosong;

        $kerja = $operator->karyawan_id ? $this->jeda->jamKerja((int) $operator->karyawan_id, $dari, $sampai) : [];
        $langkah = $this->jeda->langkah((int) $operator->department_id, $dari, $sampai);
        $antrean = JedaMesinService::gabung($langkah->map(fn ($s) => $s->siap ? [$s->siap, $s->mulai ?? $sampai->timestamp] : null)->filter()->all());
        $pending = app(ProductionCalendarService::class)->pendingsFor($dari, $sampai, $now);
        $batas = [[$dari->timestamp, $sampai->timestamp]];

        $kejadian = [];
        $catat = [];
        foreach ($mesin as $m) {
            $sibuk = JedaMesinService::gabung($langkah->filter(fn ($s) => $s->mulai && in_array($m->id, $s->mesin, true))
                ->map(fn ($s) => [$s->mulai, $s->selesai ?? $sampai->timestamp])->all());
            $adaKerjaan = JedaMesinService::gabung(array_merge($sibuk, $antrean));
            $pend = JedaMesinService::gabung(array_map(fn ($p) => [$p['start']->timestamp, $p['end']->timestamp], $pending[$m->id] ?? []));
            $hitung = JedaMesinService::selisih(JedaMesinService::irisan($kerja, $adaKerjaan), $pend);

            [$potong, $tercatat, $putus] = $this->potongan($m->id, $dari, $sampai, $now);

            // C. Pencatatan: waktu memotong (dalam bulan) yang tertutup langkah OP berjalan di mesin itu.
            $potongBulan = JedaMesinService::irisan(array_map(fn ($p) => [$p['a'], $p['b']], $potong), $batas);
            $catat[$m->id] = ['nama' => $m->name, 'potong' => JedaMesinService::panjang($potongBulan),
                'tercatat' => JedaMesinService::panjang(JedaMesinService::irisan($potongBulan, $sibuk))];

            for ($i = 0; $i + 1 < count($potong); $i++) {
                [$s, $mu] = [$potong[$i]['b'], $potong[$i + 1]['a']];
                if ($mu <= $dari->timestamp || $s >= $sampai->timestamp) continue;
                $tanpaData = JedaMesinService::panjang(JedaMesinService::selisih([[$s, $mu]], $tercatat))
                    + JedaMesinService::panjang(JedaMesinService::irisan([[$s, $mu]], $putus));
                if ($tanpaData > 60) continue;
                $kejadian[] = ['mesin_id' => $m->id, 'mesin' => $m->name, 'row_id' => $potong[$i]['row'], 'putusan' => $potong[$i]['putusan'],
                    's' => $s, 'm' => $mu, 'hitung' => $hitung];
            }
        }

        // Aturan antre: tunggu baru dihitung sejak mesin lain (selesai lebih dulu, masih dilayani) jalan lagi.
        usort($kejadian, fn ($a, $b) => $a['s'] <=> $b['s']);
        $hasil = collect();
        foreach ($kejadian as $e) {
            $efektif = $e['s'];
            foreach ($kejadian as $f) {
                if ($f['mesin_id'] === $e['mesin_id'] || $f['s'] > $e['s']) continue;
                if ($f['m'] > $e['s'] && $f['m'] < $e['m']) $efektif = max($efektif, $f['m']);
            }
            $detik = JedaMesinService::panjang(JedaMesinService::irisan([[$efektif, $e['m']]], $e['hitung']));
            $kotor = JedaMesinService::panjang(JedaMesinService::irisan([[$e['s'], $e['m']]], $e['hitung']));
            if ($kotor <= 0) continue; // seluruhnya di luar jam kerja / tanpa pekerjaan / Pending

            $poin = self::poin($detik);
            $status = 'dinilai';
            if ($e['putusan'] === 'abaikan') {
                $status = 'diabaikan';
            } elseif ($e['putusan'] === 'curang') {
                $poin = 0;
            } elseif (!$e['putusan'] && $detik > self::CEK_DETIK) {
                $status = 'cek';
            }
            $hasil->push([
                'row_id'  => $e['row_id'],
                'mesin_id'=> $e['mesin_id'],
                'mesin'   => $e['mesin'],
                'selesai' => Carbon::createFromTimestamp($e['s'], config('app.timezone')),
                'mulai'   => Carbon::createFromTimestamp($e['m'], config('app.timezone')),
                'detik'   => $detik,
                'antre'   => $kotor - $detik,
                'poin'    => $poin,
                'status'  => $status,
                'putusan' => $e['putusan'],
            ]);
        }

        $potongTotal = array_sum(array_column($catat, 'potong'));
        $persen = $potongTotal > 0 ? array_sum(array_column($catat, 'tercatat')) / $potongTotal : null;
        $faktor = $persen === null || $persen >= self::SYARAT_CATAT ? 1.0 : $persen / self::SYARAT_CATAT;

        return [
            'kejadian'   => $hasil->sortByDesc(fn ($k) => $k['selesai']->timestamp)->values(),
            'nilai'      => $this->ringkas($hasil, $faktor),
            'pencatatan' => ['persen' => $persen, 'faktor' => $faktor, 'mesin' => array_values($catat)],
            'mesin'      => $mesin,
            'dari'       => $dari,
            'sampai'     => $sampai,
        ];
    }

    /** Kejadian yang menunggu putusan owner, semua operator, sejak awal bulan $sejak. */
    public function perluDiputus(Carbon $sejak): Collection
    {
        $out = collect();
        foreach (JedaMesinService::operator() as $op) {
            for ($b = $sejak->copy()->startOfMonth(); $b->lte(now()); $b->addMonth()) {
                foreach ($this->bulan($op, $b->copy())['kejadian']->where('status', 'cek') as $k) {
                    $out->push($k + ['operator' => $op->name, 'operator_id' => $op->id]);
                }
            }
        }

        return $out;
    }

    /** Hitung ulang satu kejadian (untuk kartu Meja Owner). */
    public function kejadian(int $rowId): ?array
    {
        $row = DB::table('production_mesin_keadaan')->find($rowId);
        if (!$row || !$row->sampai_at) return null;
        $op = DepartmentExecutor::find(DepartmentExecutor::whereKey($row->executor_id)->value('parent_executor_id'));
        if (!$op) return null;

        return $this->bulan($op, Carbon::parse($row->sampai_at))['kejadian']->firstWhere('row_id', $rowId);
    }

    public function putuskan(int $rowId, string $putusan, ?int $userId): void
    {
        abort_unless(array_key_exists($putusan, PenilaianKinerjaService::PUTUSAN), 422);
        DB::table('production_mesin_keadaan')->where('id', $rowId)
            ->update(['putusan' => $putusan, 'putusan_by' => $userId, 'putusan_at' => now()]);
    }

    private function ringkas(Collection $k, float $faktor): array
    {
        $dinilai = $k->where('status', 'dinilai');
        $n = $dinilai->count();
        $rata = $n ? ($dinilai->where('poin', 2)->count() * 2 + $dinilai->where('poin', 1)->count()) / $n : null;
        $akhir = $rata === null ? null : round($rata * $faktor, 2);

        return [
            'n'        => $n,
            'poin2'    => $dinilai->where('poin', 2)->count(),
            'poin1'    => $dinilai->where('poin', 1)->count(),
            'poin0'    => $dinilai->where('poin', 0)->count(),
            'ditahan'  => $k->where('status', 'cek')->count(),
            'diabaikan'=> $k->where('status', 'diabaikan')->count(),
            'rata'     => $rata === null ? null : round($rata, 2),
            'faktor'   => $faktor,
            'akhir'    => $akhir,
            'tingkat'  => $this->pk->tingkat($akhir),
            'median'   => $n ? (int) $dinilai->pluck('detik')->sort()->values()[intdiv($n - 1, 2)] : null,
        ];
    }

    /**
     * Potongan "potong" satu mesin (digabung bila jedanya < 20 dtk), plus selang yang tercatat &
     * selang putus — semuanya detik unix.
     */
    private function potongan(int $mesinId, Carbon $dari, Carbon $sampai, Carbon $now): array
    {
        $rows = DB::table('production_mesin_keadaan')->where('executor_id', $mesinId)
            ->where('mulai_at', '<', $sampai)
            ->where(fn ($q) => $q->whereNull('sampai_at')->orWhere('sampai_at', '>', $dari->copy()->subDay()))
            ->orderBy('mulai_at')->orderBy('id')->get();
        $ts = fn ($v) => Carbon::parse($v)->timestamp;
        $akhir = function ($r) use ($ts, $now, $sampai) {
            if ($r->sampai_at) return $ts($r->sampai_at);
            $lihat = $ts($r->dilihat_at ?? $r->mulai_at);
            return min($sampai->timestamp, $now->timestamp - $lihat <= PantauMesinService::BATAS_SENYAP_DETIK ? $now->timestamp : $lihat);
        };

        $potong = [];
        foreach ($rows->where('keadaan', 'potong') as $r) {
            $iv = ['a' => $ts($r->mulai_at), 'b' => $akhir($r), 'row' => (int) $r->id, 'putusan' => $r->putusan];
            $n = count($potong);
            if ($n && $iv['a'] - $potong[$n - 1]['b'] < self::GABUNG_POTONG_DETIK) {
                // Satu pekerjaan: ujungnya pindah ke baris terakhir (tempat putusan disimpan).
                $potong[$n - 1] = ['a' => $potong[$n - 1]['a'], 'b' => max($potong[$n - 1]['b'], $iv['b']), 'row' => $iv['row'], 'putusan' => $iv['putusan']];
            } else {
                $potong[] = $iv;
            }
        }
        $tercatat = JedaMesinService::gabung($rows->map(fn ($r) => [$ts($r->mulai_at), $akhir($r)])->all());
        $putus = JedaMesinService::gabung($rows->where('keadaan', 'putus')->map(fn ($r) => [$ts($r->mulai_at), $akhir($r)])->all());

        return [$potong, $tercatat, $putus];
    }
}
