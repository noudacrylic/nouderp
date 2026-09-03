<?php

namespace App\Modules\CRM\Models;

use App\Models\Customer;
use App\Models\User;
use App\Modules\CRM\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu percakapan dengan satu kontak, pada satu kanal, lewat satu nomor bisnis.
 *
 * Ini juga WADAH LEAD: lead = percakapan yang belum punya dokumen ERP. Karena
 * itu tidak ada tabel lead terpisah dan master pelanggan tidak dikotori oleh
 * setiap orang yang baru bertanya-tanya.
 */
class CrmConversation extends Model
{
    /** Antrean berbasis "bola di siapa", bukan sekadar "sudah/belum dikerjakan". */
    public const QUEUE_KITA      = 'menunggu_kita';      // paling menonjol di layar
    public const QUEUE_PELANGGAN = 'menunggu_pelanggan';
    public const QUEUE_DESAIN    = 'menunggu_desain';
    public const QUEUE_DINGIN    = 'dingin';

    public const QUEUE_LABELS = [
        self::QUEUE_KITA      => 'Menunggu Kita',
        self::QUEUE_PELANGGAN => 'Menunggu Pelanggan',
        self::QUEUE_DESAIN    => 'Menunggu Desain',
        self::QUEUE_DINGIN    => 'Dingin',
    ];

    public const STATUS_AKTIF = 'aktif';
    public const STATUS_ARSIP = 'arsip';

    protected $fillable = [
        'channel', 'contact_key', 'display_name', 'provider_customer_id', 'business_number_id',
        'customer_id', 'owner_user_id', 'queue_state', 'status',
        'window_expires_at', 'last_inbound_at', 'last_outbound_at', 'last_message_at',
        'unread_count', 'notes',
    ];

    protected $casts = [
        'window_expires_at' => 'datetime',
        'last_inbound_at'   => 'datetime',
        'last_outbound_at'  => 'datetime',
        'last_message_at'   => 'datetime',
        'unread_count'      => 'integer',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(CrmMessage::class, 'conversation_id');
    }

    /** Notifikasi pesanan yang pernah dikirim lewat percakapan ini. */
    public function outboxMessages(): HasMany
    {
        return $this->hasMany(CrmOutboxMessage::class, 'conversation_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * Cari atau buat percakapan untuk satu kontak. Nomor dinormalkan lebih dulu
     * supaya '0899…' dan '+62 899…' tidak melahirkan dua percakapan terpisah.
     */
    public static function findOrCreateFor(
        string $contact,
        string $channel = 'whatsapp',
        ?string $businessNumberId = null,
        array $attributes = []
    ): self {
        $key = $channel === 'whatsapp'
            ? (PhoneNumber::normalize($contact) ?? $contact)
            : ltrim(trim($contact), '@');

        /*
         * Nomor bisnis tidak disebut? Pakai percakapan yang sudah ada untuk
         * kontak ini, jangan bikin yang baru. Sebagian peristiwa webhook
         * memuat field itu dan sebagian tidak — kalau ketiadaannya dianggap
         * identitas tersendiri, satu orang pecah jadi dua thread dan balasan
         * admin mendarat di thread yang bukan tempat pelanggan menulis.
         */
        if ($businessNumberId === null) {
            $adaDuluan = static::where('channel', $channel)
                ->where('contact_key', $key)
                ->orderBy('id')
                ->first();

            if ($adaDuluan) {
                return $adaDuluan;
            }
        }

        return static::firstOrCreate(
            ['channel' => $channel, 'contact_key' => $key, 'business_number_id' => $businessNumberId],
            $attributes
        );
    }

    /**
     * Jendela 24 jam masih terbuka?
     *
     * Dipakai layar Inbox untuk menandai thread SEBELUM admin mengetik panjang
     * lebar — tanpa penanda ini admin mengetik, ditolak API, lalu kembali
     * membalas dari HP, dan sistemnya mati sebelum sempat dipakai.
     */
    public function windowIsOpen(): bool
    {
        return $this->window_expires_at !== null && $this->window_expires_at->isFuture();
    }

    /** Sisa jendela dalam jam (dibulatkan ke bawah), null bila sudah tertutup. */
    public function windowHoursLeft(): ?int
    {
        return $this->windowIsOpen() ? (int) now()->diffInHours($this->window_expires_at, false) : null;
    }

    public function scopeAktif($query)
    {
        return $query->where('status', self::STATUS_AKTIF);
    }

    public function scopeAntrean($query, string $queueState)
    {
        return $query->where('queue_state', $queueState);
    }

    /** Lead = percakapan yang belum tertaut ke pelanggan mana pun di ERP. */
    public function scopeBelumDikenali($query)
    {
        return $query->whereNull('customer_id');
    }
}
