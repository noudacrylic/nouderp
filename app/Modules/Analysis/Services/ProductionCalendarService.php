<?php

namespace App\Modules\Analysis\Services;

use App\Modules\Production\Models\MachineDowntime;
use App\Modules\Production\Services\ExecutorScheduleResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Kalender produksi harian — memperlihatkan APA yang dikerjakan tiap mesin & operator
 * sepanjang satu hari kerja, supaya lubang kapasitas terlihat sebagai ruang kosong,
 * bukan sebagai persentase yang harus dipercaya begitu saja.
 *
 * Kenapa dibangun dari `production_step_time_logs`, bukan dari `elapsed_working_seconds`:
 * accessor itu hanya menghasilkan SATU angka total per langkah, sedangkan yang dicari di
 * sini justru sebaran waktunya. Log-nya juga satu-satunya sumber yang tahu kapan timer
 * berhenti dan kenapa (auto-pause jam pulang vs operator menekan selesai).
 *
 * Aturan penempatan baris:
 *  - Barisnya hanya eksekutor PELAKU (daun). Operator penaung seperti Andi tidak punya
 *    baris: di CNC yang bekerja mesinnya, dan barisnya hanya akan mengulang jam mesin yang
 *    ditungguinya. Ini sejalan dengan keputusan "1 slot = eksekutor daun".
 *  - Langkah lama yang terlanjur tercatat atas nama operator penaung tidak hilang: blok itu
 *    jatuh ke baris "Tanpa eksekutor" dan dihitung sebagai `orphan`, supaya terlihat bahwa
 *    mesinnya jalan tapi tidak diketahui mesin yang mana.
 */
class ProductionCalendarService
{
    public function __construct(protected ExecutorScheduleResolver $schedules)
    {
    }

    /** Jam tampil minimal, dipakai bila jadwal tidak ketemu. */
    public const FALLBACK_START = '08:00';
    public const FALLBACK_END   = '16:00';

    /** Celah di bawah ini tidak dianggap lubang — jeda ganti benda kerja, bukan kapasitas hilang. */
    public const GAP_MIN_SECONDS = 300;

    /**
     * @return array{
     *   date: Carbon, is_today: bool, window: array{start: Carbon, end: Carbon},
     *   departments: array<int,array>, totals: array, has_data: bool, generated_at: Carbon
     * }
     */
    public function build(Carbon $date): array
    {
        $dayStart = $date->copy()->startOfDay();
        $dayEnd   = $date->copy()->endOfDay();
        $now      = Carbon::now();

        $holiday          = $this->holidayFor($date);
        $blocksByExecutor = $this->blocksFor($dayStart, $dayEnd, $now);
        $downtimes        = $this->downtimesFor($dayStart, $dayEnd);
        $departments      = $this->rows($date, $blocksByExecutor, $holiday, $downtimes);

        [$winStart, $winEnd] = $this->window($date, $departments);

        // Hitung ulang statistik setelah jendela final diketahui (lubang diukur di dalam shift,
        // bukan di dalam jendela tampilan — jendela bisa lebih lebar karena ada kerja di luar jam).
        foreach ($departments as &$dept) {
            foreach ($dept['rows'] as &$row) {
                $row = $this->withStats($row);
            }
            unset($row);
            $dept = $this->withDeptStats($dept);
        }
        unset($dept);

        return [
            'date'         => $date->copy(),
            'is_today'     => $date->isSameDay($now),
            'holiday'      => $holiday,
            'window'       => ['start' => $winStart, 'end' => $winEnd],
            'departments'  => array_values($departments),
            'totals'       => $this->grandTotals($departments),
            'has_data'     => collect($departments)->contains(fn ($d) => $d['busy_seconds'] > 0),
            'generated_at' => $now,
        ];
    }

    /** Tanggal terakhir yang punya aktivitas produksi — untuk tombol "lompat ke hari terakhir". */
    public function lastActiveDate(): ?Carbon
    {
        $val = DB::table('production_step_time_logs')->max('occurred_at');

        return $val ? Carbon::parse($val)->startOfDay() : null;
    }

    // ── Blok waktu ────────────────────────────────────────────────────────────────

