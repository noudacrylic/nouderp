<?php

namespace App\Modules\Analysis\Services;

use App\Modules\Analysis\Models\ProductionQuotaExcludedDate;
use App\Modules\Analysis\Models\ProductionQuotaSlot;
use App\Modules\Production\Models\Department;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Modules\Analysis\Support\AnalysisCache;

/**
 * Kuota Produksi — berapa slot-jam yang PABRIK PUNYA sebulan, dan berapa yang benar-benar terpakai.
 *
 * Satu baris = satu slot kapasitas: mesin di CNC, orang di Assembling. Biaya tenaga kerja sudah
 * ketangkap di Fixed Cost, jadi menambah operator CNC menambah biaya tanpa menambah slot —
 * yang membatasi di sana mesinnya. Di Assembling sebaliknya.
 *
 * Dua sisi yang sengaja dipisah, karena perannya berbeda:
 *
 *  - **Tersedia** (jadwal) → PEMBAGI HPP. Jam yang dibayar tetap dibayar walau menganggur;
 *    itulah arti fixed cost. Konsekuensinya biaya tidak terserap habis, dan sisanya muncul
 *    sebagai biaya kapasitas menganggur — ditampilkan, bukan disembunyikan ke dalam HPP.
 *  - **Terpakai** (kalender) → DIAGNOSIS. Melihat satu mesin cuma 74% adalah undangan untuk
 *    menyelidiki, bukan sesuatu yang otomatis menaikkan HPP.
 *
 * Asumsinya hanya menyentuh sisi TERSEDIA, dan hanya pada jamnya: jam/hari dan hari/bulan per
 * slot, plus slot pengandaian untuk "kalau beli mesin keempat". Waktu per unit diandaikan di
 * halaman Waktu Produksi — satu tuas per halaman, supaya tidak ada dua asumsi berebut arti.
 */
class ProductionQuotaService
{
    /** Panjang jendela pengamatan sisi "terpakai". */
    public const WINDOW_DAYS = 30;

    public function __construct(
        protected ProductionCalendarService $calendar,
        protected ProductionCostRateService $rateService,
        protected AnalysisCache $cache,
    ) {
    }

    /**
     * @return array{
     *   slots: array<int,array>, departments: array<int,array>, totals: array,
     *   cost: array, window: array, excluded: array, contaminated: array
     * }
     */
    public function build(array $filters = []): array
    {
        return $this->cache->remember('kuota.build', $filters, fn () => $this->hitung($filters));
    }

    protected function hitung(array $filters): array
    {
        [$from, $to] = $this->window($filters);

        $observed = $this->observe($from, $to);
        $slots    = $this->slots($observed['per_executor'], $filters);

        return [
            'slots'        => $slots,
            'departments'  => $this->perDepartment($slots),
            'totals'       => $this->totals($slots),
            'cost'         => $this->cost($slots, $filters),
            'window'       => ['from' => $from, 'to' => $to, 'days' => $observed['days']],
            'excluded'     => $observed['excluded'],
            'contaminated' => $observed['contaminated'],
        ];
    }

    /**
     * Kapasitas efektif per divisi dalam sehari — dipakai halaman Waktu Produksi untuk mengubah
     * "detik per unit" jadi "berapa unit sehari".
     *
     * Sengaja lewat build() yang sama, bukan hitungan sendiri: kalau suatu hari asumsi jam atau
     * slot pengandaian berubah, kapasitas di Waktu Produksi harus ikut berubah pada saat yang
     * sama. Dua tempat menghitung hal yang sama adalah dua tempat untuk berbeda pendapat.
     *
     * @return array<int,array{slot_count:int, hours_per_day:float, seconds_per_day:float, working_days:float}>
     */
    public function capacityPerDay(array $filters = []): array
    {
        $out = [];
        foreach ($this->build($filters)['slots'] as $s) {
            if ($s['hours_per_day'] <= 0) {
                continue;
            }
            $d = $s['department_id'];
            $out[$d]['slot_count']    = ($out[$d]['slot_count'] ?? 0) + 1;
            $out[$d]['hours_per_day'] = ($out[$d]['hours_per_day'] ?? 0) + $s['hours_per_day'];
            $out[$d]['working_days']  = max($out[$d]['working_days'] ?? 0, $s['working_days']);
        }

        foreach ($out as &$d) {
            $d['seconds_per_day'] = $d['hours_per_day'] * 3600;
        }

        return $out;
    }

