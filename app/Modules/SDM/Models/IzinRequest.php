<?php

namespace App\Modules\SDM\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pengajuan izin karyawan (alur draft→posting). Lihat IzinRequestService.
 */
class IzinRequest extends Model
{
    protected $table = 'sdm_izin_requests';

    protected $fillable = [
        'karyawan_id', 'type', 'tanggal', 'tanggal_akhir',
        'paired_date', 'sesi', 'paired_sesi',
        'jam_masuk_override', 'jam_pulang_override',
        'alasan', 'lampiran_path',
        'status', 'reviewed_by', 'reviewed_at', 'review_notes', 'override_ids',
    ];

    protected $casts = [
        'tanggal'       => 'date',
        'tanggal_akhir' => 'date',
        'paired_date'   => 'date',
        'reviewed_at'   => 'datetime',
        'override_ids'  => 'array',
    ];

    /** Tipe yang boleh diajukan karyawan = semua override + toleransi. */
    public const TYPES = AttendanceOverride::TYPES + [
        'toleransi' => 'Toleransi (force majeure)',
    ];

    /** Tipe yang boleh rentang tanggal (1 pengajuan → banyak hari). */
    public const RANGE_TYPES = ['cuti', 'sakit'];

    public function karyawan()
    {
        return $this->belongsTo(Karyawan::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function isPending(): bool  { return $this->status === 'pending'; }
    public function isApproved(): bool { return $this->status === 'approved'; }
    public function isRejected(): bool { return $this->status === 'rejected'; }

    /** Badge label + warna untuk status pengajuan. */
    public function statusBadge(): array
    {
        return match ($this->status) {
            'approved' => ['Disetujui', 'bg-green-100 text-green-700'],
            'rejected' => ['Ditolak', 'bg-red-100 text-red-700'],
            default    => ['Menunggu', 'bg-amber-100 text-amber-700'],
        };
    }
}
