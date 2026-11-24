<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\DepartmentExecutor;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Penilaian operator mesin (CNC) Tahap 0 — dari data ERP saja, tanpa sinyal mesin (24 Nov 2026).
 *
 * Satu operator menaungi beberapa mesin (eksekutor anak). Mesin dianggap BEKERJA selama ada
 * langkah di mesin itu antara Mulai dan Selesai (jeda di dalam langkah tetap dihitung bekerja —
 * belum terlihat tanpa sinyal mesin). Di luar itu mesin DIAM. Diam dihitung hanya:
 *   - di jam kerja reguler operator (sudah scan masuk, belum scan pulang, bukan istirahat; lembur
 *     tidak ikut), dan
 *   - saat ADA ANTREAN: ada langkah divisi ini yang siap dikerjakan (OP sudah dibuat & langkah
 *     sebelumnya selesai) tapi belum dimulai. Antrean dihitung untuk divisi, bukan per mesin.
 * Hasilnya informasi untuk owner, belum masuk nilai.
 */
class JedaMesinService
{
    /** Jeda diam-saat-ada-antrean sependek ini tidak didaftar (ganti lembar/ambil hasil wajar). */
    public const AMBANG_MENIT = 10;

    public function __construct(private ExecutorScheduleResolver $resolver) {}

    /** Operator = eksekutor divisi yang punya mesin anak. */
    public static function operator(?int $departmentId = null)
    {
        return DepartmentExecutor::query()
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->whereIn('id', DepartmentExecutor::whereNotNull('parent_executor_id')->select('parent_executor_id'))
            ->orderByDesc('is_active')->orderBy('name')->get();
    }

    /**
     * @return array{mesin: array<int, array>, jam_kerja: int, dari: Carbon, sampai: Carbon, ambang: int}
     */
    public function bulan(DepartmentExecutor $operator, Carbon $bulan, ?Carbon $now = null): array
    {
        $now ??= now();
        $dari = $bulan->copy()->startOfMonth();
        $sampai = $bulan->copy()->endOfMonth()->min($now);
        $mesin = $operator->children()->orderBy('name')->get(['id', 'name']);

        $kerja = $sampai->gt($dari) && $operator->karyawan_id
            ? $this->jamKerja((int) $operator->karyawan_id, $dari, $sampai) : [];
        $langkah = $this->langkah((int) $operator->department_id, $dari, $sampai);
        $antrean = self::gabung($langkah->map(fn ($s) => $s->siap ? [$s->siap, $s->mulai ?? $sampai->timestamp] : null)->filter()->all());

        $hasil = [];
        foreach ($mesin as $m) {
            $punya = $langkah->filter(fn ($s) => $s->mulai && in_array($m->id, $s->mesin, true))->sortBy('mulai')->values();
            $sibuk = self::gabung($punya->map(fn ($s) => [$s->mulai, $s->selesai ?? $sampai->timestamp])->all());
            $diam = self::selisih([[$dari->timestamp, $sampai->timestamp]], $sibuk);

            $jeda = [];
            foreach ($diam as [$a, $b]) {
                $diKerja = self::irisan([[$a, $b]], $kerja);
                $adaAntrean = self::irisan($diKerja, $antrean);
                $mnt = intdiv(self::panjang($adaAntrean), 60);
                if ($mnt < self::AMBANG_MENIT) continue;
                $sebelum = $punya->filter(fn ($s) => $s->selesai && $s->selesai <= $a)->last();
                $sesudah = $punya->first(fn ($s) => $s->mulai >= $b);
                $mulaiTunggu = $adaAntrean[0][0];
                $jeda[] = [
                    'dari'         => Carbon::createFromTimestamp($a, config('app.timezone')),
                    'sampai'       => $b >= $sampai->timestamp && !$sesudah ? null : Carbon::createFromTimestamp($b, config('app.timezone')),
                    'menit_kerja'  => intdiv(self::panjang($diKerja), 60),
                    'menit'        => $mnt,
                    'sebelum'      => $sebelum ? ['op' => $sebelum->order_number, 'op_id' => $sebelum->op_id] : null,
                    'sesudah'      => $sesudah ? ['op' => $sesudah->order_number, 'op_id' => $sesudah->op_id] : null,
                    'menunggu'     => $langkah->filter(fn ($s) => $s->siap && $s->siap <= $mulaiTunggu && ($s->mulai ?? PHP_INT_MAX) > $mulaiTunggu)
                        ->take(4)->map(fn ($s) => ['op' => $s->order_number, 'op_id' => $s->op_id])->values()->all(),
                ];
            }

            $jalan = intdiv(self::panjang(self::irisan($sibuk, $kerja)), 60);
            $diamKerja = intdiv(self::panjang(self::irisan($diam, $kerja)), 60);
            $diamAntrean = intdiv(self::panjang(self::irisan(self::irisan($diam, $kerja), $antrean)), 60);
            $hasil[] = [
                'id'           => $m->id,
                'nama'         => $m->name,
                'jalan'        => $jalan,
                'diam_antrean' => $diamAntrean,
                'diam_kosong'  => max(0, $diamKerja - $diamAntrean),
                'langkah'      => $punya->filter(fn ($s) => $s->selesai && $s->selesai >= $dari->timestamp)->count(),
                'jeda'         => array_reverse($jeda), // terbaru di atas
            ];
        }

        return [
            'mesin'     => $hasil,
            'jam_kerja' => intdiv(self::panjang($kerja), 60),
            'dari'      => $dari,
            'sampai'    => $sampai,
            'ambang'    => self::AMBANG_MENIT,
        ];
    }