    // ── Sisi TERPAKAI: baca kalender ──────────────────────────────────────────────

    /**
     * Rata-ratakan pemakaian nyata sepanjang jendela, hari kerja saja.
     *
     * Dua jenis hari dibuang, dan keduanya dilaporkan supaya tidak ada yang dibuang diam-diam:
     *  - hari yang terdaftar rusak (produksi jalan tapi tidak terekam sama sekali);
     *  - hari yang tercemar timer belum ditutup — bloknya membentang sepanjang hari dan akan
     *    membuat slot yang menganggur tampak sibuk 100%.
     */
    protected function observe(Carbon $from, Carbon $to): array
    {
        // Satu kalender per hari selama sejendela penuh: borong dulu data yang bisa
        // diborong, supaya yang tersisa per hari hanya yang memang berat (log timer).
        $this->calendar->hangatkan($from, $to);

        $excludedRows = ProductionQuotaExcludedDate::orderBy('tanggal')->get()
            ->keyBy(fn ($r) => $r->tanggal->toDateString());

        $per = [];
        $days = 0;
        $excluded = [];
        $contaminated = [];

        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $tgl = $d->toDateString();

            if ($row = ($excludedRows[$tgl] ?? null)) {
                $excluded[] = ['tanggal' => $d->copy(), 'reason' => $row->reason, 'id' => $row->id];
                continue;
            }

            $hari = $this->calendar->build($d->copy());

            // Hari tanpa kapasitas sama sekali = Minggu / tanggal merah. Bukan hari kerja,
            // jadi tidak boleh ikut membagi rata-rata.
            if (($hari['totals']['capacity_seconds'] ?? 0) <= 0) {
                continue;
            }

            $terbuka = $this->openTimers($hari);
            if (!empty($terbuka)) {
                $contaminated[] = ['tanggal' => $d->copy(), 'slots' => $terbuka];
                continue;
            }

            $days++;
            foreach ($hari['departments'] as $dept) {
                foreach ($dept['rows'] as $row2) {
                    if (!$row2['counts_as_slot'] || !$row2['executor_id']) {
                        continue;
                    }
                    $id = $row2['executor_id'];
                    $per[$id]['available'] = ($per[$id]['available'] ?? 0) + $row2['shift_seconds'];
                    $per[$id]['used']      = ($per[$id]['used'] ?? 0) + $row2['busy_seconds'];
                    $per[$id]['gap']       = ($per[$id]['gap'] ?? 0) + $row2['gap_seconds'];
                    $per[$id]['downtime']  = ($per[$id]['downtime'] ?? 0) + $row2['downtime_seconds'];
                }
            }
        }