    /**
     * Susun ulang interval "timer jalan" dari log, lalu tempelkan ke tiap eksekutor.
     *
     * @return array<int,array<int,array>>  [executor_id => blok[]]
     */
    protected function blocksFor(Carbon $dayStart, Carbon $dayEnd, Carbon $now): array
    {
        // Langkah yang menyentuh hari ini: punya log di hari ini, ATAU masih berjalan sejak
        // sebelum hari ini (timer menggantung — justru kasus yang ingin dilihat).
        $touched = DB::table('production_step_time_logs')
            ->whereBetween('occurred_at', [$dayStart, $dayEnd])
            ->distinct()->pluck('production_order_step_id');

        $running = DB::table('production_order_steps')
            ->where('status', 'in_progress')
            ->where('started_at', '<', $dayStart)
            ->pluck('id');

        $stepIds = $touched->merge($running)->unique()->values();
        if ($stepIds->isEmpty()) {
            return [];
        }

        $meta = DB::table('production_order_steps as s')
            ->join('production_orders as o', 'o.id', '=', 's.production_order_id')
            ->leftJoin('production_order_outputs as po', function ($j) {
                $j->on('po.production_order_id', '=', 'o.id')->where('po.output_type', '=', 'main');
            })
            ->leftJoin('products as p', 'p.id', '=', 'po.product_id')
            ->whereIn('s.id', $stepIds)
            ->select(
                's.id', 's.name as step_name', 's.department_id', 's.status', 's.executor_id',
                'o.id as order_id', 'o.order_number', 'o.type as order_type', 'o.planned_cycles',
                'p.name as product_name', 'p.sku'
            )
            ->get()->keyBy('id');

        $logs = DB::table('production_step_time_logs')
            ->whereIn('production_order_step_id', $stepIds)
            ->orderBy('occurred_at')->orderBy('id')
            ->get()->groupBy('production_order_step_id');

        $execByStep = DB::table('production_order_step_executors')
            ->whereIn('step_id', $stepIds)
            ->get()->groupBy('step_id');

        // Operator penaung tidak punya baris. Langkah lama yang terlanjur tercatat atas
        // namanya tidak boleh menguap — kalau setelah disaring tidak ada pelaku tersisa,
        // bloknya jatuh ke baris "Tanpa eksekutor".
        $supervisorIds = DB::table('production_department_executors')
            ->whereNotNull('parent_executor_id')
            ->distinct()->pluck('parent_executor_id')
            ->map(fn ($v) => (int) $v)->all();

        $out = [];
        foreach ($stepIds as $stepId) {
            $m = $meta[$stepId] ?? null;
            if (!$m) {
                continue;
            }

            $executorIds = ($execByStep[$stepId] ?? collect())->pluck('executor_id')->all();
            if (empty($executorIds) && $m->executor_id) {
                $executorIds = [(int) $m->executor_id];
            }

            $executorIds = array_values(array_diff(array_map('intval', $executorIds), $supervisorIds));
            if (empty($executorIds)) {
                $executorIds = [0];
            }

            foreach ($this->intervals($logs[$stepId] ?? collect(), $m, $dayStart, $dayEnd, $now) as $iv) {
                foreach ($executorIds as $eid) {
                    $out[(int) $eid][] = $iv + ['executor_id' => (int) $eid];
                }
            }
        }

        foreach ($out as &$list) {
            usort($list, fn ($a, $b) => $a['start']->timestamp <=> $b['start']->timestamp);
        }

        return $out;
    }

    /**
     * Pasangkan log mulai/lanjut dengan log jeda/selesai jadi interval, lalu potong ke hari ini.
     *
     * Interval yang belum tertutup DAN langkahnya masih `in_progress` dianggap berjalan sampai
     * sekarang — itulah cara timer menggantung menampakkan dirinya di kalender.
     */
    protected function intervals($logs, $meta, Carbon $dayStart, Carbon $dayEnd, Carbon $now): array
    {
        $open = ['started', 'resumed', 'auto_resumed'];
        $shut = ['paused', 'auto_paused', 'completed'];

        $result = [];
        $start  = null;
        $reason = null;

        foreach ($logs as $log) {
            $at = Carbon::parse($log->occurred_at);

            if (in_array($log->event_type, $open, true)) {
                $start  = $at;
                $reason = $log->event_type;
            } elseif ($start && in_array($log->event_type, $shut, true)) {
                if ($at->gt($start)) {
                    $result[] = $this->clip($start, $at, $meta, $reason, $log->event_type, false, $dayStart, $dayEnd);
                }
                $start = $reason = null;
            }
        }

        if ($start && $meta->status === 'in_progress') {
            $end = $now->gt($start) ? $now : $start->copy();
            $result[] = $this->clip($start, $end, $meta, $reason, null, true, $dayStart, $dayEnd);
        }

        return array_values(array_filter($result));
    }

