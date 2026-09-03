<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu pesan dalam percakapan — masuk maupun keluar.
 */
class CrmMessage extends Model
{
    public const MASUK  = 'masuk';
    public const KELUAR = 'keluar';

    /** Asal pesan keluar. Lihat komentar kolom `source` di migrasi. */
    public const SOURCE_ERP          = 'erp';
    public const SOURCE_WHATSAPP_APP = 'whatsapp_app';
    public const SOURCE_API          = 'api';

    protected $fillable = [
        'conversation_id', 'direction', 'message_type', 'content', 'source',
        'provider_message_id', 'wam_id', 'reply_to_wam_id',
        'status', 'error', 'sent_by_user_id', 'sent_at', 'raw',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'raw'     => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(CrmConversation::class, 'conversation_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(CrmAttachment::class, 'message_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function isInbound(): bool
    {
        return $this->direction === self::MASUK;
    }

    /**
     * Balasan yang diketik admin langsung dari HP (coexistence), bukan lewat ERP.
     * Ditandai di layar supaya kebocoran triase terlihat, bukan cuma dikeluhkan.
     */
    public function dibalasDariHp(): bool
    {
        return $this->direction === self::KELUAR && $this->source === self::SOURCE_WHATSAPP_APP;
    }

    public function scopeMasuk($query)
    {
        return $query->where('direction', self::MASUK);
    }

    public function scopeKeluar($query)
    {
        return $query->where('direction', self::KELUAR);
    }
}
