<?php

namespace App\Modules\CRM\Models;

use App\Core\Inventory\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu titipan "kabari kalau stoknya ada" (lihat migrasi crm_stock_watches).
 */
class CrmStockWatch extends Model
{
    protected $table = 'crm_stock_watches';

    protected $fillable = [
        'conversation_id', 'product_id', 'qty', 'recipient',
        'aktif', 'created_by', 'notified_at', 'outbox_id',
    ];

    protected $casts = [
        'qty'         => 'float',
        'aktif'       => 'boolean',
        'notified_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(CrmConversation::class, 'conversation_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function scopeAktif($query)
    {
        return $query->where('aktif', true);
    }

    /**
     * Lepas titipannya. `aktif` di-NULL-kan, BUKAN di-false-kan: indeks unik
     * yang menjaga "satu titipan aktif per chat+produk" bersandar pada NULL
     * diabaikannya baris oleh MySQL. Diisi false, titipan berikutnya untuk
     * pasangan yang sama akan ditolak basis data.
     */
    public function lepas(?int $outboxId = null): void
    {
        $this->forceFill([
            'aktif'       => null,
            'notified_at' => $outboxId ? now() : $this->notified_at,
            'outbox_id'   => $outboxId ?: $this->outbox_id,
        ])->save();
    }
}
