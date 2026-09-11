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

    /** Asal `display_name` — lihat migrasi add_name_source_to_crm_conversations. */
    public const NAMA_WHATSAPP = 'whatsapp';
    public const NAMA_MANUAL   = 'manual';

    protected $fillable = [
        'channel', 'contact_key', 'display_name', 'name_source', 'name_checked_at',
        'provider_customer_id', 'business_number_id',
        'customer_id', 'owner_user_id', 'queue_state', 'status',
        'window_expires_at', 'pancingan_untuk_jendela_at',
        'last_inbound_at', 'last_outbound_at', 'last_message_at',
        'unread_count', 'notes', 'order_draft',
    ];

    protected $casts = [
        'order_draft'       => 'array',
        'name_checked_at'   => 'datetime',
        'window_expires_at' => 'datetime',
        'pancingan_untuk_jendela_at' => 'datetime',
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
     * Nama yang dipakai di layar untuk percakapan ini.
     *
     * Urutannya sengaja: nama pelanggan ERP (paling bermakna) → nama profil
     * WhatsApp → nomor telepon sebagai jaring terakhir. Dikumpulkan di sini
     * karena urutan yang sama sudah tersebar di kepala thread, daftar kiri, dan
     * pemilih tujuan teruskan — dan yang tercecer cepat jadi berbeda-beda.
     */
    public function namaTampil(): string
    {
        return $this->customer->name ?? $this->display_name ?? $this->contact_key;
    }

    /**
     * Sapaan siap tempel untuk pesan yang dikirim KE pelanggan ini.
     *
     * Beda dari namaTampil(), dan bedanya disengaja: namaTampil() jatuh ke
     * nomor telepon sebagai jaring terakhir — benar untuk layar kita, keliru
     * untuk pesan. "Halo Kak 6289…" yang dibaca pelanggan terbaca seperti
     * robot yang salah sasaran. Tanpa nama, "Kak" saja sudah sopan dan wajar.
     */
    public function sapaan(): string
    {
        $nama = $this->namaUntukPesan();

        return $nama !== null ? 'Kak ' . $nama : 'Kak';
    }

    /**
     * Nama yang PANTAS ditulis di pesan ke pelanggan, null bila tak ada.
     *
     * Nama profil WhatsApp sengaja TIDAK ikut: isinya terserah pemilik akun —
     * nama toko, emoji, "Mama Rafa 🌸" — bagus untuk mengenali chat di layar
     * kita, tapi "Halo Kak Toko Berkah Jaya" terbaca seperti salah sasaran.
     * Yang lolos hanya nama pelanggan ERP dan nama yang sengaja diketik CS.
     */
    public function namaUntukPesan(): ?string
    {
        $nama = trim((string) ($this->customer->name
            ?? ($this->name_source === self::NAMA_MANUAL ? $this->display_name : null)
            ?? ''));

        return $nama !== '' ? $nama : null;
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

    /**
     * Sisa jendela dalam MENIT, null bila sudah tertutup.
     *
     * Ada karena jam saja terlalu kasar di ujung: `windowHoursLeft()`
     * mengembalikan 0 untuk apa pun di bawah satu jam, sehingga "tinggal 55
     * menit" dan "tinggal 30 detik" tak bisa dibedakan — padahal justru di
     * rentang itulah keputusan bertanya ke vendor diambil.
     */
    public function windowMinutesLeft(): ?int
    {
        return $this->windowIsOpen() ? (int) now()->diffInMinutes($this->window_expires_at, false) : null;
    }

    /**
     * Jendela masih terbuka menurut catatan kita, TAPI tinggal sedikit.
     *
     * Inilah satu-satunya rentang yang layak diverifikasi ke vendor: di luar
     * itu, selisih beberapa menit antara jam kita dan jam Meta tak pernah
     * mengubah keputusan apa pun.
     */
    public function windowHampirTutup(int $menit = 60): bool
    {
        return $this->windowIsOpen() && (int) $this->windowMinutesLeft() <= $menit;
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
