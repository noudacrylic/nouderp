<?php

namespace App\Modules\SDM\Services;

use App\Modules\SDM\Models\Attendance;
use App\Modules\SDM\Models\Karyawan;
use App\Modules\SDM\Models\Kebijakan;
use App\Modules\SDM\Models\PeriodePenggajian;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as PsDate;

class AttendanceImportService
{
    public function __construct(
        protected PayrollCalculationService $payroll,
        protected PeriodePenggajianService $periodeService,
        protected AttendanceStatusResolver $statusResolver,
    ) {}

    /**
     * Isi data absensi dari Excel. DIGERAKKAN OLEH TANGGAL di tiap baris: setiap
     * baris dirutekan ke periode sesuai tanggalnya (auto-buat bila belum ada),
     * bukan dibatasi satu bulan. Cocok untuk backfill hari-hari sebelum mesin
     * fingerprint aktif. Data fingerprint/manual yang sudah ada TIDAK ditimpa —
     * Excel hanya mengisi slot waktu yang masih kosong.
     *
     * @param int|null $hintBulan,$hintTahun  petunjuk bulan/tahun untuk membantu
     *        memparse format tanggal ambigu (m/d vs d/m). Tidak membatasi baris.
     * @return array{imported:int, skipped:int, errors:array<string>}
     */
    public function import(UploadedFile $file, ?int $hintBulan = null, ?int $hintTahun = null): array
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        $header = $this->mapHeader(array_shift($rows));
        $imported = 0;
        $skipped = 0;
        $errors = [];

        $karyawanCache = [];
        $periodeCache  = [];          // 'Y-n' => PeriodePenggajian
        $affected      = [];          // periode_id => PeriodePenggajian (untuk regen slip)

