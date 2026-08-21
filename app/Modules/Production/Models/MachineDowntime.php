<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Henti mesin — kenapa sebuah slot kapasitas tidak dipakai, padahal harinya hari kerja.
 *
 * Bukan pengganti timer produksi: ini justru untuk waktu yang TIDAK produktif, supaya lubang
 * di Kalender Produksi punya keterangan alih-alih jadi tuduhan diam-diam bahwa pabriknya
 * menganggur. Kapasitasnya tetap dihitung — mesin yang sedang dirawat tetap menanggung
 * penyusutan dan sewa — yang berubah hanya: lubangnya sekarang bernama.
 */
class MachineDowntime extends Model
{
    protected $table = 'production_machine_downtimes';

    protected $fillable = ['executor_id', 'started_at', 'ended_at', 'reason', 'notes', 'created_by'];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at'   => 'datetime',
    ];

    public const REASONS = [
        'perawatan'    => 'Perawatan rutin',
        'rusak'        => 'Rusak / perbaikan',
        'setup'        => 'Setting / ganti alat',
        'mati_listrik' => 'Mati listrik',
        'bahan_habis'  => 'Menunggu bahan',
        'lainnya'      => 'Lainnya',
    ];

    public function executor()
    {
        return $this->belongsTo(DepartmentExecutor::class, 'executor_id');
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? $this->reason;
    }
}
