<?php

namespace App\Modules\SDM\Services;

use App\Modules\SDM\Models\Attendance;
use App\Modules\SDM\Models\Karyawan;
use App\Modules\SDM\Models\Kasbon;
use App\Modules\SDM\Models\KebijakanKolom;
use App\Modules\SDM\Models\KebijakanSummary;
use App\Modules\SDM\Models\PayrollSetting;
use App\Modules\SDM\Models\PeriodePenggajian;
use Carbon\Carbon;

/**
 * Bangun preview gaji bulanan untuk dipakai di form Bayar Gaji (Kas Bank).
 * Hasil = struktur yang sesuai field SalaryPayment.
 */
class SummaryPreviewService
{
    public static function buildPayrollPreview(int $karyawanId, int $bulan, int $tahun): array
    {
        $karyawan = Karyawan::findOrFail($karyawanId);
        $engine   = app(KebijakanRuleEngine::class);

        $startDate = Carbon::create($tahun, $bulan, 1)->startOfDay();
        $endDate   = $startDate->copy()->endOfMonth();
        $periode   = PeriodePenggajian::where('bulan', $bulan)->where('tahun', $tahun)->first();

        // Hari kerja (hari per minggu dari schedule, dipotong libur nasional/mingguan)
        $schedule = $karyawan->schedules->keyBy('day_of_week');
        $holidayDates = \App\Modules\SDM\Models\NationalHoliday::whereBetween('tanggal', [$startDate->toDateString(), $endDate->toDateString()])
            ->pluck('tanggal')->map(fn($d) => Carbon::parse($d)->toDateString())->all();

        $hariKerja = 0;
        $cursor = $startDate->copy();
        while ($cursor->lte($endDate)) {
            $dow   = (int) $cursor->dayOfWeek;
            $sched = $schedule[$dow] ?? null;
            $isOff = $sched ? (bool) $sched->is_off : ($dow === 0);
            $isHol = in_array($cursor->toDateString(), $holidayDates, true);
            if (! $isOff && ! $isHol) $hariKerja++;
            $cursor->addDay();
        }
        $hariKerja   = max(1, $hariKerja);
        $gajiPerHari = round((float) $karyawan->gaji_pokok / $hariKerja);

        // Agregat attendance — hitung gaji_pokok dibayar, lembur, kolom kebijakan
        $attendances = $periode
            ? Attendance::where('periode_id', $periode->id)
                ->where('karyawan_id', $karyawanId)
                ->whereBetween('tanggal', [$startDate->toDateString(), $endDate->toDateString()])
                ->get()
            : collect();

        $totalGajiHari = 0;
        $totalLembur   = 0;
        foreach ($attendances as $att) {
            // Status 'lembur' = kerja di hari libur/off → hanya dapat uang lembur, tanpa gaji harian.
            $statusPaid = in_array($att->status, ['hadir', 'terlambat', 'pulang_awal', 'cuti', 'sakit'], true);
            $halfPay    = $att->get_half_pay;
            if ($statusPaid && ! $halfPay) {
                $totalGajiHari += $gajiPerHari;
            } elseif ($halfPay) {
                $totalGajiHari += round($gajiPerHari / 2);
            }
            $lemburJam = (float) $att->overtime_hours;
            $totalLembur += round($lemburJam * ($gajiPerHari / 7) * 2);
        }

        // Kolom kebijakan dinamis (tunjangan harian, bonus, dll)
        $kolomDinamis = KebijakanKolom::aktif()->ordered()->get();
        $totalKolom = 0.0;
        $sampleRow = [
            'status'      => 'hadir',
            'reg_datang'  => '07:00',
            'reg_pulang'  => '16:00',
            'lembur_jam'  => 0,
            'is_holiday'  => false,
            'is_off'      => false,
        ];
        $applied = $engine->applyRow($sampleRow, $karyawan, (float) $gajiPerHari);
        foreach ($kolomDinamis as $kol) {
            if ($kol->tipe === 'flag') continue;
            $v = $applied[$kol->key] ?? null;
            if (is_numeric($v) && $v > 0) {
                // Asumsi sederhana: kolom × hari_full (untuk approximation preview)
                $hariEfektif = $attendances->where('get_full_pay', true)->count();
                $totalKolom += (float) $v * $hariEfektif;
            }
        }

        // Baris summary (mode auto + manual)
        $summaryRows = KebijakanSummary::aktif()->ordered()->get();
        $autoValues  = $engine->applySummaryAuto($karyawan, (float) $gajiPerHari);
        $sumPlus = $sumMinus = 0.0;
        foreach ($summaryRows as $s) {
            if ($s->key === KebijakanSummary::KEY_TOTAL) continue;
            $v = $s->mode === 'auto' ? (float) ($autoValues[$s->key] ?? 0) : (float) $s->nominal_manual;
            if ($s->arah === 'plus') $sumPlus += $v;
            else                     $sumMinus += $v;
        }

        $bruto = $totalGajiHari + $totalLembur + $totalKolom + $sumPlus - $sumMinus;

        // Potongan BPJS + PPh 21
        $setting = PayrollSetting::singleton();
        $calc    = new PayrollTaxBpjsCalculator($setting);
        $tax     = $calc->compute($karyawan, $bruto);

        // Kasbon cicilan aktif
        $kasbonPotongan = 0.0;
        Kasbon::where('karyawan_id', $karyawan->id)
            ->where('status', 'posted')
            ->where('sisa_terhutang', '>', 0)
            ->get()
            ->each(function ($k) use (&$kasbonPotongan) {
                $cicilan = min((float) $k->cicilan_per_bulan, (float) $k->sisa_terhutang);
                $kasbonPotongan += $cicilan;
            });

        $potonganKaryawan = $tax['bpjs_kesehatan_employee']
                          + $tax['bpjs_tk_employee_total']
                          + $tax['pph21']
                          + $kasbonPotongan;
        $nett = $bruto - $potonganKaryawan;

        return [
            'hari_kerja'         => $hariKerja,
            'gaji_per_hari'      => $gajiPerHari,
            'total_gaji_pokok'   => $totalGajiHari,
            'total_lembur'       => $totalLembur,
            'total_kolom'        => $totalKolom,
            'summary_plus'       => $sumPlus,
            'summary_minus'      => $sumMinus,
            'bruto_gaji'         => round($bruto, 2),
            'bpjs_kes_employee'  => $tax['bpjs_kesehatan_employee'],
            'bpjs_kes_company'   => $tax['bpjs_kesehatan_company'],
            'bpjs_tk_employee'   => $tax['bpjs_tk_employee_total'],
            'bpjs_tk_company'    => $tax['bpjs_tk_company_total'],
            'pph21_amount'       => $tax['pph21'],
            'kasbon_potongan'    => round($kasbonPotongan, 2),
            'nett_dibayar'       => round($nett, 2),
            'karyawan'           => [
                'id'                  => $karyawan->id,
                'name'                => $karyawan->name,
                'staf_code'           => $karyawan->staf_code,
                'gaji_pokok'          => (float) $karyawan->gaji_pokok,
                'ptkp_category'       => $karyawan->ptkp_category,
                'ikut_bpjs_kesehatan' => (bool) $karyawan->ikut_bpjs_kesehatan,
                'ikut_bpjs_tk'        => (bool) $karyawan->ikut_bpjs_tk,
            ],
        ];
    }
}