        foreach ($rows as $rowIdx => $row) {
            $stafCode = trim((string) ($row[$header['staf_code']] ?? ''));
            $namaRow  = trim((string) ($row[$header['name']] ?? ''));
            if ($stafCode === '' && $namaRow === '') {
                continue;
            }

            // Cocokkan via staf_code / user_id_fingerprint / nama (lihat findKaryawan).
            $cacheKey = $stafCode . '|' . mb_strtolower($namaRow);
            $karyawan = $karyawanCache[$cacheKey]
                ?? ($karyawanCache[$cacheKey] = $this->findKaryawan($stafCode, $namaRow));

            if (! $karyawan) {
                $ident = $stafCode !== '' ? "Staf Code {$stafCode}" : "Nama {$namaRow}";
                $errors[] = "Baris {$rowIdx}: {$ident} tidak cocok dengan master karyawan (cek Staf Code / Nama).";
                $skipped++;
                continue;
            }

            $tanggalRaw = $row[$header['date']] ?? null;
            $tanggal = $this->parseDate($tanggalRaw, $hintBulan, $hintTahun);
            if (! $tanggal) {
                $errors[] = "Baris {$rowIdx}: format tanggal tidak valid ({$tanggalRaw}).";
                $skipped++;
                continue;
            }

            // Rute ke periode sesuai TANGGAL baris (auto-buat bila belum ada).
            $cacheKey = $tanggal->year . '-' . $tanggal->month;
            $periode = $periodeCache[$cacheKey]
                ?? ($periodeCache[$cacheKey] = $this->periodeService->ensureForMonth($tanggal->month, $tanggal->year));

            if (! $periode->canImport()) {
                $errors[] = "Baris {$rowIdx}: periode {$periode->label} sudah finalized/void — dilewati.";
                $skipped++;
                continue;
            }
            $affected[$periode->id] = $periode;

            $existing = Attendance::where([
                'periode_id'  => $periode->id,
                'karyawan_id' => $karyawan->id,
                'tanggal'     => $tanggal->toDateString(),
            ])->first();

            if ($existing && $existing->edited_manually) {
                $skipped++;
                continue;
            }

            // Excel = fallback. Sumber utama = mesin fingerprint.
            // Hanya isi slot waktu yang masih kosong di DB; jangan timpa yang sudah terisi.
            $excelOn1  = $this->parseTime($row[$header['on_work1']] ?? null);
            $excelOff1 = $this->parseTime($row[$header['off_work1']] ?? null);
            $excelOn2  = $this->parseTime($row[$header['on_work2']] ?? null);
            $excelOff2 = $this->parseTime($row[$header['off_work2']] ?? null);
            $excelOn3  = $this->parseTime($row[$header['on_work3']] ?? null);
            $excelOff3 = $this->parseTime($row[$header['off_work3']] ?? null);

            $on1  = $existing?->on_work1  ?: $excelOn1;
            $off1 = $existing?->off_work1 ?: $excelOff1;
            $on2  = $existing?->on_work2  ?: $excelOn2;
            $off2 = $existing?->off_work2 ?: $excelOff2;
            $on3  = $existing?->on_work3  ?: $excelOn3;
            $off3 = $existing?->off_work3 ?: $excelOff3;

            // Kalau semua slot sudah ada di DB (dari mesin) dan Excel tidak menambahkan apa-apa baru, skip.
            $anyFilledFromExcel =
                (! $existing?->on_work1  && $excelOn1)  ||
                (! $existing?->off_work1 && $excelOff1) ||
                (! $existing?->on_work2  && $excelOn2)  ||
                (! $existing?->off_work2 && $excelOff2) ||
                (! $existing?->on_work3  && $excelOn3)  ||
                (! $existing?->off_work3 && $excelOff3);

            if ($existing && ! $anyFilledFromExcel) {
                $skipped++;
                continue;
            }

            $week = $this->shortWeek($row[$header['week']] ?? null, $tanggal);
            // Status mengikuti jadwal per-karyawan + override jam hari itu (bukan ambang global).
            $status = $this->statusResolver->autoStatus($karyawan, $tanggal, $on1, $off1);

            $overtimeAllowed = $karyawan->isOvertimeAllowedOn($tanggal);

            $overtime = $this->calculateOvertime($off2, $overtimeAllowed);
            $flags = $this->applyPayFlags($status, $on1);

            Attendance::updateOrCreate(
                [
                    'periode_id'  => $periode->id,
                    'karyawan_id' => $karyawan->id,
                    'tanggal'     => $tanggal->toDateString(),
                ],
                [
                    'week'                 => $week,
                    'shift_name'           => $existing?->shift_name ?: ($row[$header['shift_name']] ?? null),
                    'on_work1'             => $on1,
                    'off_work1'            => $off1,
                    'on_work2'             => $on2,
                    'off_work2'            => $off2,
                    'on_work3'             => $on3,
                    'off_work3'            => $off3,
                    'absent_days'          => $this->numeric($row[$header['absent_days']] ?? 0),
                    'working_hours'        => $this->numeric($row[$header['working_hours']] ?? 0),
                    'ot_hours'             => $this->numeric($row[$header['ot_hours']] ?? 0),
                    'late_minutes'         => (int) ($row[$header['late_minutes']] ?? 0),
                    'early_out_minutes'    => (int) ($row[$header['early_out_minutes']] ?? 0),
                    'absent_punch_times'   => (int) ($row[$header['absent_punch_times']] ?? 0),
                    'public_holiday_hours' => $this->numeric($row[$header['public_holiday_hours']] ?? 0),
                    'leave_hours'          => $this->numeric($row[$header['leave_hours']] ?? 0),
                    'remark'               => $row[$header['remark']] ?? null,
                    'status'               => $status,
                    'overtime_hours'       => $overtime,
                    'get_full_pay'         => $flags['full'],
                    'get_half_pay'         => $flags['half'],
                    'get_tunjangan'        => $flags['tunj'],
                    'get_bonus_absen'      => $flags['bonus_absen'],
                    'edited_manually'      => false,
                ]
            );

            $imported++;
        }

        // Untuk tiap periode yang tersentuh: catat jejak audit + regen slip (tetap 'open').
        foreach ($affected as $periode) {
            $periode->update(['imported_at' => now()]);
            $this->payroll->generateAllSlips($periode);
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Cari karyawan dari Excel dengan beberapa strategi (paling andal dulu):
     * 1. staf_code persis  2. user_id_fingerprint persis  3. nama (case-insensitive).
     * Excel mesin/manual sering pakai kode berbeda dari master → fallback ke nama.
     */
    protected function findKaryawan(string $stafCode, ?string $name = null): ?Karyawan
    {
        $code = trim($stafCode);

        if ($code !== '') {
            $byCode = Karyawan::where('staf_code', $code)
                ->orWhere('user_id_fingerprint', $code)
                ->first();
            if ($byCode) return $byCode;
        }

        $name = trim((string) $name);
        if ($name !== '') {
            return Karyawan::whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])->first();
        }