    protected function clip(Carbon $s, Carbon $e, $meta, ?string $openedBy, ?string $closedBy, bool $stillOpen, Carbon $dayStart, Carbon $dayEnd): ?array
    {
        if ($e->lte($dayStart) || $s->gte($dayEnd)) {
            return null;
        }

        $cs = $s->lt($dayStart) ? $dayStart->copy() : $s->copy();
        $ce = $e->gt($dayEnd) ? $dayEnd->copy() : $e->copy();
        $sec = (int) $cs->diffInSeconds($ce);
        if ($sec <= 0) {
            return null;
        }

        return [
            'start'          => $cs,
            'end'            => $ce,
            'seconds'        => $sec,
            'from_yesterday' => $s->lt($dayStart),
            'into_tomorrow'  => $e->gt($dayEnd),
            'still_open'     => $stillOpen,
            'opened_by'      => $openedBy,
            'closed_by'      => $closedBy,
            'step_id'        => (int) $meta->id,
            'step_name'      => $meta->step_name,
            'department_id'  => (int) $meta->department_id,
            'order_id'       => (int) $meta->order_id,
            'order_number'   => $meta->order_number,
            'order_type'     => $meta->order_type,
            'cycles'         => (float) ($meta->planned_cycles ?? 0),
            'product_name'   => $meta->product_name,
            'sku'            => $meta->sku,
        ];
    }

    // ── Baris (eksekutor) ─────────────────────────────────────────────────────────

    protected function rows(Carbon $date, array $blocksByExecutor, ?object $holiday, array $downtimes = []): array
    {
        $executors = DB::table('production_department_executors as e')
            ->join('production_departments as d', 'd.id', '=', 'e.department_id')
            ->where('e.is_active', 1)
            ->select('e.id', 'e.name', 'e.department_id', 'e.parent_executor_id', 'e.karyawan_id', 'd.name as dept_name', 'd.type as dept_type')
            ->orderBy('e.department_id')->orderByRaw('COALESCE(e.parent_executor_id, e.id)')->orderBy('e.id')
            ->get();

        $hasChild  = $executors->whereNotNull('parent_executor_id')->pluck('parent_executor_id')->unique()->flip();
        $shifts    = $this->shifts($date, $executors, $holiday);
        $absences  = $this->absences($date, $executors);

        $depts = [];
        foreach ($executors as $ex) {
            // Operator penaung (mis. Andi) tidak ditampilkan: sejak pilihan eksekutor
            // dibatasi ke pelaku sebenarnya, dia tidak pernah punya blok, dan barisnya
            // hanya akan mengulang jam mesin yang ditungguinya.
            if (isset($hasChild[$ex->id])) {
                continue;
            }

            $depts[$ex->department_id] ??= [
                'id'    => (int) $ex->department_id,
                'name'  => $ex->dept_name,
                'type'  => $ex->dept_type,
                'rows'  => [],
            ];

            $depts[$ex->department_id]['rows'][] = [
                'executor_id'    => (int) $ex->id,
                'name'           => $ex->name,
                'is_machine'     => $ex->karyawan_id === null,
                'is_leaf'        => true,
                'counts_as_slot' => true,
                'parent_id'      => $ex->parent_executor_id ? (int) $ex->parent_executor_id : null,
                'shift'          => $shifts[$ex->id],
                'absence'        => $this->absenceLabel($absences[$ex->id] ?? null, $shifts[$ex->id]),
                'blocks'         => $blocksByExecutor[$ex->id] ?? [],
                'downtimes'      => $downtimes[$ex->id] ?? [],
            ];
        }

        // Blok tanpa eksekutor terdaftar — jangan hilang, taruh di divisinya sebagai baris khusus.
        if (!empty($blocksByExecutor[0])) {
            foreach (collect($blocksByExecutor[0])->groupBy('department_id') as $deptId => $blocks) {
                if (!isset($depts[$deptId])) {
                    continue;
                }
                $depts[$deptId]['rows'][] = [
                    'executor_id'    => 0,
                    'name'           => 'Tanpa eksekutor',
                    'is_machine'     => false,
                    'is_leaf'        => false,
                    'counts_as_slot' => false,
                    'parent_id'      => null,
                    'absence'        => null,
                    'shift'          => $this->deptShift($date, $deptId, $shifts, $depts[$deptId]['rows']),
                    'blocks'         => $blocks->all(),
                    'downtimes'      => [],
                ];
            }
        }

        return $depts;
    }

