<?php

namespace App\Modules\SDM\Services;

use App\Modules\SDM\Models\Attendance;
use App\Modules\SDM\Models\AttendanceOverride;
use App\Modules\SDM\Models\IzinRequest;
use App\Modules\SDM\Models\Karyawan;
use App\Modules\SDM\Models\NationalHoliday;
use App\Modules\SDM\Models\PeriodePenggajian;
use App\Modules\SDM\Models\SlipGaji;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Alur pengajuan izin karyawan: draft (pending) → posting (approved) → efek payroll.
 *
 * - submit()        : validasi bisnis + simpan request status pending (NOL efek).
 * - approve()       : atomik — buat AttendanceOverride (reuse logika IzinController)
 *                     atau materialisasi 'toleransi' ke baris absensi; efek payroll DI SINI.
 * - reject()        : tolak tanpa efek.
 * - cancelApproval(): batalkan approval (ber-guard periode belum finalized), revert efek.
 *
 * Lihat memori project_dashboard_karyawan_2026_06_23 & feedback_void_pattern.
 */
class IzinRequestService
{
    public function __construct(
        private AttendanceImportService $importer,
        private PayrollCalculationService $payroll,
    ) {}

    // ───────────────────────── SUBMIT ─────────────────────────

    /**
     * @throws \RuntimeException pesan siap-tampil (bahasa Indonesia)
     */
    public function submit(Karyawan $karyawan, array $data, ?UploadedFile $foto = null): IzinRequest
    {
        $type = $data['type'] ?? '';
        if (! array_key_exists($type, IzinRequest::TYPES)) {
            throw new \RuntimeException('Tipe izin tidak dikenal.');
        }

        $tanggal = Carbon::parse($data['tanggal']);
        $isRange = in_array($type, IzinRequest::RANGE_TYPES, true) && ! empty($data['tanggal_akhir']);
        $tanggalAkhir = $isRange ? Carbon::parse($data['tanggal_akhir']) : null;

        if ($tanggalAkhir && $tanggalAkhir->lt($tanggal)) {
            throw new \RuntimeException('Tanggal akhir tidak boleh sebelum tanggal mulai.');
        }

        // ── Aturan per tipe ──
        if (in_array($type, ['tukar_hari', 'tukar_setengah_hari'], true) && empty($data['paired_date'])) {
            throw new \RuntimeException('Tanggal pasangan wajib diisi untuk Tukar Hari / Tukar Setengah Hari.');
        }
        if ($type === 'tukar_setengah_hari' && (empty($data['sesi']) || empty($data['paired_sesi']))) {
            throw new \RuntimeException('Sesi (pagi/sore) wajib diisi untuk Tukar Setengah Hari.');
        }
        if ($type === 'penyesuaian_jam' && empty($data['jam_masuk_override']) && empty($data['jam_pulang_override'])) {
            throw new \RuntimeException('Minimal salah satu jam (masuk/pulang) harus diisi untuk Penyesuaian Jam.');
        }

        // Toleransi: retroaktif, wajib foto, dan HANYA pada hari yang ADA scan.
        if ($type === 'toleransi') {
            if ($tanggal->gt(Carbon::today())) {
                throw new \RuntimeException('Toleransi hanya untuk hari yang sudah lewat (ada keterlambatan).');
            }
            if (! $foto) {
                throw new \RuntimeException('Toleransi wajib melampirkan bukti foto.');
            }
            if (! $this->hasScan($karyawan->id, $tanggal->toDateString())) {
                throw new \RuntimeException('Tidak ada catatan scan pada tanggal itu. Gunakan Izin / Cuti, bukan Toleransi.');
            }
        }

        // Cuti: cek sisa hak cuti tahun berjalan vs jumlah hari kerja yang diajukan.
        if ($type === 'cuti') {
            $hariKerja = $this->countWorkingDays($tanggal, $tanggalAkhir ?? $tanggal);
            $sisa = $this->sisaCuti($karyawan, $tanggal->year);
            if ($hariKerja > $sisa) {
                throw new \RuntimeException("Sisa cuti tahun {$tanggal->year} hanya {$sisa} hari, pengajuan {$hariKerja} hari.");
            }
        }

        // Cegah tumpang tindih: sudah ada request aktif / override tipe sama di tanggal itu.
        $this->assertNoOverlap($karyawan->id, $type, $tanggal, $tanggalAkhir ?? $tanggal);

        $path = $foto ? $foto->store('izin', 'public') : null;

        return IzinRequest::create([
            'karyawan_id'         => $karyawan->id,
            'type'                => $type,
            'tanggal'             => $tanggal->toDateString(),
            'tanggal_akhir'       => $tanggalAkhir?->toDateString(),
            'paired_date'         => in_array($type, ['tukar_hari', 'tukar_setengah_hari'], true) ? ($data['paired_date'] ?? null) : null,
            'sesi'                => $type === 'tukar_setengah_hari' ? ($data['sesi'] ?? null) : null,
            'paired_sesi'         => $type === 'tukar_setengah_hari' ? ($data['paired_sesi'] ?? null) : null,
            'jam_masuk_override'  => $type === 'penyesuaian_jam' ? ($data['jam_masuk_override'] ?? null) : null,
            'jam_pulang_override' => $type === 'penyesuaian_jam' ? ($data['jam_pulang_override'] ?? null) : null,
            'alasan'              => $data['alasan'],
            'lampiran_path'       => $path,
            'status'              => 'pending',
        ]);
    }