    /** Interval jam kerja reguler operator (detik unix), per menit dari jadwal + scan. */
    private function jamKerja(int $karyawanId, Carbon $dari, Carbon $sampai): array
    {
        $iv = [];
        for ($hari = $dari->copy()->startOfDay(); $hari->lte($sampai); $hari->addDay()) {
            $jadwal = $this->resolver->forKaryawan($karyawanId, $hari);
            if (!$jadwal || $jadwal->is_off || !$jadwal->jam_masuk || !$jadwal->jam_pulang) continue;
            $masuk = $this->resolver->hasCheckedIn($karyawanId, $hari);
            if (!$masuk) continue;
            $pulang = $this->resolver->hasCheckedOut($karyawanId, $hari);

            $t = Carbon::parse($hari->toDateString() . ' ' . $jadwal->jam_masuk);
            $akhir = Carbon::parse($hari->toDateString() . ' ' . $jadwal->jam_pulang);
            for (; $t->lt($akhir); $t->addMinute()) {
                if ($t->lt($masuk) || ($pulang && $t->gte($pulang)) || $t->lt($dari) || $t->gte($sampai)) continue;
                if ($this->resolver->currentStatus($jadwal, $t, null) !== 'in_work') continue;
                $iv[] = [$t->timestamp, $t->timestamp + 60];
            }
        }

        return self::gabung($iv);
    }

