<?php

namespace App\Modules\SDM\Models;

use Illuminate\Database\Eloquent\Model;

class PeriodePenggajian extends Model
{
    protected $table = 'sdm_periode_penggajian';

    protected $fillable = [
        'code', 'bulan', 'tahun', 'label', 'start_date', 'end_date',
        'status', 'imported_at', 'finalized_at', 'voided_at', 'void_reason', 'notes',
    ];

    protected $casts = [
        'bulan'        => 'integer',
        'tahun'        => 'integer',
        'start_date'   => 'date',
        'end_date'     => 'date',
        'imported_at'  => 'datetime',
        'finalized_at' => 'datetime',
        'voided_at'    => 'datetime',
    ];

    public function slipGajis()
    {
        return $this->hasMany(SlipGaji::class, 'periode_id');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'periode_id');
    }

    public function canBeVoided(): bool
    {
        return $this->status !== 'void';
    }

    public function canBeFinalized(): bool
    {
        return $this->status === 'open' && $this->slipGajis()->count() > 0;
    }

    /** Impor Excel hanya untuk periode berjalan (open); finalized/void terkunci. */
    public function canImport(): bool
    {
        return $this->status === 'open';
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isFinalized(): bool
    {
        return $this->status === 'finalized';
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }

    /** Label tampilan status. open=Berjalan, finalized=Final, void=Void. */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'open'      => 'Berjalan',
            'finalized' => 'Final',
            'void'      => 'Void',
            default     => ucfirst((string) $this->status),
        };
    }
}
