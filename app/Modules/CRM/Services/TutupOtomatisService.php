<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmConversation;

/**
 * Tutup chat yang pekerjaannya sudah selesai.
 *
 * Dua kelompok, sama-sama setelah sepi CrmConversation::HARI_TUTUP_OTOMATIS hari:
 *  - chat berlabel Selesai — pesanannya sudah sampai ke pelanggan;
 *  - chat distributor — langganan yang datang dan pergi; begitu ia menulis
 *    lagi, chatnya kembali ke pemilik terakhirnya (lihat model).
 *
 * "Sepi" dihitung dari pesan terakhir, bukan dari saat label dipasang:
 * pelanggan yang masih mengirim ucapan terima kasih atau foto barang yang
 * sudah sampai tidak boleh ditutup di tengah obrolan.
 *
 * Dipanggil penjadwal (crm:tutup-otomatis) DAN tombol manual di layar Label.
 */
class TutupOtomatisService
{
    /** @return int jumlah chat yang ditutup */
    public function jalankan(): int
    {
        return CrmConversation::query()
            ->where('status', CrmConversation::STATUS_AKTIF)
            ->where(fn ($q) => $q
                ->where('queue_state', CrmConversation::LABEL_SELESAI)
                ->orWhere('is_distributor', true))
            ->whereRaw('COALESCE(last_message_at, created_at) <= ?', [now()->subDays(CrmConversation::HARI_TUTUP_OTOMATIS)])
            ->update(CrmConversation::nilaiTutup());
    }
}