    /**
     * Jam kerja tiap eksekutor pada tanggal itu.
     *
     * Mesin tidak punya karyawan, jadi ia meminjam jadwal INDUKNYA (operator yang menunggui).
     * Kalau tidak ada induk, dipakai jadwal terpanjang di divisinya.
     */
    protected function shifts(Carbon $date, $executors, ?object $holiday = null): array
    {
        // Libur nasional: pabrik tidak dijadwalkan buka, jadi tidak ada kapasitas yang bisa
        // hilang. Ini sejalan dengan halaman Fixed Cost yang membagi biaya dengan HARI KERJA
        // AKTUAL dari slip gaji — tanggal merah memang sudah di luar pembagi.
        if ($holiday) {
            return $executors->mapWithKeys(fn ($ex) => [$ex->id => $this->offShift()])->all();
        }

        // Sengaja memakai resolver yang SAMA dengan timer produksi. Kalau kalender punya
        // aturan sendiri, suatu hari keduanya akan berbeda pendapat soal "hari ini kerja atau
        // tidak", dan kapasitas di halaman ini tidak lagi menggambarkan hari yang benar-benar
        // bisa dipakai bekerja. Resolver itu yang tahu soal tukar hari dan masuk di hari libur.
        $byId  = $executors->keyBy('id');
        $cache = [];

        $shiftOf = function ($karyawanId) use ($date, &$cache) {
            if (!array_key_exists($karyawanId, $cache)) {
                $sched = $this->schedules->scheduledFor($karyawanId, $date);

                $cache[$karyawanId] = $sched ? $this->shiftFromRow($date, $sched) : null;
            }

            return $cache[$karyawanId];
        };

        // Mesin tidak punya karyawan — ia meminjam jadwal INDUKNYA (operator yang menunggui).
        $resolve = function ($ex) use (&$resolve, $byId, $shiftOf) {
            if ($ex->karyawan_id) {
                return $shiftOf((int) $ex->karyawan_id);
            }

            return $ex->parent_executor_id && isset($byId[$ex->parent_executor_id])
                ? $resolve($byId[$ex->parent_executor_id])
                : null;
        };

        // Jadwal terpanjang per divisi, sebagai cadangan bila eksekutor tidak punya jadwal.
        $deptFallback = [];
        foreach ($executors as $ex) {
            $s = $resolve($ex);
            if ($s && !$s['is_off']) {
                $cur = $deptFallback[$ex->department_id] ?? null;
                if (!$cur || $s['seconds'] > $cur['seconds']) {
                    $deptFallback[$ex->department_id] = $s;
                }
            }
        }

        $out = [];
        foreach ($executors as $ex) {
            $out[$ex->id] = $resolve($ex)
                ?? $deptFallback[$ex->department_id]
                ?? $this->defaultShift($date);
        }

        return $out;
    }

    /** Hari tanpa jadwal kerja: tidak ada kapasitas, jadi tidak ada yang bisa jadi lubang. */
    protected function offShift(): array
    {
        return ['is_off' => true, 'start' => null, 'end' => null, 'break_start' => null, 'break_end' => null, 'seconds' => 0];
    }