        return null;
    }

    /**
     * @deprecated Pakai AttendanceStatusResolver::autoStatus() — memakai ambang GLOBAL,
     * tidak sadar jadwal per-karyawan / override jam. Dipertahankan utk fallback langka.
     */
    public function determineStatus(?string $on1, ?string $off1, ?string $week): string
    {
        $isWeekend = in_array(strtolower((string) $week), ['sun', 'min'], true);

        if (! $on1 && ! $off1) {
            return $isWeekend ? 'libur' : 'tidak_hadir';
        }

        // Hanya 1 sisi yang ada — datang tanpa pulang, atau pulang tanpa datang = setengah hari
        if (! $on1 || ! $off1) {
            return 'setengah_hari';
        }

        $batasMasukSetengah  = $this->normalizeTime(Kebijakan::threshold('batas_setengah_hari_masuk', '10:30'));
        $batasPulangSetengah = $this->normalizeTime(Kebijakan::threshold('batas_setengah_hari_pulang', '14:00'));
        $batasTerlambat      = $this->normalizeTime(Kebijakan::threshold('batas_terlambat', '08:10'));
        $jamSelesai          = $this->normalizeTime(Kebijakan::threshold('jam_kerja_selesai', '16:00'));

        if ($on1 > $batasMasukSetengah) {
            return 'setengah_hari';
        }
        if ($off1 < $batasPulangSetengah) {
            return 'setengah_hari';
        }

        $comingLate = $on1 > $batasTerlambat;
        $pulangAwal = $off1 < $jamSelesai;

        if ($comingLate && $pulangAwal) return 'pulang_awal';
        if ($comingLate) return 'terlambat';
        if ($pulangAwal) return 'pulang_awal';

        return 'hadir';
    }

    protected function normalizeTime(string $hm): string
    {
        return strlen($hm) === 5 ? $hm . ':00' : $hm;
    }

    public function calculateOvertime(?string $off2, bool $deptOvertimeActive): float
    {
        if (! $deptOvertimeActive || ! $off2 || $off2 <= '16:30:00') {
            return 0;
        }

        if ($off2 <= '17:30:00') return 1.0;
        if ($off2 <= '18:00:00') return 1.5;
        if ($off2 <= '18:30:00') return 1.5;
        if ($off2 <= '19:00:00') return 2.0;
        if ($off2 <= '20:00:00') {
            $start = Carbon::createFromFormat('H:i:s', '18:30:00');
            $end   = Carbon::createFromFormat('H:i:s', $off2);
            $mins  = $start->diffInMinutes($end);
            return round(1.5 + ($mins / 60), 2);
        }
        return 3.0;
    }

    public function applyPayFlags(string $status, ?string $on1 = null): array
    {
        $base = match ($status) {
            'hadir'                => ['full' => true,  'half' => false],
            'terlambat'            => ['full' => true,  'half' => false],
            'pulang_awal'          => ['full' => true,  'half' => false],
            'setengah_hari'        => ['full' => false, 'half' => true],
            'cuti'                 => ['full' => true,  'half' => false],
            'sakit'                => ['full' => true,  'half' => false],
            'lembur'               => ['full' => false, 'half' => false],
            'lembur_setengah_hari' => ['full' => false, 'half' => false],
            default                => ['full' => false, 'half' => false],
        };

        // Flag tunjangan & bonus diturunkan dari engine rule: jika rule menghasilkan nilai > 0
        // untuk kolom 'tunjangan_harian' / 'bonus_harian' pada konteks ini → flag = true.
        // Karyawan-context tidak tersedia di sini (per-row import), jadi pakai dummy karyawan.
        $row = [
            'status'      => $status,
            'reg_datang'  => $on1 ? substr($on1, 0, 5) : null,
            'reg_pulang'  => null,
            'lembur_jam'  => 0,
            'is_holiday'  => false,
            'is_off'      => false,
        ];
        $dummy = new \App\Modules\SDM\Models\Karyawan(['sanksi' => 'SP0', 'gaji_pokok' => 0]);
        $engine = app(\App\Modules\SDM\Services\KebijakanRuleEngine::class);
        $result = $engine->applyRow($row, $dummy, 0.0);

        $base['tunj']        = ($base['full'] || $base['half']) && (float) ($result['tunjangan_harian'] ?? 0) > 0;
        $base['bonus_absen'] = $base['full'] && (float) ($result['bonus_harian'] ?? 0) > 0;

        return $base;
    }

    protected function mapHeader(array $headerRow): array
    {
        $normalized = [];
        foreach ($headerRow as $col => $label) {
            $key = strtolower(trim((string) $label));
            $key = str_replace([' ', '_'], '_', $key);
            $key = preg_replace('/__+/', '_', $key);
            $normalized[$key] = $col;
        }

        return [
            'staf_code'            => $normalized['staf_code'] ?? 'A',
            'name'                 => $normalized['name'] ?? 'B',
            'department'           => $normalized['department'] ?? 'C',
            'date'                 => $normalized['date'] ?? 'D',
            'week'                 => $normalized['week'] ?? 'E',
            'shift_name'           => $normalized['shift_name'] ?? 'F',
            'on_work1'             => $normalized['on_work1'] ?? 'G',
            'off_work1'            => $normalized['off_work1'] ?? 'H',
            'on_work2'             => $normalized['on_work2'] ?? 'I',
            'off_work2'            => $normalized['off_work2'] ?? 'J',
            'on_work3'             => $normalized['on_work3'] ?? 'K',
            'off_work3'            => $normalized['off_work3'] ?? 'L',
            'absent_days'          => $normalized['absent_days'] ?? 'M',
            'working_hours'        => $normalized['working_hours'] ?? 'N',
            'ot_hours'             => $normalized['ot_hours'] ?? 'O',
            'late_minutes'         => $normalized['late_in_minutes'] ?? $normalized['late_minutes'] ?? 'P',
            'early_out_minutes'    => $normalized['early_out_minutes'] ?? 'Q',
            'absent_punch_times'   => $normalized['absent_punch_times'] ?? 'R',
            'public_holiday_hours' => $normalized['public_holiday_hours'] ?? 'S',
            'leave_hours'          => $normalized['leave_hours'] ?? 'T',
            'remark'               => $normalized['remark'] ?? 'U',
        ];
    }

    protected function parseDate($value, ?int $hintBulan = null, ?int $hintTahun = null): ?Carbon
    {
        if ($value === null || $value === '') return null;
        if (is_numeric($value)) {
            try {
                return Carbon::instance(PsDate::excelToDateTimeObject($value));
            } catch (\Throwable $e) {
                return null;
            }
        }
        $value = trim((string) $value);

        // Untuk format ambigu m/d/Y vs d/m/Y, pakai bulan/tahun yang sedang dilihat
        // sebagai petunjuk (cocokkan kalau dua-duanya bisa parse).
        $candidates = [];
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'm/d/Y', 'n/j/Y'] as $f) {
            try {
                $dt = Carbon::createFromFormat('!' . $f, $value);
                if ($dt && $dt->format($f) === $value) {
                    $candidates[$f] = $dt;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        if ($hintBulan && $hintTahun && $candidates) {
            foreach ($candidates as $dt) {
                if ($dt->month === (int) $hintBulan && $dt->year === (int) $hintTahun) {
                    return $dt;
                }
            }
        }

        if ($candidates) {
            return reset($candidates);
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function parseTime($value): ?string
    {
        if ($value === null || $value === '') return null;
        if (is_numeric($value)) {
            try {
                $dt = PsDate::excelToDateTimeObject($value);
                return $dt->format('H:i:s');
            } catch (\Throwable $e) {
                return null;
            }
        }
        $value = trim((string) $value);
        if (preg_match('/^(\d{1,2}):(\d{2})(:\d{2})?$/', $value, $m)) {
            return sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], isset($m[3]) ? (int) ltrim($m[3], ':') : 0);
        }
        return null;
    }

    protected function shortWeek($raw, Carbon $tanggal): string
    {
        if ($raw) {
            $r = strtolower(trim((string) $raw));
            $map = [
                'monday'    => 'Mon', 'mon' => 'Mon', 'senin' => 'Mon',
                'tuesday'   => 'Tue', 'tue' => 'Tue', 'selasa' => 'Tue',
                'wednesday' => 'Wed', 'wed' => 'Wed', 'rabu' => 'Wed',
                'thursday'  => 'Thu', 'thu' => 'Thu', 'kamis' => 'Thu',
                'friday'    => 'Fri', 'fri' => 'Fri', 'jumat' => 'Fri', 'jum\'at' => 'Fri',
                'saturday'  => 'Sat', 'sat' => 'Sat', 'sabtu' => 'Sat',
                'sunday'    => 'Sun', 'sun' => 'Sun', 'minggu' => 'Sun',
            ];
            if (isset($map[$r])) return $map[$r];
        }
        return $tanggal->format('D');
    }

    protected function numeric($value): float
    {
        if ($value === null || $value === '') return 0;
        if (is_numeric($value)) return (float) $value;
        $clean = preg_replace('/[^0-9\.,-]/', '', (string) $value);
        $clean = str_replace(',', '.', $clean);
        return is_numeric($clean) ? (float) $clean : 0;
    }
}
