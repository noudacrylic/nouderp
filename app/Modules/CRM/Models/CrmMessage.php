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
    /**
     * Balasan yang DICATAT tapi tidak pernah keluar karena saklar jangan-kirim.
     * Statusnya sengaja dibedakan dari 'terkirim': menandai pesan yang tak
     * pernah sampai sebagai "terkirim" membuat admin mengira pelanggan sudah
     * dijawab, lalu menunggu balasan yang tak akan pernah datang.
     */
    public const STATUS_TIDAK_DIKIRIM = 'tidak_dikirim';

    public const SOURCE_ERP          = 'erp';
    public const SOURCE_WHATSAPP_APP = 'whatsapp_app';
    public const SOURCE_API          = 'api';

    protected $fillable = [
        'conversation_id', 'direction', 'message_type', 'content', 'source',
        'provider_message_id', 'wam_id', 'reply_to_wam_id', 'forwarded_from_message_id',
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

    /**
     * Id yang dipakai MENGUTIP pesan ini — wamid Meta, bukan id vendor.
     *
     * Ini pelajaran mahal dari uji kirim 7 Sep 2026. Vendor punya id sendiri
     * (bentuk `cmtp…`) dan WhatsApp punya wamid (`wamid.HBg…`). Penanda balasan
     * yang diisi id vendor DITERIMA API tanpa keluhan — jawabannya `success`,
     * pesannya terkirim, centangnya naik — lalu kutipannya diabaikan diam-diam.
     * Di ERP kutipannya tergambar rapi, di HP pelanggan pesannya datang polos.
     * Tidak ada satu pun gejala yang menunjukkan ada yang salah.
     *
     * Null berarti pesan ini memang belum bisa dikutip; lihat bisaDikutip().
     */
    public function idKutipan(): ?string
    {
        return $this->wam_id;
    }

    /**
     * Boleh dikutip?
     *
     * Pesan MASUK selalu bisa (wamid-nya ikut di webhook sejak detik pertama).
     * Pesan KELUAR baru bisa setelah sampai: wamid-nya menumpang peristiwa
     * 'delivered'/'read', sementara peristiwa 'sent' belum membawanya. Sengaja
     * TIDAK ada jalan mundur ke id vendor — mengirimkannya berarti mengulang
     * kegagalan senyap yang sama.
     */
    public function bisaDikutip(): bool
    {
        return $this->wam_id !== null;
    }

    /**
     * Pesan yang DIKUTIP oleh pesan ini (balasan ala WhatsApp).
     *
     * Dua relasi karena `reply_to_wam_id` menyimpan dua ruang id yang berbeda:
     * baris baru menyimpan wamid (yang benar), sedangkan baris warisan dari
     * sebelum 7 Sep 2026 menyimpan id vendor. Yang lama sengaja tetap bisa
     * ditemukan — kalau tidak, kutipan di riwayat lama berubah jadi tulisan
     * "pesan tidak ada" yang membingungkan padahal pesannya ada di layar.
     */
    public function dikutipWamid(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_wam_id', 'wam_id');
    }

    /** Jalur warisan; lihat dikutipWamid(). */
    public function dikutipVendor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_wam_id', 'provider_message_id');
    }

    /**
     * Pesan yang dikutip, dari ruang id mana pun.
     *
     * Bisa null walau `reply_to_wam_id` terisi — yang dikutip mungkin lebih tua
     * dari ERP ini, atau sudah dihapus. Tampilan wajib menyiapkan itu.
     */
    public function pesanDikutip(): ?self
    {
        return $this->dikutipWamid ?: $this->dikutipVendor;
    }

    public function diteruskanDari(): BelongsTo
    {
        return $this->belongsTo(self::class, 'forwarded_from_message_id');
    }

    /**
     * Satu baris ringkas untuk kotak kutipan — bukan isi pesan penuh.
     *
     * Pesan tanpa teks (foto polos, dokumen) tetap harus punya bunyi: kutipan
     * kosong terlihat seperti kutipan yang rusak, dan admin tak tahu pesan mana
     * yang sedang dibalas.
     */
    public function ringkas(int $batas = 90): string
    {
        $isi = trim((string) $this->content);

        if ($isi === '') {
            $isi = match ($this->message_type) {
                'image'    => '📷 Foto',
                'video'    => '🎬 Video',
                'audio'    => '🎤 Pesan suara',
                'document' => '📄 ' . ($this->attachments->first()?->original_name ?: 'Dokumen'),
                default    => 'Pesan',
            };
        }

        // Kutipan selalu SATU baris; pesan panjang berbaris-baris akan
        // mendorong gelembungnya jadi setinggi layar.
        $isi = preg_replace('/\s+/u', ' ', $isi);

        return mb_strlen($isi) > $batas ? mb_substr($isi, 0, $batas - 1) . '…' : $isi;
    }

    /** Label pengirim di kotak kutipan, seperti WhatsApp menulis nama di atas kutipan. */
    public function labelPengirim(): string
    {
        if (! $this->isInbound()) {
            return 'Anda';
        }

        return $this->conversation?->namaTampil() ?? 'Pelanggan';
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

    /**
     * Penanda kirim ala WhatsApp: jam → satu centang → dua centang → biru.
     *
     * 'failed' dan 'tidak_dikirim' sengaja TIDAK punya centang. Keduanya sudah
     * memakai lencana bertulis yang membawa alasannya; menambah ikon merah di
     * sebelahnya cuma mengulang hal yang sama dua kali.
     *
     * Keadaan 'menunggu' tidak pernah lahir dari sini — hanya dipakai gelembung
     * sementara di layar, yang memang belum punya baris di basis data.
     */
    public function centang(): string
    {
        if ($this->isInbound()) {
            return 'tidak';
        }

        return match ($this->status) {
            'read'      => 'dibaca',
            'delivered' => 'sampai',
            'terkirim'  => 'terkirim',
            default     => 'tidak',
        };
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