    /**
     * Keterangan mana yang layak ditampilkan di baris.
     *
     * Pada hari yang memang tidak dijadwalkan kerja, "Libur"/"tidak ada catatan absensi"
     * cuma mengulang yang sudah kelihatan. Tapi TUKAR HARI justru wajib tampil di hari yang
     * jadi kosong itu — tanpa keterangannya, hari kerja yang tiba-tiba nol terbaca seperti
     * data hilang.
     */
    protected function absenceLabel(?array $absence, array $shift): ?array
    {
        if (!$absence) {
            return null;
        }

        $selaluTampil = ['Tukar hari', 'Tukar setengah hari', 'Cuti', 'Sakit'];

        if (($shift['is_off'] ?? true) && !in_array($absence['label'], $selaluTampil, true)) {
            return null;
        }

        return $absence;
    }

    /**
     * Henti mesin yang menyentuh hari ini, dipotong ke hari ini.
     *
     * @return array<int,array<int,array>>  [executor_id => henti[]]
     */
    protected function downtimesFor(Carbon $dayStart, Carbon $dayEnd): array
    {
        $rows = MachineDowntime::where('started_at', '<', $dayEnd)
            ->where('ended_at', '>', $dayStart)
            ->orderBy('started_at')
            ->get();

        $out = [];
        foreach ($rows as $d) {
            $start = $d->started_at->lt($dayStart) ? $dayStart->copy() : $d->started_at->copy();
            $end   = $d->ended_at->gt($dayEnd) ? $dayEnd->copy() : $d->ended_at->copy();
            if ($end->lte($start)) {
                continue;
            }

            $out[(int) $d->executor_id][] = [
                'id'      => $d->id,
                'start'   => $start,
                'end'     => $end,
                'seconds' => (int) $start->diffInSeconds($end),
                'reason'  => $d->reason,
                'label'   => $d->reasonLabel(),
                'notes'   => $d->notes,
            ];
        }

        return $out;
    }

    /** Libur nasional / cuti bersama pada tanggal itu, kalau terdaftar di SDM → Libur. */
    protected function holidayFor(Carbon $date): ?object
    {
        return DB::table('sdm_national_holidays')->whereDate('tanggal', $date)->first();
    }

    /**
     * Alasan orangnya tidak ada pada tanggal itu — supaya lubang punya penjelasan.
     *
     * Kapasitasnya TETAP dihitung. Cuti seorang operator tidak membuat sewa gedung dan gaji
     * berhenti berjalan, jadi jam mesin yang menganggur karenanya memang biaya kapasitas
     * menganggur — beda dengan tanggal merah, yang sejak awal tidak masuk hari kerja.
     * Mesin ikut alasan operator yang menungguinya.
     *
     * @return array<int,array{label: string, note: ?string}>  [executor_id => …]
     */
    protected function absences(Carbon $date, $executors): array
    {
        $byId = $executors->keyBy('id');

        $karyawanOf = function ($ex) use (&$karyawanOf, $byId) {
            if ($ex->karyawan_id) {
                return (int) $ex->karyawan_id;
            }

            return $ex->parent_executor_id && isset($byId[$ex->parent_executor_id])
                ? $karyawanOf($byId[$ex->parent_executor_id])
                : null;
        };

        $map = [];
        foreach ($executors as $ex) {
            if ($k = $karyawanOf($ex)) {
                $map[$ex->id] = $k;
            }
        }
        if (empty($map)) {
            return [];
        }

        $karyawanIds = array_values(array_unique($map));

        $overrides = DB::table('sdm_attendance_overrides')
            ->whereIn('karyawan_id', $karyawanIds)->whereDate('tanggal', $date)
            ->get()->keyBy('karyawan_id');

        $attendance = DB::table('sdm_attendance')
            ->whereIn('karyawan_id', $karyawanIds)->whereDate('tanggal', $date)
            ->get()->keyBy('karyawan_id');

        $label = [
            'cuti'                => 'Cuti',
            'sakit'               => 'Sakit',
            'tukar_hari'          => 'Tukar hari',
            'tukar_setengah_hari' => 'Tukar setengah hari',
            'tidak_hadir'         => 'Tidak hadir',
            'libur'               => 'Libur',
            'setengah_hari'       => 'Setengah hari',
        ];

        $out = [];
        foreach ($map as $execId => $karyawanId) {
            $o = $overrides[$karyawanId] ?? null;
            $a = $attendance[$karyawanId] ?? null;

            if ($o) {
                $out[$execId] = ['label' => $label[$o->type] ?? $o->type, 'note' => $o->notes];
            } elseif (!$a) {
                $out[$execId] = ['label' => 'Tidak ada catatan absensi', 'note' => null];
            } elseif (isset($label[$a->status]) && $a->status !== 'setengah_hari') {
                $out[$execId] = ['label' => $label[$a->status], 'note' => $a->remark];
            } elseif ($a->status === 'setengah_hari') {
                $out[$execId] = ['label' => 'Setengah hari', 'note' => $a->remark];
            }
        }

        return $out;
    }

