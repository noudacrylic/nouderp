<?php

namespace App\Modules\SDM\Models;

use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\DepartmentExecutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Karyawan extends Model
{
    use SoftDeletes;

    protected $table = 'sdm_karyawan';

    protected $fillable = [
        'staf_code', 'name', 'department_id', 'jabatan',
        'gaji_pokok', 'tunjangan_pegawai',
        'mulai_kerja', 'sanksi', 'hak_cuti', 'user_id_fingerprint',
        'is_active', 'resign_date',
        'hp', 'email', 'alamat', 'nik', 'npwp', 'bpjs', 'foto_path', 'ktp_path',
        'bpjs_kesehatan_amount', 'bpjs_tk_amount', 'pph21_amount',
        'ptkp_category', 'ikut_bpjs_kesehatan', 'ikut_bpjs_tk',
        'bank_name', 'bank_account_number', 'bank_account_holder',
    ];

    protected $casts = [
        'gaji_pokok'             => 'decimal:2',
        'tunjangan_pegawai'      => 'decimal:2',
        'bpjs_kesehatan_amount'  => 'decimal:2',
        'bpjs_tk_amount'         => 'decimal:2',
        'pph21_amount'           => 'decimal:2',
        'hak_cuti'               => 'integer',
        'mulai_kerja'            => 'date',
        'resign_date'            => 'date',
        'is_active'              => 'boolean',
        'ikut_bpjs_kesehatan'    => 'boolean',
        'ikut_bpjs_tk'           => 'boolean',
    ];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function executors()
    {
        return $this->hasMany(DepartmentExecutor::class);
    }

    public function slipGajis()
    {
        return $this->hasMany(SlipGaji::class);
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    public function schedules()
    {
        return $this->hasMany(KaryawanSchedule::class)->orderBy('day_of_week');
    }

    public function spHistory()
    {
        return $this->hasMany(SpHistory::class)->orderByDesc('tanggal_terbit');
    }

    public function user()
    {
        return $this->hasOne(\App\Models\User::class);
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    public function kasbon()
    {
        return $this->hasMany(Kasbon::class);
    }

    public function activeKasbon()
    {
        return $this->hasMany(Kasbon::class)->where('status', 'posted')->where('sisa_terhutang', '>', 0);
    }

    public function canBeDeleted(): bool
    {
        return !$this->slipGajis()->exists()
            && !$this->attendances()->exists()
            && !$this->kasbon()->exists()
            && !$this->spHistory()->exists()
            && !$this->executors()->exists();
    }

    /**
     * Apakah karyawan dijadwalkan boleh lembur pada tanggal tertentu?
     * Dibaca dari KaryawanSchedule.has_lembur untuk day_of_week tanggal tsb.
     */
    public function isOvertimeAllowedOn(\DateTimeInterface $date): bool
    {
        $dow = (int) $date->format('w'); // 0=Sun..6=Sat
        return (bool) KaryawanSchedule::where('karyawan_id', $this->id)
            ->where('day_of_week', $dow)
            ->value('has_lembur');
    }
}
