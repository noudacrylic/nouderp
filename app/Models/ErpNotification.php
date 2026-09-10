<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu notifikasi milik satu orang, tersimpan di ERP.
 *
 * Bedanya dengan web push: yang ini tidak hilang. Push lewat sekali lalu tak
 * bisa ditarik lagi; baris ini menunggu sampai dibaca. Untuk operan chat itu
 * bukan kemewahan melainkan syarat — pekerjaan yang berpindah tangan tidak
 * boleh bergantung pada apakah orangnya kebetulan membuka peramban saat itu.
 */
class ErpNotification extends Model
{
    public const CHAT_MASUK  = 'chat_masuk';
    public const CHAT_DIOPER = 'chat_dioper';

    protected $fillable = ['user_id', 'jenis', 'judul', 'isi', 'url', 'tag', 'read_at'];

    protected $casts = ['read_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeBelumDibaca(Builder $q): Builder
    {
        return $q->whereNull('read_at');
    }

    public function scopeMilik(Builder $q, int $userId): Builder
    {
        return $q->where('user_id', $userId);
    }

    public function sudahDibaca(): bool
    {
        return $this->read_at !== null;
    }
}