    protected function deptShift(Carbon $date, int $deptId, array $shifts, array $rows): array
    {
        foreach ($rows as $r) {
            if (!($r['shift']['is_off'] ?? true)) {
                return $r['shift'];
            }
        }

        return $this->defaultShift($date);
    }

    protected function shiftFromRow(Carbon $date, $row): array
    {
        if ($row->is_off || !$row->jam_masuk || !$row->jam_pulang) {
            return ['is_off' => true, 'start' => null, 'end' => null, 'break_start' => null, 'break_end' => null, 'seconds' => 0];
        }

        $start = $this->at($date, $row->jam_masuk);
        $end   = $this->at($date, $row->jam_pulang);
        if ($end->lte($start)) {
            $end = $end->addDay();
        }

        $bs = $row->jam_istirahat_start ? $this->at($date, $row->jam_istirahat_start) : null;
        $be = $row->jam_istirahat_end   ? $this->at($date, $row->jam_istirahat_end)   : null;

        $seconds = (int) $start->diffInSeconds($end);
        if ($bs && $be && $be->gt($bs)) {
            $seconds -= (int) $bs->diffInSeconds($be);
        }

        return [
            'is_off'      => false,
            'start'       => $start,
            'end'         => $end,
            'break_start' => $bs,
            'break_end'   => $be,
            'seconds'     => max(0, $seconds),
        ];
    }

    protected function defaultShift(Carbon $date): array
    {
        $start = $this->at($date, self::FALLBACK_START);
        $end   = $this->at($date, self::FALLBACK_END);

        return [
            'is_off'      => false,
            'start'       => $start,
            'end'         => $end,
            'break_start' => null,
            'break_end'   => null,
            'seconds'     => (int) $start->diffInSeconds($end),
        ];
    }

    protected function at(Carbon $date, string $time): Carbon
    {
        return Carbon::parse($date->toDateString() . ' ' . substr($time, 0, 8));
    }

    // ── Statistik ─────────────────────────────────────────────────────────────────

    /**
     * Terpakai, tumpang-tindih, lubang, dan di luar jam — dihitung dari GABUNGAN interval
     * (union), bukan penjumlahan blok, supaya dua blok yang bertumpuk di satu mesin tidak
     * menghasilkan "8 jam terpakai dari shift 7 jam".
     */
    protected function withStats(array $row): array
    {
        $shift  = $row['shift'];
        $merged = $this->merge(array_map(fn ($b) => [$b['start'], $b['end']], $row['blocks']));

        $rawSum   = array_sum(array_column($row['blocks'], 'seconds'));
        $unionSec = array_sum(array_map(fn ($s) => (int) $s[0]->diffInSeconds($s[1]), $merged));

        $work    = $this->workSegments($shift);
        $inside  = $this->intersect($merged, $work);
        $busyIn  = array_sum(array_map(fn ($s) => (int) $s[0]->diffInSeconds($s[1]), $inside));
        $outside = max(0, $unionSec - $busyIn);

        // Henti mesin yang sudah dicatat BUKAN lubang: lubangnya sudah punya nama. Dihitung
        // di dalam shift saja, sama seperti waktu terpakai.
        $henti     = $this->merge(array_map(fn ($d) => [$d['start'], $d['end']], $row['downtimes'] ?? []));
        $hentiIn   = $this->intersect($henti, $work);
        $hentiSec  = array_sum(array_map(fn ($s) => (int) $s[0]->diffInSeconds($s[1]), $hentiIn));

        $gaps = [];
        foreach ($this->subtract($work, array_merge($merged, $henti)) as $g) {
            $sec = (int) $g[0]->diffInSeconds($g[1]);
            if ($sec >= self::GAP_MIN_SECONDS) {
                $gaps[] = ['start' => $g[0], 'end' => $g[1], 'seconds' => $sec];
            }
        }

        $shiftSec = (int) ($shift['seconds'] ?? 0);

        return $row + [
            'busy_seconds'      => $busyIn,
            'outside_seconds'   => $outside,
            'overlap_seconds'   => max(0, $rawSum - $unionSec),
            'downtime_seconds'  => $hentiSec,
            'gap_seconds'       => array_sum(array_column($gaps, 'seconds')),
            'gaps'              => $gaps,
            'shift_seconds'     => $shiftSec,
            'utilization'       => $shiftSec > 0 ? round($busyIn / $shiftSec * 100, 1) : null,
            'has_open_timer'    => (bool) collect($row['blocks'])->contains('still_open', true),
            'block_count'       => count($row['blocks']),
        ];
    }

