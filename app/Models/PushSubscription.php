<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Langganan Web Push satu perangkat. Dibuat saat karyawan menekan
 * "Aktifkan Notifikasi" di PWA. Lihat WebPushNotifier.
 */
class PushSubscription extends Model
{
    protected $fillable = [
        'user_id', 'endpoint', 'public_key', 'auth_token',
        'content_encoding', 'user_agent',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
