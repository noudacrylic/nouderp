<?php

namespace App\Modules\Purchasing\Models;

use App\Core\Inventory\Product;
use App\Models\User;
use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu laporan barang habis / hampir habis dari divisi. Lihat LaporStokService. */
class LaporStok extends Model
{
    protected $table = 'lapor_stok';

    public const KONDISI = ['hampir_habis' => 'Hampir habis', 'habis' => 'Sudah habis'];

    protected $fillable = ['product_id', 'nama_barang', 'kondisi', 'catatan', 'divisi', 'pelapor_user_id', 'pelapor_nama', 'task_id'];

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }

    public function pelapor(): BelongsTo { return $this->belongsTo(User::class, 'pelapor_user_id'); }

    public function task(): BelongsTo { return $this->belongsTo(Task::class); }

    public function namaBarang(): string
    {
        return $this->product?->name ?? (string) $this->nama_barang;
    }

    public function namaPelapor(): string
    {
        return $this->pelapor_nama ?: ($this->pelapor?->name ?? '—');
    }

    /** Status untuk pelapor, dibaca dari task penanganannya. */
    public function statusLabel(): array
    {
        return match ($this->task?->status) {
            null                  => ['Menunggu', 'bg-gray-100 text-gray-600'],
            'done'                => ['Sudah ditangani', 'bg-green-100 text-green-800'],
            'cancelled'           => ['Dibatalkan', 'bg-gray-100 text-gray-500'],
            default               => ['Diproses Purchasing', 'bg-blue-100 text-blue-800'],
        };
    }
}
