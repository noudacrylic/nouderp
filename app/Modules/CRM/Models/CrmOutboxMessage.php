<?php

namespace App\Modules\CRM\Models;

use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Antrean & catatan notifikasi keluar (template berbayar).
 *
 * Sengaja berupa tabel, bukan langsung panggil API di dalam observer:
 *  1. idempoten — `dedupe_key` unik menjaga status yang di-set ulang tidak
 *     mengirim pesan kedua ke pelanggan;
 *  2. jam sopan — notifikasi di luar jam kerja ditunda lewat `scheduled_at`;
 *  3. jejak — saat pelanggan bilang "saya tidak dapat kabar", ada barisnya,
 *     lengkap dengan alasan bila dilewati.
 */
class CrmOutboxMessage extends Model
{
    protected $table = 'crm_outbox';

    /** Ketiganya UTILITY di Meta. Satu kalimat promosi = direklasifikasi MARKETING. */
    public const EVENT_PEMBAYARAN  = 'pembayaran_diterima';
    public const EVENT_SIAP_AMBIL  = 'siap_diambil';
    public const EVENT_DIKIRIM     = 'dikirim';

    /** Nama template sebagaimana didaftarkan ke Meta. */
    public const TEMPLATES = [
        self::EVENT_PEMBAYARAN => 'pembayaran_diterima',
        self::EVENT_SIAP_AMBIL => 'pesanan_siap_diambil',
        self::EVENT_DIKIRIM    => 'pesanan_dikirim',
    ];

    public const STATUS_MENUNGGU = 'menunggu';
    public const STATUS_TERKIRIM = 'terkirim';
    public const STATUS_GAGAL    = 'gagal';
    public const STATUS_DILEWATI = 'dilewati';

    protected $fillable = [
        'dedupe_key', 'event', 'sales_order_id', 'conversation_id',
        'recipient', 'template_name', 'template_body',
        'status', 'reason', 'scheduled_at', 'held_since', 'sent_at', 'provider_message_id', 'attempts',
    ];

    protected $casts = [
        'template_body' => 'array',
        'scheduled_at'  => 'datetime',
        'held_since'    => 'datetime',
        'sent_at'       => 'datetime',
        'attempts'      => 'integer',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(CrmConversation::class, 'conversation_id');
    }

    /**
     * Antrekan satu notifikasi. Mengembalikan null bila kunci ini sudah pernah
     * diantrekan — inilah penjaga "satu peristiwa, satu pesan".
     */
    public static function antrekan(string $dedupeKey, array $attributes): ?self
    {
        if (static::where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        try {
            return static::create($attributes + ['dedupe_key' => $dedupeKey]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * @param ?string $reason keterangan yang tetap disimpan meski berhasil —
     *        dipakai eskalasi, supaya terbaca bahwa pesan ini akhirnya
     *        berangkat lewat jalur berbayar, bukan jalur yang dikira.
     */
    public function tandaiTerkirim(?string $providerMessageId, ?string $reason = null): void
    {
        $this->forceFill([
            'status'              => self::STATUS_TERKIRIM,
            'provider_message_id' => $providerMessageId,
            'sent_at'             => now(),
            'attempts'            => $this->attempts + 1,
            'reason'              => $reason,
            'held_since'          => null,
        ])->save();
    }

    public function tandaiGagal(string $reason): void
    {
        $this->forceFill([
            'status'   => self::STATUS_GAGAL,
            'reason'   => $reason,
            'attempts' => $this->attempts + 1,
        ])->save();
    }

    /**
     * Belum bisa dikirim, tapi BUKAN gagal: sesi WhatsApp jalur tak resmi
     * sedang tidak siap (mati, sedang menunggu QR, container restart).
     *
     * Statusnya sengaja tetap 'menunggu' supaya barisnya tetap di dalam antrean
     * dan berangkat sendiri begitu sesinya pulih. Yang bergeser cuma jadwal &
     * alasannya — dan alasan itu yang muncul di layar Notifikasi saat ada yang
     * bertanya "kenapa belum terkirim?".
     */
    public function tandaiTertahan(string $reason, \Illuminate\Support\Carbon $cobaLagi): void
    {
        $this->forceFill([
            'status'       => self::STATUS_MENUNGGU,
            'reason'       => $reason,
            'scheduled_at' => $cobaLagi,
            // Dicatat sekali, pada penahanan PERTAMA. Kalau di-set ulang setiap
            // percobaan, jamnya mundur terus dan batas eskalasi tak pernah
            // tercapai — pesan menggantung selamanya tanpa ada yang sadar.
            'held_since'   => $this->held_since ?: now(),
            'attempts'     => $this->attempts + 1,
        ])->save();
    }

    /** Sudah tertahan lebih lama dari batas yang ditetapkan? */
    public function tertahanLebihDari(int $jam): bool
    {
        return $this->held_since !== null && $this->held_since->lte(now()->subHours($jam));
    }

    /**
     * Tidak jadi dikirim, dan itu memang benar — mis. pesanan marketplace
     * (nomor proxy + melanggar aturan platform) atau pelanggan belum opt-in.
     * Barisnya tetap ada supaya alasannya bisa dilihat belakangan.
     */
    public function tandaiDilewati(string $reason): void
    {
        $this->forceFill(['status' => self::STATUS_DILEWATI, 'reason' => $reason])->save();
    }

    /** Sudah waktunya dikirim (dan belum pernah terkirim). */
    public function scopeJatuhTempo($query)
    {
        return $query->where('status', self::STATUS_MENUNGGU)
            ->where(fn ($q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()));
    }
}
