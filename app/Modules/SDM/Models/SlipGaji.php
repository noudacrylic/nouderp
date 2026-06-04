<?php

namespace App\Modules\SDM\Models;

use Illuminate\Database\Eloquent\Model;

class SlipGaji extends Model
{
    protected $table = 'sdm_slip_gaji';

    protected $fillable = [
        'periode_id', 'karyawan_id', 'code',
        'hari_hadir_penuh', 'hari_setengah', 'hari_terlambat', 'hari_pulang_awal',
        'hari_tidak_hadir', 'hari_libur', 'hari_bonus_absen', 'total_jam_lembur',
        'gaji_pokok_bulan', 'gaji_per_hari', 'hari_kerja_periode', 'gaji_pokok_dibayar',
        'lembur_amount', 'tunjangan_harian_amount', 'tunjangan_bulanan_amount', 'bonus_absen_amount',
        'bonus', 'thr_amount', 'cuti_unused_amount',
        'bpjs_kesehatan_amount', 'bpjs_tk_amount', 'pph21_amount',
        'kasbon_payment',
        'total_komponen_earning', 'total_potongan',
        'subtotal', 'total_gaji',
        'total_dibayar', 'sisa_dibayar', 'payment_status',
        'status', 'finalized_at', 'notes',
    ];

    protected $casts = [
        'total_jam_lembur'         => 'decimal:2',
        'gaji_pokok_bulan'         => 'decimal:2',
        'gaji_per_hari'            => 'decimal:2',
        'hari_kerja_periode'       => 'integer',
        'gaji_pokok_dibayar'       => 'decimal:2',
        'lembur_amount'            => 'decimal:2',
        'tunjangan_harian_amount'  => 'decimal:2',
        'tunjangan_bulanan_amount' => 'decimal:2',
        'bonus_absen_amount'       => 'decimal:2',
        'bonus'                    => 'decimal:2',
        'thr_amount'               => 'decimal:2',
        'cuti_unused_amount'       => 'decimal:2',
        'bpjs_kesehatan_amount'    => 'decimal:2',
        'bpjs_tk_amount'           => 'decimal:2',
        'pph21_amount'             => 'decimal:2',
        'kasbon_payment'           => 'decimal:2',
        'total_komponen_earning'   => 'decimal:2',
        'total_potongan'           => 'decimal:2',
        'subtotal'                 => 'decimal:2',
        'total_gaji'               => 'decimal:2',
        'total_dibayar'            => 'decimal:2',
        'sisa_dibayar'             => 'decimal:2',
        'finalized_at'             => 'datetime',
    ];

    public function periode()
    {
        return $this->belongsTo(PeriodePenggajian::class, 'periode_id');
    }

    public function karyawan()
    {
        return $this->belongsTo(Karyawan::class);
    }

    public function komponen()
    {
        return $this->hasMany(SlipGajiKomponen::class, 'slip_gaji_id')->orderBy('urutan');
    }

    public function isFinalized(): bool
    {
        return $this->status === 'finalized';
    }

    public function isFullyPaid(): bool
    {
        return $this->payment_status === 'paid';
    }

    public function isUnpaid(): bool
    {
        return $this->payment_status === 'unpaid';
    }
}