    // ───────────────────────── APPROVE ─────────────────────────

    /** @throws \RuntimeException */
    public function approve(IzinRequest $req, ?string $reviewer = null): IzinRequest
    {
        if (! $req->isPending()) {
            throw new \RuntimeException('Hanya pengajuan berstatus menunggu yang bisa disetujui.');
        }

        DB::transaction(function () use ($req, $reviewer) {
            $dates = $this->expandDates($req);
            if (empty($dates)) {
                throw new \RuntimeException('Tidak ada hari kerja dalam rentang tanggal (semua libur/Minggu).');
            }

            $overrideIds = [];

            if ($req->type === 'toleransi') {
                // Materialisasi langsung ke baris absensi (status tetap "hadir",
                // late dinetralkan, flag bayar dipulihkan, jam scan asli dipertahankan).
                foreach ($dates as $date) {
                    $this->applyToleransi($req, $date);
                }
            } else {
                foreach ($dates as $date) {
                    $ov = $this->createOverride($req, $date, $reviewer);
                    if ($ov) {
                        $overrideIds[] = $ov->id;
                    }
                    $this->regenerateSlipFor($req->karyawan_id, $date);
                }
            }

            $req->update([
                'status'       => 'approved',
                'reviewed_by'  => $reviewer,
                'reviewed_at'  => now(),
                'override_ids' => $overrideIds ?: null,
            ]);
        });

        return $req->refresh();
    }

    public function reject(IzinRequest $req, ?string $reviewer = null, ?string $notes = null): IzinRequest
    {
        if (! $req->isPending()) {
            throw new \RuntimeException('Hanya pengajuan berstatus menunggu yang bisa ditolak.');
        }
        $req->update([
            'status'       => 'rejected',
            'reviewed_by'  => $reviewer,
            'reviewed_at'  => now(),
            'review_notes' => $notes,
        ]);
        return $req;
    }

    /** Batalkan approval: revert efek, kembali ke pending. Guard periode finalized. */
    public function cancelApproval(IzinRequest $req): IzinRequest
    {
        if (! $req->isApproved()) {
            throw new \RuntimeException('Hanya pengajuan yang sudah disetujui yang bisa dibatalkan.');
        }

        DB::transaction(function () use ($req) {
            $dates = $this->expandDates($req);

            // Guard: tolak kalau ada periode terkait yang sudah finalized.
            foreach ($dates as $date) {
                if ($this->isPeriodeFinalized($req->karyawan_id, $date)) {
                    throw new \RuntimeException('Tidak bisa dibatalkan: slip/periode terkait sudah difinalisasi.');
                }
            }

            if ($req->type === 'toleransi') {
                foreach ($dates as $date) {
                    $this->revertToleransi($req->karyawan_id, $date);
                }
            } else {
                foreach ((array) $req->override_ids as $id) {
                    AttendanceOverride::where('id', $id)->delete();
                }
                foreach ($dates as $date) {
                    $this->regenerateSlipFor($req->karyawan_id, $date);
                }
            }

            $req->update([
                'status'       => 'pending',
                'reviewed_by'  => null,
                'reviewed_at'  => null,
                'review_notes' => null,
                'override_ids' => null,
            ]);
        });

        return $req->refresh();
    }

    // ───────────────────────── HELPERS ─────────────────────────