        return [
            'per_executor' => array_map(fn ($v) => array_map(fn ($s) => $days > 0 ? $s / $days : 0, $v), $per),
            'days'         => $days,
            'excluded'     => $excluded,
            'contaminated' => $contaminated,
        ];
    }

    /** @return array<int,string> nama slot yang timernya masih menggantung pada hari itu */
    protected function openTimers(array $hari): array
    {
        $out = [];
        foreach ($hari['departments'] as $dept) {
            foreach ($dept['rows'] as $row) {
                if ($row['has_open_timer']) {
                    $out[] = $row['name'];
                }
            }
        }

        return $out;
    }

    // ── Sisi TERSEDIA: jadwal + asumsi ────────────────────────────────────────────

    /**
     * Satu baris per slot: angka nyata dari jadwal & kalender, angka asumsi di sebelahnya, dan
     * angka yang benar-benar dipakai menghitung.
     */
    protected function slots(array $observed, array $filters): array
    {
        $workingDays = $this->rateService->workingDaysPerMonth($filters);
        $asumsi      = ProductionQuotaSlot::get();
        $perExecutor = $asumsi->whereNotNull('executor_id')->keyBy('executor_id');

        $out = [];

        foreach ($this->leafExecutors() as $ex) {
            $obs = $observed[$ex->id] ?? [];

            // Jam/hari nyata diambil dari kalender (sudah menghormati tukar hari & cuti); kalau
            // slot itu belum pernah muncul di jendela pengamatan, jatuh ke jadwal kontraknya.
            $availPerDay = ($obs['available'] ?? 0) / 3600;
            $usedPerDay  = ($obs['used'] ?? 0) / 3600;
            $days        = (float) ($workingDays[$ex->department_id] ?? 0);

            if ($availPerDay <= 0) {
                $availPerDay = $this->scheduleHours($ex);
            }

            $a     = $perExecutor[$ex->id] ?? null;
            $pakai = (bool) $a?->use_assumption;

            $jam  = $pakai && $a->assumed_hours_per_day !== null ? $a->assumed_hours_per_day : $availPerDay;
            $hari = $pakai && $a->assumed_working_days !== null ? $a->assumed_working_days : $days;

            $out[] = [
                'key'                    => 'e' . $ex->id,
                'executor_id'            => (int) $ex->id,
                'department_id'          => (int) $ex->department_id,
                'department'             => $ex->dept_name,
                'name'                   => $ex->name,
                'is_machine'             => $ex->karyawan_id === null,
                'is_virtual'             => false,

                'hours_per_day_real'     => round($availPerDay, 2),
                'working_days_real'      => round($days, 1),
                'used_per_day'           => round($usedPerDay, 2),
                'gap_per_day'            => round(($obs['gap'] ?? 0) / 3600, 2),
                'downtime_per_day'       => round(($obs['downtime'] ?? 0) / 3600, 2),
                'utilization'            => $availPerDay > 0 ? round($usedPerDay / $availPerDay * 100, 1) : null,

                'assumed_hours_per_day'  => $a?->assumed_hours_per_day,
                'assumed_working_days'   => $a?->assumed_working_days,
                'use_assumption'         => $pakai,

                'hours_per_day'          => round($jam, 2),
                'working_days'           => round($hari, 1),
                'available_month'        => $jam * $hari,
                'used_month'             => $usedPerDay * $days,
            ];
        }

        // Slot pengandaian — mesin/orang yang belum ada.
        foreach ($asumsi->whereNull('executor_id') as $v) {
            $days = (float) ($workingDays[$v->department_id] ?? 0);
            $jam  = $v->assumed_hours_per_day ?? 0;
            $hari = $v->assumed_working_days ?? $days;

            $out[] = [
                'key'                    => 'v' . $v->id,
                'virtual_id'             => $v->id,
                'executor_id'            => null,
                'department_id'          => (int) $v->department_id,
                'department'             => Department::find($v->department_id)?->name ?? '—',
                'name'                   => $v->label ?: 'Slot baru',
                'is_machine'             => true,
                'is_virtual'             => true,

                'hours_per_day_real'     => 0.0,
                'working_days_real'      => round($days, 1),
                'used_per_day'           => 0.0,
                'gap_per_day'            => 0.0,
                'downtime_per_day'       => 0.0,
                'utilization'            => null,

                'assumed_hours_per_day'  => $v->assumed_hours_per_day,
                'assumed_working_days'   => $v->assumed_working_days,
                'use_assumption'         => (bool) $v->use_assumption,

                'hours_per_day'          => $v->use_assumption ? round($jam, 2) : 0.0,
                'working_days'           => $v->use_assumption ? round($hari, 1) : 0.0,
                'available_month'        => $v->use_assumption ? $jam * $hari : 0.0,
                'used_month'             => 0.0,
            ];
        }

        usort($out, fn ($a, $b) => [$a['department'], $a['is_virtual'], $a['name']] <=> [$b['department'], $b['is_virtual'], $b['name']]);

        return $out;
    }

    /** Eksekutor DAUN yang aktif — operator penaung bukan slot, ia biaya. */
    public function leafExecutors()
    {
        $parents = DB::table('production_department_executors')
            ->whereNotNull('parent_executor_id')->distinct()->pluck('parent_executor_id')->all();

        return DB::table('production_department_executors as e')
            ->join('production_departments as d', 'd.id', '=', 'e.department_id')
            ->where('e.is_active', 1)->where('d.is_active', 1)->where('d.type', 'produksi')
            ->when(!empty($parents), fn ($q) => $q->whereNotIn('e.id', $parents))
            ->orderBy('d.name')->orderBy('e.name')
            ->select('e.id', 'e.name', 'e.department_id', 'e.karyawan_id', 'd.name as dept_name')
            ->get();
    }

    /** Jam kerja bersih sehari menurut jadwal kontrak — cadangan bila slot belum pernah terpakai. */
    protected function scheduleHours($ex): float
    {
        $karyawanId = $ex->karyawan_id ?: DB::table('production_department_executors')
            ->where('id', $ex->id)->value('parent_executor_id');

        $row = DB::table('sdm_karyawan_schedule')
            ->where('karyawan_id', $karyawanId)->where('is_off', 0)
            ->whereNotNull('jam_masuk')->first();

        if (!$row) {
            return 0.0;
        }

        $detik = strtotime($row->jam_pulang) - strtotime($row->jam_masuk);
        if ($row->jam_istirahat_start && $row->jam_istirahat_end) {
            $detik -= strtotime($row->jam_istirahat_end) - strtotime($row->jam_istirahat_start);
        }

        return max(0, $detik) / 3600;
    }

    // ── Ringkasan ─────────────────────────────────────────────────────────────────

    protected function perDepartment(array $slots): array
    {
        $out = [];
        foreach ($slots as $s) {
            $d = $s['department_id'];
            $out[$d]['name']            = $s['department'];
            $out[$d]['slot_count']      = ($out[$d]['slot_count'] ?? 0) + ($s['available_month'] > 0 ? 1 : 0);
            $out[$d]['available_month'] = ($out[$d]['available_month'] ?? 0) + $s['available_month'];
            $out[$d]['used_month']      = ($out[$d]['used_month'] ?? 0) + $s['used_month'];
        }

        foreach ($out as &$d) {
            $d['utilization'] = $d['available_month'] > 0 ? round($d['used_month'] / $d['available_month'] * 100, 1) : null;
        }

        return $out;
    }

    protected function totals(array $slots): array
    {
        $avail = array_sum(array_column($slots, 'available_month'));
        $used  = array_sum(array_column($slots, 'used_month'));

        return [
            'slot_count'      => count(array_filter($slots, fn ($s) => $s['available_month'] > 0)),
            'available_month' => $avail,
            'used_month'      => $used,
            'utilization'     => $avail > 0 ? round($used / $avail * 100, 1) : null,
            'has_assumption'  => (bool) collect($slots)->contains('use_assumption', true),
        ];
    }

    /**
     * Tarif fixed cost per slot-jam — inilah satu-satunya angka yang dipakai HPP dari halaman ini.
     *
     * Pembaginya jam TERSEDIA, bukan terpakai: jam yang dibayar tetap dibayar walau menganggur.
     * Akibatnya biaya tidak terserap habis, dan selisihnya dilaporkan apa adanya sebagai biaya
     * kapasitas menganggur. Ini angka ANALISA — tidak pernah masuk jurnal.
     */
    protected function cost(array $slots, array $filters): array
    {
        $biaya   = $this->rateService->build($filters);
        $semua   = (float) ($biaya['grand_total'] ?? 0);

        // Packing dikeluarkan dari tarif per jam: kerja membungkus mengikuti jumlah paket
        // yang keluar, bukan lamanya barang dibuat. Ia punya sukunya sendiri di HPP.
        $packing = (float) ($biaya['groups']['packing']['total'] ?? 0);
        $fixed   = $semua - $packing;

        $avail = array_sum(array_column($slots, 'available_month'));
        $used  = array_sum(array_column($slots, 'used_month'));

        $rate     = $avail > 0 ? $fixed / $avail : null;
        $terserap = $rate !== null ? $rate * $used : null;

        return [
            'grand_total'            => $semua,
            'packing_total'          => $packing,
            'packing_per_transaction'=> $biaya['groups']['packing']['allocation']['rate'] ?? null,
            'transactions_per_month' => $biaya['transactions_per_month'] ?? null,
            'fixed_total'            => $fixed,
            'available_hours'        => $avail,
            'rate_per_slot_hour'     => $rate,
            'absorbed'               => $terserap,
            'unabsorbed'             => $terserap !== null ? $fixed - $terserap : null,
            'unabsorbed_percent'     => ($terserap !== null && $fixed > 0) ? round(($fixed - $terserap) / $fixed * 100, 1) : null,
        ];
    }

    protected function window(array $filters): array
    {
        $to = !empty($filters['to']) ? Carbon::parse($filters['to'])->startOfDay() : Carbon::yesterday();
        $days = (int) ($filters['window_days'] ?? self::WINDOW_DAYS);

        return [$to->copy()->subDays(max(1, $days) - 1), $to];
    }
}
