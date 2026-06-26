<?php

namespace App\Modules\SDM\Controllers;

use App\Core\Accounting\Account;
use App\Modules\SDM\Models\Attendance;
use App\Modules\SDM\Models\AttendanceOverride;
use App\Modules\SDM\Models\Karyawan;
use App\Modules\SDM\Models\KebijakanKolom;
use App\Modules\SDM\Models\KebijakanSummary;
use App\Modules\SDM\Models\KebijakanSummaryValue;
use App\Modules\SDM\Models\NationalHoliday;
use App\Modules\SDM\Models\PeriodePenggajian;
use App\Modules\SDM\Models\SlipGaji;
use App\Modules\SDM\Services\PayrollBreakdownService;
use App\Modules\SDM\Models\PayrollSetting;
use App\Modules\SDM\Services\AttendanceImportService;
use App\Modules\SDM\Services\KebijakanRuleEngine;
use App\Modules\SDM\Services\PayrollCalculationService;
use App\Modules\SDM\Services\PayrollTaxBpjsCalculator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AttendanceController extends Controller
{
    public function index(int $periodeId, Request $request)
    {
        $periode = PeriodePenggajian::findOrFail($periodeId);

        $query = Attendance::with('karyawan')->where('periode_id', $periodeId);
        if ($request->filled('karyawan_id')) {
            $query->where('karyawan_id', $request->karyawan_id);
        }
        $attendances = $query->orderBy('karyawan_id')->orderBy('tanggal')->paginate(50)->withQueryString();

        $karyawans = Karyawan::whereIn('id',
            Attendance::where('periode_id', $periodeId)->distinct()->pluck('karyawan_id')
        )->orderBy('name')->get();

        return view('erp.sdm.attendance.index', compact('periode', 'attendances', 'karyawans'));
    }

    /**
     * Dashboard absensi per karyawan per bulan.
     */
    public function dashboard(Request $request, KebijakanRuleEngine $engine)
    {
        $karyawans = Karyawan::active()->orderBy('name')->get();

        $now    = now();
        $bulan  = (int) $request->input('bulan', $now->month);
        $tahun  = (int) $request->input('tahun', $now->year);
        $karyawanId = (int) $request->input('karyawan_id', $karyawans->first()?->id ?? 0);

        // Pastikan periode bulan berjalan selalu ada → jadi default & muncul di pemilih Periode.
        app(\App\Modules\SDM\Services\PeriodePenggajianService::class)
            ->ensureForMonth((int) $now->month, (int) $now->year);

        $karyawan = $karyawans->firstWhere('id', $karyawanId) ?? $karyawans->first();

        $startDate = Carbon::create($tahun, $bulan, 1)->startOfDay();
        $endDate   = $startDate->copy()->endOfMonth();

        $periode = PeriodePenggajian::where('bulan', $bulan)
            ->where('tahun', $tahun)
            ->first();

        $holidayDates = NationalHoliday::whereBetween('tanggal', [$startDate->toDateString(), $endDate->toDateString()])
            ->pluck('tanggal')
            ->map(fn($d) => Carbon::parse($d)->toDateString())
            ->all();

        $kolomDinamis = KebijakanKolom::aktif()->ordered()->get();

        $rows           = [];
        $hariKerja      = 0;
        $gajiPerHari    = 0;
        $lemburPerJam   = 0;
        $totalLembur    = 0;
        $totalGajiHari  = 0;
        $totalLemburJam = 0;
        $totalKolom     = [];
        foreach ($kolomDinamis as $kol) {
            $totalKolom[$kol->key] = 0.0;
        }

        if ($karyawan) {
            $schedule = $karyawan->schedules->keyBy('day_of_week');

            $attendances = Attendance::when($periode, fn($q) => $q->where('periode_id', $periode->id))
                ->where('karyawan_id', $karyawan->id)
                ->whereBetween('tanggal', [$startDate->toDateString(), $endDate->toDateString()])
                ->get()
                ->keyBy(fn($a) => Carbon::parse($a->tanggal)->toDateString());

            // Load overrides: include yang tanggal-nya ATAU paired_date-nya di range, lalu map ke kedua tanggal
            $overridesRaw = AttendanceOverride::where('karyawan_id', $karyawan->id)
                ->where(function ($q) use ($startDate, $endDate) {
                    $q->whereBetween('tanggal', [$startDate->toDateString(), $endDate->toDateString()])
                      ->orWhereBetween('paired_date', [$startDate->toDateString(), $endDate->toDateString()]);
                })
                ->get();

            $overridesByDate = [];
            foreach ($overridesRaw as $o) {
                $tStr = Carbon::parse($o->tanggal)->toDateString();
                $overridesByDate[$tStr][] = $o;
                if ($o->paired_date) {
                    $pStr = Carbon::parse($o->paired_date)->toDateString();
                    $overridesByDate[$pStr][] = $o;
                }
            }

            $cursor = $startDate->copy();
            while ($cursor->lte($endDate)) {
                $dateStr  = $cursor->toDateString();
                $dow      = (int) $cursor->dayOfWeek;
                $sched    = $schedule[$dow] ?? null;
                $isOff    = $sched ? (bool) $sched->is_off : ($dow === 0);
                $isHoli   = in_array($dateStr, $holidayDates, true);
                $isWorkDay= ! $isOff && ! $isHoli;
                if ($isWorkDay) $hariKerja++;

                $rows[] = [
                    'date'        => $cursor->copy(),
                    'is_off'      => $isOff,
                    'is_holiday'  => $isHoli,
                    'is_work_day' => $isWorkDay,
                    'schedule'    => $sched,
                    'attendance'  => $attendances[$dateStr] ?? null,
                    'overrides'   => collect($overridesByDate[$dateStr] ?? []),
                ];

                $cursor->addDay();
            }

            $gajiPerHari  = $hariKerja > 0 ? round((float) $karyawan->gaji_pokok / $hariKerja) : 0;
            $lemburPerJam = round($gajiPerHari / 7 * 2);

            foreach ($rows as &$row) {
                $att      = $row['attendance'];
                $sched    = $row['schedule'];

                $regIn    = $att?->on_work1 ? substr($att->on_work1, 0, 5) : null;
                $regOut   = $att?->off_work1 ? substr($att->off_work1, 0, 5) : null;
                $otIn     = $att?->on_work2 ? substr($att->on_work2, 0, 5) : null;
                $otOut    = $att?->off_work2 ? substr($att->off_work2, 0, 5) : null;

                $schedRegIn   = $sched?->jam_masuk ? substr($sched->jam_masuk, 0, 5) : null;
                $schedRegOut  = $sched?->jam_pulang ? substr($sched->jam_pulang, 0, 5) : null;
                $schedOtIn    = $sched?->jam_masuk_lembur ? substr($sched->jam_masuk_lembur, 0, 5) : null;
                $schedOtOut   = $sched?->jam_pulang_lembur ? substr($sched->jam_pulang_lembur, 0, 5) : null;

                $status   = $this->resolveStatus($row, $regIn, $regOut);
                $lemburJ  = $this->resolveLemburJam($row, $status, $att, $otOut, $regOut);

                $row['reg_datang']        = $regIn;
                $row['reg_pulang']        = $regOut;
                $row['lembur_datang']     = $otIn;
                $row['lembur_pulang']     = $otOut;
                $row['sched_reg_datang']  = $schedRegIn;
                $row['sched_reg_pulang']  = $schedRegOut;
                $row['sched_lembur_datang'] = $schedOtIn;
                $row['sched_lembur_pulang']= $schedOtOut;
                $row['status']    = $status;
                $row['lembur_jam']= $lemburJ;
                $row['gaji_pokok']= $this->paysFullDay($status)
                    ? $gajiPerHari
                    : ($this->paysHalfDay($status) ? (int) round($gajiPerHari / 2) : 0);
                $row['lembur_rp'] = $lemburJ * $lemburPerJam;

                // Engine kebijakan menghitung kolom-kolom dinamis (tunjangan, bonus, custom, flag)
                $row['kolom_values'] = $engine->applyRow($row, $karyawan, (float) $gajiPerHari);

                foreach ($kolomDinamis as $kol) {
                    $v = $row['kolom_values'][$kol->key] ?? null;
                    if ($kol->tipe === 'flag') {
                        continue;
                    }
                    if (is_numeric($v)) {
                        $totalKolom[$kol->key] += (float) $v;
                    }
                }

                $totalGajiHari  += $row['gaji_pokok'];
                $totalLembur    += $row['lembur_rp'];
                $totalLemburJam += $lemburJ;
            }
            unset($row);
        }

        // Pemilih Periode: hanya periode yang sudah ada (terbaru dulu). Bulan berjalan
        // dipastikan ada di atas, jadi selalu tampil sebagai default.
        $bulanOptions = PeriodePenggajian::orderByDesc('tahun')->orderByDesc('bulan')
            ->get(['bulan', 'tahun', 'label'])
            ->map(fn ($p) => [
                'bulan' => (int) $p->bulan,
                'tahun' => (int) $p->tahun,
                'label' => $p->label,
            ])->all();

        // === Baris Summary (per-bulan-per-karyawan) ===
        $summaryRows   = KebijakanSummary::aktif()->ordered()->get();
        $summaryValue  = [];   // key => nilai final (numeric)
        $summaryShow   = [];   // key => bool apakah baris perlu ditampilkan di summary box
        $slipNotes     = null;
        $slip          = null;

        if ($karyawan) {
            $autoValues = $engine->applySummaryAuto($karyawan, (float) $gajiPerHari);
            foreach ($summaryRows as $s) {
                if ($s->key === KebijakanSummary::KEY_TOTAL) continue;

                if ($s->mode === 'auto') {
                    $summaryValue[$s->key] = (float) ($autoValues[$s->key] ?? 0);
                    $summaryShow[$s->key]  = true;
                    continue;
                }

                $scope = $s->scope ?? 'all';
                $recur = $s->recurrence ?? 'monthly';

                if ($scope === 'all' && $recur === 'monthly') {
                    $summaryValue[$s->key] = (float) $s->nominal_manual;
                    $summaryShow[$s->key]  = true;
                } else {
                    // Lookup per-instance value
                    $q = KebijakanSummaryValue::where('summary_id', $s->id);
                    $scope === 'per_karyawan'
                        ? $q->where('karyawan_id', $karyawan->id)
                        : $q->whereNull('karyawan_id');
                    if ($recur === 'one_time') {
                        $q->where('bulan', $bulan)->where('tahun', $tahun);
                    } else {
                        $q->whereNull('bulan')->whereNull('tahun');
                    }
                    $row = $q->first();

                    $summaryValue[$s->key] = $row ? (float) $row->nominal : 0.0;
                    // one_time: hanya tampil jika sudah ada nilai (sesuai keputusan UX user)
                    // monthly per-karyawan: selalu tampil supaya bisa diisi
                    $summaryShow[$s->key] = $recur === 'one_time' ? (bool) $row : true;
                }
            }

            $slip = $periode
                ? SlipGaji::where('periode_id', $periode->id)->where('karyawan_id', $karyawan->id)->first()
                : null;
            $slipNotes = $slip?->notes;
        }

        // Hitung Total Dibayarkan = ∑ kolom + ∑ summary plus − ∑ summary minus + gaji_pokok + lembur
        $totalSemuaKolom    = array_sum(array_filter($totalKolom, 'is_numeric'));
        $totalSummaryPlus   = 0;
        $totalSummaryMinus  = 0;
        foreach ($summaryRows as $s) {
            if ($s->key === KebijakanSummary::KEY_TOTAL) continue;
            $v = (float) ($summaryValue[$s->key] ?? 0);
            if ($s->arah === 'plus') $totalSummaryPlus += $v;
            else                     $totalSummaryMinus += $v;
        }
        $brutoSebelumPotongan = $totalGajiHari + $totalLembur + $totalSemuaKolom + $totalSummaryPlus - $totalSummaryMinus;

        // === Potongan BPJS & PPh 21 (rate-based dari Pengaturan) ===
        $payrollSetting = PayrollSetting::singleton();
        $taxBpjs        = null;
        if ($karyawan) {
            $calc    = new PayrollTaxBpjsCalculator($payrollSetting);
            $taxBpjs = $calc->compute($karyawan, $brutoSebelumPotongan);
        }
        $potonganTax = $taxBpjs ? (float) $taxBpjs['total_potongan'] : 0.0;

        $totalDibayarkan = $brutoSebelumPotongan - $potonganTax;
        $summaryValue[KebijakanSummary::KEY_TOTAL] = $totalDibayarkan;

        // Sudah dibayar? (untuk hide tombol Bayar)
        $existingPayment = null;
        if ($karyawan) {
            $existingPayment = \App\Modules\SDM\Models\SalaryPayment::where('karyawan_id', $karyawan->id)
                ->where('periode_bulan', $bulan)
                ->where('periode_tahun', $tahun)
                ->whereIn('status', ['draft', 'posted'])
                ->first();
        }

        $cashAccounts = Account::whereIn('account_category', ['cash', 'cash_equivalent'])
            ->where('is_active', 1)->orderBy('code')->get(['id', 'code', 'name']);
        $expenseAccounts = Account::where('type', 'expense')
            ->where('is_active', 1)->orderBy('code')->get(['id', 'code', 'name']);
        $defaultAdminFeeAccountId = Account::where('account_category', 'bank_admin_fee')
            ->where('is_active', 1)->value('id');

        return view('erp.sdm.absensi.dashboard', compact(
            'karyawans', 'karyawan', 'bulan', 'tahun', 'bulanOptions',
            'rows', 'hariKerja', 'gajiPerHari', 'lemburPerJam',
            'totalGajiHari', 'totalLembur', 'totalLemburJam',
            'kolomDinamis', 'totalKolom',
            'summaryRows', 'summaryValue', 'summaryShow', 'totalDibayarkan', 'slipNotes',
            'periode', 'taxBpjs', 'payrollSetting', 'brutoSebelumPotongan', 'slip',
            'cashAccounts', 'expenseAccounts', 'defaultAdminFeeAccountId', 'existingPayment'
        ));
    }

    protected function resolveStatus(array $row, ?string $on1, ?string $off1): ?string
    {
        // Sumber tunggal: AttendanceStatusResolver (dipakai juga PWA karyawan)
        return app(\App\Modules\SDM\Services\AttendanceStatusResolver::class)
            ->resolve($row, $on1, $off1);
    }

    protected function resolveLemburJam(array $row, ?string $status, ?Attendance $att, ?string $otOut, ?string $regOut): float
    {
        // Sumber tunggal: pakai logika di PayrollBreakdownService (lembur hari kerja =
        // jam_masuk_lembur s/d scan keluar lembur − istirahat lembur) agar dashboard,
        // slip show & slip cetak konsisten.
        return PayrollBreakdownService::resolveLemburJam($row, $status, $att, $otOut, $regOut);
    }

    protected function paysFullDay(?string $status): bool
    {
        // Status 'lembur' = kerja di hari libur/off → hanya dapat uang lembur, tanpa gaji harian.
        return in_array($status, ['hadir', 'terlambat', 'pulang_awal', 'cuti', 'sakit'], true);
    }

    protected function paysHalfDay(?string $status): bool
    {
        return $status === 'setengah_hari';
    }

    protected function toMinutes(string $hhmm): int
    {
        [$h, $m] = array_pad(explode(':', $hhmm), 2, 0);
        return ((int) $h) * 60 + (int) $m;
    }

    protected function hasFullDaySwap(array $row): bool
    {
        $overrides = $row['overrides'] ?? collect();
        return $overrides->contains(fn($o) => $o->type === 'tukar_hari');
    }

    protected function hasHalfDaySwap(array $row): bool
    {
        $overrides = $row['overrides'] ?? collect();
        return $overrides->contains(fn($o) => $o->type === 'tukar_setengah_hari');
    }

    public function updateStatus(Request $request, AttendanceImportService $importer, PayrollCalculationService $payroll)
    {
        $data = $request->validate([
            'karyawan_id' => 'required|integer|exists:sdm_karyawan,id',
            'tanggal'     => 'required|date',
            'status'      => 'nullable|in:hadir,terlambat,setengah_hari,pulang_awal,tidak_hadir,libur,cuti,sakit,lembur,lembur_setengah_hari',
        ]);

        $tanggal = Carbon::parse($data['tanggal']);
        $bulan = $tanggal->month;
        $tahun = $tanggal->year;

        // Reset (status kosong) — cukup unset edited_manually di row existing, tidak perlu buat row/periode baru
        if (empty($data['status'])) {
            $periode = PeriodePenggajian::where('bulan', $bulan)->where('tahun', $tahun)->first();
            if ($periode) {
                $att = Attendance::where('periode_id', $periode->id)
                    ->where('karyawan_id', $data['karyawan_id'])
                    ->whereDate('tanggal', $tanggal->toDateString())
                    ->first();
                if ($att) {
                    $att->edited_manually = false;
                    $att->save();

                    $slip = SlipGaji::where('periode_id', $periode->id)
                        ->where('karyawan_id', $data['karyawan_id'])
                        ->first();
                    if ($slip && ! $slip->isFinalized()) {
                        $payroll->regenerateSlip($slip);
                    }
                }
            }
            return back()->with('success', 'Manual status dihapus, kembali ke auto.');
        }

        $periode = $this->ensurePeriode($bulan, $tahun);

        $att = Attendance::firstOrNew([
            'periode_id'  => $periode->id,
            'karyawan_id' => $data['karyawan_id'],
            'tanggal'     => $tanggal->toDateString(),
        ]);
        $att->week   = $att->week ?: $tanggal->format('D');
        $att->status = $data['status'];

        $flags = $importer->applyPayFlags($data['status'], $att->on_work1);
        $att->get_full_pay    = $flags['full'];
        $att->get_half_pay    = $flags['half'];
        $att->get_tunjangan   = $flags['tunj'];
        $att->get_bonus_absen = $flags['bonus_absen'];
        $att->edited_manually = true;
        $att->save();

        $slip = SlipGaji::where('periode_id', $periode->id)
            ->where('karyawan_id', $data['karyawan_id'])
            ->first();
        if ($slip && ! $slip->isFinalized()) {
            $payroll->regenerateSlip($slip);
        }

        return back()->with('success', 'Status diperbarui.');
    }

    public function saveNotes(Request $request)
    {
        $data = $request->validate([
            'karyawan_id' => 'required|integer|exists:sdm_karyawan,id',
            'bulan'       => 'required|integer|min:1|max:12',
            'tahun'       => 'required|integer|min:2020|max:2100',
            'notes'       => 'nullable|string',
        ]);

        $periode = PeriodePenggajian::where('bulan', $data['bulan'])
            ->where('tahun', $data['tahun'])->first();
        if (! $periode) {
            return back()->with('error', 'Periode belum dibuat — upload absensi dulu.');
        }

        $slip = SlipGaji::where('periode_id', $periode->id)
            ->where('karyawan_id', $data['karyawan_id'])->first();
        if (! $slip) {
            return back()->with('error', 'Slip belum tersedia — upload absensi dulu untuk membuat slip.');
        }
        if ($slip->isFinalized()) {
            return back()->with('error', 'Slip sudah finalized, catatan tidak bisa diubah.');
        }

        $slip->notes = $data['notes'];
        $slip->save();

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'notes' => $slip->notes]);
        }
        return back()->with('success', 'Catatan disimpan.');
    }

    public function uploadExcel(Request $request, AttendanceImportService $service)
    {
        $data = $request->validate([
            'file'        => 'required|file|mimes:xlsx,xls|max:10240',
            'bulan'       => 'required|integer|min:1|max:12',
            'tahun'       => 'required|integer|min:2020|max:2100',
            'karyawan_id' => 'nullable|integer',
        ]);

        $bulan = (int) $data['bulan'];
        $tahun = (int) $data['tahun'];

        // bulan/tahun = petunjuk parse tanggal ambigu saja. Tiap baris Excel
        // dirutekan ke periode sesuai tanggalnya (auto-buat) di dalam service.
        $result = $service->import($request->file('file'), $bulan, $tahun);

        $msg = "Upload selesai: {$result['imported']} baris diisi, {$result['skipped']} di-skip.";
        if (! empty($result['errors'])) {
            $msg .= ' Catatan: ' . implode(' | ', array_slice($result['errors'], 0, 5));
            if (count($result['errors']) > 5) {
                $msg .= ' (+' . (count($result['errors']) - 5) . ' lainnya)';
            }
        }

        return redirect()->route('sdm.absensi.index', [
            'bulan' => $bulan, 'tahun' => $tahun,
            'karyawan_id' => $data['karyawan_id'] ?? null,
        ])->with('success', $msg);
    }

    protected function ensurePeriode(int $bulan, int $tahun): PeriodePenggajian
    {
        // Satu sumber pembuatan periode (sama dengan cron & tombol manual).
        return app(\App\Modules\SDM\Services\PeriodePenggajianService::class)
            ->ensureForMonth($bulan, $tahun);
    }

    public function edit(int $periodeId, int $id)
    {
        $att = Attendance::with('karyawan')->where('periode_id', $periodeId)->findOrFail($id);
        return view('erp.sdm.attendance.edit', compact('att'));
    }

    public function update(Request $request, int $id, AttendanceImportService $importer, PayrollCalculationService $payroll, \App\Modules\SDM\Services\AttendanceStatusResolver $statusResolver)
    {
        $att = Attendance::findOrFail($id);

        $data = $request->validate([
            'on_work1'  => 'nullable|date_format:H:i',
            'off_work1' => 'nullable|date_format:H:i',
            'on_work2'  => 'nullable|date_format:H:i',
            'off_work2' => 'nullable|date_format:H:i',
            'on_work3'  => 'nullable|date_format:H:i',
            'off_work3' => 'nullable|date_format:H:i',
            'status'    => 'nullable|in:hadir,terlambat,setengah_hari,pulang_awal,tidak_hadir,libur,cuti,sakit',
            'remark'    => 'nullable|string',
        ]);

        foreach (['on_work1','off_work1','on_work2','off_work2','on_work3','off_work3'] as $f) {
            $att->{$f} = $data[$f] ? $data[$f] . ':00' : null;
        }
        $att->remark = $data['remark'] ?? null;

        // Fallback otomatis kalau HRD tak memilih status: ikut jadwal per-karyawan + override jam.
        $autoStatus = $statusResolver->autoStatus($att->karyawan, Carbon::parse($att->tanggal), $att->on_work1, $att->off_work1);
        $status = $data['status'] ?: $autoStatus;
        $att->status = $status;

        $overtimeAllowed = $att->karyawan->isOvertimeAllowedOn(\Carbon\Carbon::parse($att->tanggal));
        $att->overtime_hours = $importer->calculateOvertime($att->off_work2, $overtimeAllowed);

        $flags = $importer->applyPayFlags($status, $att->on_work1);
        $att->get_full_pay = $flags['full'];
        $att->get_half_pay = $flags['half'];
        $att->get_tunjangan = $flags['tunj'];
        $att->get_bonus_absen = $flags['bonus_absen'];
        $att->edited_manually = true;
        $att->save();

        $slip = SlipGaji::where('periode_id', $att->periode_id)
            ->where('karyawan_id', $att->karyawan_id)
            ->first();
        if ($slip && ! $slip->isFinalized()) {
            $payroll->regenerateSlip($slip);
        }

        return redirect(list_url('sdm.attendance.index', $att->periode_id))
            ->with('success', 'Kehadiran diperbarui.');
    }
}