    /**
     * Langkah divisi yang menyentuh periode: mulai/selesai (unix), mesin pelaksana, dan saat
     * SIAP dikerjakan (OP dibuat & langkah sebelumnya selesai; null = belum siap s/d kini).
     */
    private function langkah(int $departmentId, Carbon $dari, Carbon $sampai)
    {
        $rows = DB::table('production_order_steps as s')
            ->join('production_orders as o', 'o.id', '=', 's.production_order_id')
            ->where('s.department_id', $departmentId)
            ->whereNotIn('o.status', ['draft', 'cancelled'])
            ->where('o.created_at', '<', $sampai)
            ->where(fn ($q) => $q->whereNull('s.completed_at')->orWhere('s.completed_at', '>=', $dari->copy()->subDays(45)))
            ->select('s.id', 's.production_order_id as op_id', 's.step_number', 's.started_at', 's.completed_at', 'o.order_number', 'o.status as op_status', 'o.created_at as op_dibuat')
            ->get();
        if ($rows->isEmpty()) return collect();

        $mesin = DB::table('production_order_step_executors')->whereIn('step_id', $rows->pluck('id'))
            ->get(['step_id', 'executor_id'])->groupBy('step_id');
        $sebelumnya = DB::table('production_order_steps')->whereIn('production_order_id', $rows->pluck('op_id')->unique())
            ->get(['production_order_id', 'step_number', 'completed_at', 'department_id', 'desain'])->groupBy('production_order_id');

        $ts = fn ($v) => $v ? Carbon::parse($v)->timestamp : null;

        return $rows->map(function ($r) use ($mesin, $sebelumnya, $ts) {
            $siap = $ts($r->op_dibuat);
            foreach ($sebelumnya[$r->op_id] ?? [] as $p) {
                if ($p->step_number >= $r->step_number) continue;
                if (!$p->completed_at) { $siap = null; break; }
                $siap = max($siap, $ts($p->completed_at));
            }
            $mulai = $ts($r->started_at);
            // Langkah sebelumnya selesai SESUDAH langkah ini dimulai (data lama) → anggap siap saat dimulai.
            if ($siap && $mulai && $siap > $mulai) $siap = $mulai;
            if (!$siap && $mulai) $siap = $mulai;
            // Langkah tak pernah dimulai hanya antrean selama OP-nya masih berjalan (OP selesai /
            // difinalisasi tanpa langkah ini dimulai bukan antrean).
            if (!$mulai && !in_array($r->op_status, ['confirmed', 'in_progress'], true)) $siap = null;

            return (object) [
                'op_id'        => (int) $r->op_id,
                'order_number' => $r->order_number,
                'mulai'        => $mulai,
                'selesai'      => $ts($r->completed_at),
                'siap'         => $siap,
                'mesin'        => ($mesin[$r->id] ?? collect())->pluck('executor_id')->map(fn ($v) => (int) $v)->all(),
            ];
        })->values();
    }

    // ---- aritmetika interval [awal, akhir) dalam detik ----

    public static function gabung(array $iv): array
    {
        $iv = array_values(array_filter($iv, fn ($x) => $x && $x[1] > $x[0]));
        usort($iv, fn ($a, $b) => $a[0] <=> $b[0]);
        $out = [];
        foreach ($iv as [$a, $b]) {
            if ($out && $a <= $out[count($out) - 1][1]) {
                $out[count($out) - 1][1] = max($out[count($out) - 1][1], $b);
            } else {
                $out[] = [$a, $b];
            }
        }

        return $out;
    }

    /** Irisan dua daftar interval yang sudah tergabung & terurut. */
    public static function irisan(array $x, array $y): array
    {
        $out = [];
        $i = $j = 0;
        while ($i < count($x) && $j < count($y)) {
            $a = max($x[$i][0], $y[$j][0]);
            $b = min($x[$i][1], $y[$j][1]);
            if ($b > $a) $out[] = [$a, $b];
            $x[$i][1] < $y[$j][1] ? $i++ : $j++;
        }

        return $out;
    }

    /** $x dikurangi $y (keduanya tergabung & terurut). */
    public static function selisih(array $x, array $y): array
    {
        $out = [];
        foreach ($x as [$a, $b]) {
            foreach ($y as [$c, $d]) {
                if ($d <= $a || $c >= $b) continue;
                if ($c > $a) $out[] = [$a, $c];
                $a = max($a, $d);
                if ($a >= $b) break;
            }
            if ($a < $b) $out[] = [$a, $b];
        }

        return $out;
    }

    public static function panjang(array $iv): int
    {
        return array_sum(array_map(fn ($x) => $x[1] - $x[0], $iv));
    }
}
