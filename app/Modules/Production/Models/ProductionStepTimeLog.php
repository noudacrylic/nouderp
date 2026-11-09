<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionStepTimeLog extends Model
{
    /**
     * Alasan Pending manual — wajib dipilih pelaksana saat menekan Pause. Waktu pending beralasan
     * tetap dihitung "waktu kerja" (membantu divisi lain tidak dirugikan), tapi tidak masuk
     * penilaian kecepatan OP. 'keterangan' = isian bebas wajib diisi.
     *
     * @var array<string, array{label: string, keterangan: bool}>
     */
    public const ALASAN_PENDING = [
        'bongkar_barang'   => ['label' => '📦 Bongkar / turunkan barang datang', 'keterangan' => false],
        'bantu_divisi'     => ['label' => '🤝 Bantu divisi lain', 'keterangan' => true],
        'belanja'          => ['label' => '🛒 Belanja keperluan (galon, komponen, plastik, …)', 'keterangan' => true],
        'perintah_owner'   => ['label' => '👑 Diperintah owner', 'keterangan' => false],
        'tunggu_bahan'     => ['label' => '⏳ Menunggu bahan / komponen', 'keterangan' => false],
        'alat_bermasalah'  => ['label' => '🔧 Alat / mesin bermasalah', 'keterangan' => false],
        'dipanggil_atasan' => ['label' => '🙋 Dipanggil atasan', 'keterangan' => false],
        'lainnya'          => ['label' => '✍ Lainnya', 'keterangan' => true],
    ];

    protected $fillable = [
        'production_order_step_id', 'event_type', 'alasan', 'occurred_at', 'notes',
    ];

    public function labelAlasan(): ?string
    {
        return $this->alasan ? (self::ALASAN_PENDING[$this->alasan]['label'] ?? $this->alasan) : null;
    }

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public function step()
    {
        return $this->belongsTo(ProductionOrderStep::class, 'production_order_step_id');
    }
}