    /** Hari kerja efektif dalam rentang (skip Minggu + libur nasional). */
    private function expandDates(IzinRequest $req): array
    {
        $start = Carbon::parse($req->tanggal);
        $end   = $req->tanggal_akhir ? Carbon::parse($req->tanggal_akhir) : $start->copy();

        // Tipe non-range: satu hari saja, apa adanya.
        if (! in_array($req->type, IzinRequest::RANGE_TYPES, true)) {
            return [$start->toDateString()];
        }

        $dates = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            if ($d->dayOfWeek === Carbon::SUNDAY) continue;
            if (NationalHoliday::isLibur($d->toDateString())) continue;
            $dates[] = $d->toDateString();
        }
        return $dates;
    }

    private function countWorkingDays(Carbon $start, Carbon $end): int
    {
        $n = 0;
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            if ($d->dayOfWeek === Carbon::SUNDAY) continue;
            if (NationalHoliday::isLibur($d->toDateString())) continue;
            $n++;
        }
        return max(1, $n);
    }

    public function sisaCuti(Karyawan $karyawan, int $year): int
    {
        $hak = (int) ($karyawan->hak_cuti ?? 12);
        $terpakai = Attendance::where('karyawan_id', $karyawan->id)
            ->where('status', 'cuti')
            ->whereYear('tanggal', $year)
            ->count();
        // Tambah cuti yang sudah di-approve tapi mungkin belum termaterialisasi.
        $approvedPending = IzinRequest::where('karyawan_id', $karyawan->id)
            ->where('type', 'cuti')
            ->where('status', 'approved')
            ->whereYear('tanggal', $year)
            ->get()
            ->sum(fn ($r) => count($this->expandDates($r)));
        return max(0, $hak - max($terpakai, $approvedPending));
    }

    private function hasScan(int $karyawanId, string $date): bool
    {
        return Attendance::where('karyawan_id', $karyawanId)
            ->whereDate('tanggal', $date)
            ->whereNotNull('on_work1')
            ->exists();
    }

    private function assertNoOverlap(int $karyawanId, string $type, Carbon $start, Carbon $end): void
    {
        // Request aktif (pending/approved) tipe sama yang rentangnya beririsan.
        $clash = IzinRequest::where('karyawan_id', $karyawanId)
            ->where('type', $type)
            ->whereIn('status', ['pending', 'approved'])
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('tanggal', [$start->toDateString(), $end->toDateString()])
                  ->orWhere(function ($w) use ($start) {
                      $w->whereDate('tanggal', '<=', $start->toDateString())
                        ->whereDate('tanggal_akhir', '>=', $start->toDateString());
                  });
            })
            ->exists();
        if ($clash) {
            throw new \RuntimeException('Sudah ada pengajuan tipe sama yang menunggu/disetujui pada tanggal itu.');
        }

        $ovClash = AttendanceOverride::where('karyawan_id', $karyawanId)
            ->where('type', $type)
            ->whereBetween('tanggal', [$start->toDateString(), $end->toDateString()])
            ->exists();
        if ($ovClash) {
            throw new \RuntimeException('Sudah ada izin tipe sama yang tercatat pada tanggal itu.');
        }
    }

    /** Buat AttendanceOverride untuk satu tanggal (reuse pola IzinController). */
    private function createOverride(IzinRequest $req, string $date, ?string $reviewer): ?AttendanceOverride
    {
        $exists = AttendanceOverride::where('karyawan_id', $req->karyawan_id)
            ->whereDate('tanggal', $date)
            ->where('type', $req->type)
            ->first();
        if ($exists) {
            return $exists; // idempoten
        }

        return AttendanceOverride::create([
            'karyawan_id'         => $req->karyawan_id,
            'tanggal'             => $date,
            'paired_date'         => $req->paired_date,
            'sesi'                => $req->sesi,
            'paired_sesi'         => $req->paired_sesi,
            'jam_masuk_override'  => $req->jam_masuk_override,
            'jam_pulang_override' => $req->jam_pulang_override,
            'type'                => $req->type,
            'notes'               => trim('[PWA] ' . $req->alasan),
            'created_by'          => $reviewer,
        ]);
    }

    /** Toleransi: ubah baris absensi jadi "hadir" penuh, late dinetralkan, scan dipertahankan. */
    private function applyToleransi(IzinRequest $req, string $date): void
    {
        $att = Attendance::where('karyawan_id', $req->karyawan_id)
            ->whereDate('tanggal', $date)
            ->first();
        if (! $att || ! $att->on_work1) {
            throw new \RuntimeException('Tidak ada scan pada ' . $date . ' — toleransi tidak berlaku.');
        }

        $flags = $this->importer->applyPayFlags('hadir', $att->on_work1);
        $att->status            = 'hadir';
        $att->late_minutes      = 0;
        $att->early_out_minutes = 0;
        $att->get_full_pay      = $flags['full'];
        $att->get_half_pay      = $flags['half'];
        $att->get_tunjangan     = $flags['tunj'];
        $att->get_bonus_absen   = $flags['bonus_absen'];
        $att->edited_manually   = true;
        $att->remark            = trim(($att->remark ? $att->remark . ' | ' : '') . 'Toleransi: ' . $req->alasan);
        $att->save();

        $this->regenerateSlipFor($req->karyawan_id, $date);
    }

    private function revertToleransi(int $karyawanId, string $date): void
    {
        $att = Attendance::where('karyawan_id', $karyawanId)
            ->whereDate('tanggal', $date)
            ->first();
        if ($att) {
            $att->edited_manually = false; // kembali ke auto pada regenerate berikutnya
            $att->save();
            $this->regenerateSlipFor($karyawanId, $date);
        }
    }

    private function regenerateSlipFor(int $karyawanId, string $date): void
    {
        $d = Carbon::parse($date);
        $periode = PeriodePenggajian::where('bulan', $d->month)->where('tahun', $d->year)->first();
        if (! $periode) return;
        $slip = SlipGaji::where('periode_id', $periode->id)->where('karyawan_id', $karyawanId)->first();
        if ($slip && ! $slip->isFinalized()) {
            $this->payroll->regenerateSlip($slip);
        }
    }

    private function isPeriodeFinalized(int $karyawanId, string $date): bool
    {
        $d = Carbon::parse($date);
        $periode = PeriodePenggajian::where('bulan', $d->month)->where('tahun', $d->year)->first();
        if (! $periode) return false;
        $slip = SlipGaji::where('periode_id', $periode->id)->where('karyawan_id', $karyawanId)->first();
        return $slip && $slip->isFinalized();
    }
}