    protected function withDeptStats(array $dept): array
    {
        $slots = array_filter($dept['rows'], fn ($r) => $r['counts_as_slot']);

        return $dept + [
            'slot_count'       => count($slots),
            'busy_seconds'     => (int) array_sum(array_column($slots, 'busy_seconds')),
            'gap_seconds'      => (int) array_sum(array_column($slots, 'gap_seconds')),
            'outside_seconds'  => (int) array_sum(array_column($slots, 'outside_seconds')),
            'downtime_seconds' => (int) array_sum(array_column($slots, 'downtime_seconds')),
            'capacity_seconds' => (int) array_sum(array_column($slots, 'shift_seconds')),
        ] + $this->orphanStats($dept['rows']);
    }

    /**
     * Kerja yang tercatat HANYA atas nama operator, tanpa mesin mana pun.
     *
     * Ini bukan kerusakan hitungan, tapi kerusakan pencatatan: mesinnya jelas jalan, cuma
     * tidak dicentang saat memulai langkah. Akibatnya baris mesin tampak nganggur seharian
     * padahal tidak. Wajib dimunculkan, karena kalau tidak, kalender ini justru jadi bukti
     * palsu bahwa kapasitas mesin banyak menganggur.
     *
     * @param  array<int,array>  $rows
     * @return array{orphan_seconds: int, orphan_steps: int}
     */
    protected function orphanStats(array $rows): array
    {
        $onSlot = [];
        foreach ($rows as $row) {
            if ($row['counts_as_slot']) {
                foreach ($row['blocks'] as $b) {
                    $onSlot[$b['step_id']] = true;
                }
            }
        }

        $seen = [];
        foreach ($rows as $row) {
            if ($row['counts_as_slot']) {
                continue;
            }
            foreach ($row['blocks'] as $b) {
                if (isset($onSlot[$b['step_id']])) {
                    continue;
                }
                // Satu blok bisa tampil di beberapa baris bukan-slot; kunci per langkah+mulai.
                $seen[$b['step_id'] . '|' . $b['start']->timestamp] = $b;
            }
        }

        return [
            'orphan_seconds' => (int) array_sum(array_column($seen, 'seconds')),
            'orphan_steps'   => count(array_unique(array_column($seen, 'step_id'))),
        ];
    }

    protected function grandTotals(array $departments): array
    {
        $busy = $cap = $gap = $out = $slots = $orphanSec = $orphanSteps = $henti = 0;
        foreach ($departments as $d) {
            $busy        += $d['busy_seconds'];
            $cap         += $d['capacity_seconds'];
            $gap         += $d['gap_seconds'];
            $out         += $d['outside_seconds'];
            $henti       += $d['downtime_seconds'];
            $slots       += $d['slot_count'];
            $orphanSec   += $d['orphan_seconds'];
            $orphanSteps += $d['orphan_steps'];
        }

        return [
            'slot_count'       => $slots,
            'busy_seconds'     => $busy,
            'capacity_seconds' => $cap,
            'gap_seconds'      => $gap,
            'outside_seconds'  => $out,
            'downtime_seconds' => $henti,
            'orphan_seconds'   => $orphanSec,
            'orphan_steps'     => $orphanSteps,
            'utilization'      => $cap > 0 ? round($busy / $cap * 100, 1) : null,
        ];
    }

