<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Jejak setiap kiriman webhook yang diterima — sekaligus penangkal duplikat.
 *
 * Vendor mengirim ulang bila balasan 200 terlambat, dan urutan kirim ulang bisa
 * kacau. Jadi jangan pernah mengandalkan "pasti sekali kirim": kunci idempoten
 * yang menjaga, bukan harapan.
 */
class CrmWebhookEvent extends Model
{
    protected $fillable = [
        'idempotency_key', 'event_id', 'event_type', 'payload', 'processed_at', 'error',
    ];

    protected $casts = [
        'payload'      => 'array',
        'processed_at' => 'datetime',
    ];

    /**
     * Catat kiriman baru. Mengembalikan null bila kunci ini sudah pernah masuk —
     * pemanggil cukup membalas 200 dan berhenti.
     */
    public static function catatBaru(string $key, ?string $eventId, ?string $eventType, array $payload): ?self
    {
        if (static::where('idempotency_key', $key)->exists()) {
            return null;
        }

        try {
            return static::create([
                'idempotency_key' => $key,
                'event_id'        => $eventId,
                'event_type'      => $eventType,
                'payload'         => $payload,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Dua kiriman kembar tiba nyaris bersamaan: yang kalah balapan
            // memperlakukannya sebagai duplikat, bukan sebagai galat.
            return null;
        }
    }

    public function tandaiSelesai(): void
    {
        $this->forceFill(['processed_at' => now(), 'error' => null])->save();
    }

    public function tandaiGagal(string $error): void
    {
        $this->forceFill(['error' => $error])->save();
    }
}
