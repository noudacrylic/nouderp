<?php

namespace App\Modules\Marketplace\Shopee\Models;

use App\Modules\CRM\Models\CrmConversation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu percakapan chat Shopee (pembeli ↔ toko). Hanya yang `tampil` dicerminkan ke CRM;
 * percakapan yang seluruhnya dilayani AI Shopee tetap dicatat di sini tapi disembunyikan.
 */
class ShopeeChat extends Model
{
    protected $fillable = [
        'env', 'shop_id', 'conversation_id', 'to_id', 'to_name', 'latest_message_id',
        'last_message_at', 'tampil', 'crm_conversation_id',
    ];

    protected $casts = [
        'shop_id'         => 'integer',
        'conversation_id' => 'integer',
        'to_id'           => 'integer',
        'last_message_at' => 'datetime',
        'tampil'          => 'boolean',
    ];

    public function crm(): BelongsTo
    {
        return $this->belongsTo(CrmConversation::class, 'crm_conversation_id');
    }
}