    /** Segmen jam kerja shift: sebelum istirahat + sesudah istirahat. */
    protected function workSegments(array $shift): array
    {
        if (($shift['is_off'] ?? true) || !$shift['start'] || !$shift['end']) {
            return [];
        }

        $bs = $shift['break_start'];
        $be = $shift['break_end'];

        if (!$bs || !$be || $be->lte($bs) || $bs->lte($shift['start']) || $be->gte($shift['end'])) {
            return [[$shift['start']->copy(), $shift['end']->copy()]];
        }

        return [
            [$shift['start']->copy(), $bs->copy()],
            [$be->copy(), $shift['end']->copy()],
        ];
    }

    // ── Aljabar interval ──────────────────────────────────────────────────────────

    /** @param array<int,array{0:Carbon,1:Carbon}> $ranges */
    protected function merge(array $ranges): array
    {
        if (empty($ranges)) {
            return [];
        }

        usort($ranges, fn ($a, $b) => $a[0]->timestamp <=> $b[0]->timestamp);

        $out = [];
        $cur = [$ranges[0][0]->copy(), $ranges[0][1]->copy()];
        foreach (array_slice($ranges, 1) as $r) {
            if ($r[0]->lte($cur[1])) {
                if ($r[1]->gt($cur[1])) {
                    $cur[1] = $r[1]->copy();
                }
            } else {
                $out[] = $cur;
                $cur   = [$r[0]->copy(), $r[1]->copy()];
            }
        }
        $out[] = $cur;

        return $out;
    }

    protected function intersect(array $a, array $b): array
    {
        $out = [];
        foreach ($a as $x) {
            foreach ($b as $y) {
                $s = $x[0]->gt($y[0]) ? $x[0] : $y[0];
                $e = $x[1]->lt($y[1]) ? $x[1] : $y[1];
                if ($e->gt($s)) {
                    $out[] = [$s->copy(), $e->copy()];
                }
            }
        }

        return $this->merge($out);
    }

    /** $base dikurangi $cut. */
    protected function subtract(array $base, array $cut): array
    {
        $cut = $this->merge($cut);
        $out = [];

        foreach ($base as $seg) {
            $cursor = $seg[0]->copy();
            foreach ($cut as $c) {
                if ($c[1]->lte($cursor) || $c[0]->gte($seg[1])) {
                    continue;
                }
                if ($c[0]->gt($cursor)) {
                    $out[] = [$cursor->copy(), $c[0]->copy()];
                }
                if ($c[1]->gt($cursor)) {
                    $cursor = $c[1]->copy();
                }
            }
            if ($cursor->lt($seg[1])) {
                $out[] = [$cursor->copy(), $seg[1]->copy()];
            }
        }

        return $out;
    }

    // ── Jendela tampilan ──────────────────────────────────────────────────────────

    /**
     * Jendela dibulatkan ke jam penuh dan SELALU melebar untuk memuat kerja di luar shift —
     * kalau mesin jalan sampai jam 17:20, itu harus terlihat, bukan terpotong sunyi.
     */
    protected function window(Carbon $date, array $departments): array
    {
        $min = $this->at($date, self::FALLBACK_START);
        $max = $this->at($date, self::FALLBACK_END);

        foreach ($departments as $dept) {
            foreach ($dept['rows'] as $row) {
                $shift = $row['shift'];
                if (!($shift['is_off'] ?? true) && $shift['start']) {
                    $min = $shift['start']->lt($min) ? $shift['start']->copy() : $min;
                    $max = $shift['end']->gt($max)   ? $shift['end']->copy()   : $max;
                }
                foreach ($row['blocks'] as $b) {
                    $min = $b['start']->lt($min) ? $b['start']->copy() : $min;
                    $max = $b['end']->gt($max)   ? $b['end']->copy()   : $max;
                }
            }
        }

        $min = $min->copy()->startOfHour();

        $ceil = $max->copy()->startOfHour();
        if ($ceil->lt($max)) {
            $ceil->addHour();
        }

        return [$min, $ceil];
    }
}
