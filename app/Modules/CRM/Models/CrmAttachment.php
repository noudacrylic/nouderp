<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lampiran satu pesan (logo, foto acuan, bukti pesanan, revisi desain).
 *
 * Formatnya SENGAJA tidak diklasifikasi — manusia yang menggarap, jadi tampilkan
 * apa adanya: pratinjau bila bisa, selebihnya tombol unduh.
 */
class CrmAttachment extends Model
{
    protected $fillable = [
        'message_id', 'disk', 'path', 'provider_media_id', 'source_url',
        'original_name', 'mime', 'size_bytes', 'downloaded_at', 'download_error', 'purged_at',
    ];

    protected $casts = [
        'downloaded_at' => 'datetime',
        'purged_at'     => 'datetime',
        'size_bytes'    => 'integer',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(CrmMessage::class, 'message_id');
    }

    /**
     * Sudah tersimpan di penyimpanan kita sendiri?
     *
     * Selama false, satu-satunya salinan ada di server Meta yang menghapusnya
     * setelah ~30 hari — dan diskusi custom bisa menggantung lebih lama dari itu.
     */
    public function tersimpanAman(): bool
    {
        return $this->downloaded_at !== null && ! empty($this->path);
    }

    public function isGambar(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    /** Belum terunduh & belum pernah gagal — antrean kerja job pengunduh. */
    public function scopeBelumTerunduh($query)
    {
        return $query->whereNull('downloaded_at')->whereNull('download_error')->whereNull('purged_at');
    }

    /** Berkasnya sudah disapu masa simpan — barisnya sengaja tetap ada. */
    public function sudahDisapu(): bool
    {
        return $this->purged_at !== null;
    }

    /**
     * Lampiran yang BOLEH disapu setelah $hari: hanya milik percakapan yang tak
     * tertaut apa pun di ERP — lead yang mati, basa-basi, tangkapan layar yang
     * sudah selesai dipakai.
     *
     * Yang percakapannya sudah tertaut pelanggan atau punya notifikasi pesanan
     * TIDAK PERNAH ikut: di situlah logo yang sudah tercetak berada, dan berkas
     * itu tak bisa diambil lagi dari mana pun setelah ~30 hari.
     * (Tahap 7 menambah Penawaran sebagai penanda tertaut berikutnya.)
     */
    public function scopeBisaDisapu($query, int $hari)
    {
        return $query->whereNull('purged_at')
            ->whereNotNull('path')
            ->where('created_at', '<', now()->subDays($hari))
            ->whereHas('message.conversation', fn ($c) => $c
                ->whereNull('customer_id')
                ->whereDoesntHave('outboxMessages'));
    }
}
