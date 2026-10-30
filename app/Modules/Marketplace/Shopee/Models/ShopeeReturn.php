<?php

namespace App\Modules\Marketplace\Shopee\Models;

use App\Modules\Marketplace\Shopee\Services\ShopeeReturLamaService;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu pengajuan retur Shopee (salinan get_return_list, lihat ShopeeReturService::tarik).
 */
class ShopeeReturn extends Model
{
    /** Penjual sudah menyengketakan / sedang dinilai Shopee. */
    public const STATUS_SENGKETA = ['JUDGING', 'SELLER_DISPUTE'];

    protected $fillable = [
        'env', 'shop_id', 'return_sn', 'order_sn', 'status', 'reason', 'text_reason', 'refund_amount',
        'needs_logistics', 'negotiation_status', 'seller_proof_status', 'seller_compensation_status',
        'seller_due_at', 'dibuat_shopee_at', 'diperbarui_shopee_at', 'raw', 'disinkron_at',
    ];

    protected $casts = [
        'shop_id'              => 'integer',
        'refund_amount'        => 'decimal:2',
        'needs_logistics'      => 'boolean',
        'seller_due_at'        => 'datetime',
        'dibuat_shopee_at'     => 'datetime',
        'diperbarui_shopee_at' => 'datetime',
        'raw'                  => 'array',
        'disinkron_at'         => 'datetime',
    ];

    public function final(): bool
    {
        return in_array($this->status, ShopeeReturLamaService::STATUS_RETUR_FINAL, true);
    }

    /** Sedang dibanding: status sengketa, atau bukti penjual sudah/masih diminta. */
    public function disengketakan(): bool
    {
        return in_array($this->status, self::STATUS_SENGKETA, true)
            || in_array($this->seller_proof_status, ['PENDING', 'UPLOADED'], true);
    }

    /** Bentuk yang dipakai ShopeeReturLamaService (sama dengan baris get_return_list). */
    public function sebagaiApi(): array
    {
        return [
            'return_sn' => $this->return_sn, 'status' => $this->status, 'reason' => $this->reason,
            'refund_amount' => (float) $this->refund_amount, 'needs_logistics' => $this->needs_logistics,
        ];
    }
}
