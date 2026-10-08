<?php

namespace App\Modules\CRM\Support;

use App\Models\User;
use App\Modules\CRM\Models\CrmConversation;

/**
 * Berapa chat yang menunggu dibaca — angka untuk lencana menu CRM di sidebar.
 *
 * YANG DIHITUNG CHAT, BUKAN PESAN, dan itu keputusan sadar. Lencananya duduk
 * beberapa piksel dari chip "Belum dibaca" di kepala daftar Inbox, yang sudah
 * menghitung chat; dua angka berbeda untuk pertanyaan yang terdengar sama
 * membuat keduanya berhenti dipercaya. "3 chat menunggu" juga lebih bisa
 * dikerjakan daripada "17 pesan" — yang dibuka orang memang chatnya.
 *
 * CAKUPANNYA = PEKERJAAN ORANG INI, untuk SIAPA PUN termasuk super admin
 * (8 Okt 2026): chat "Belum dioper" (antrean bersama) + chat "Milik Saya".
 * Chat baru di tangan agen lain TIDAK dihitung — itu pekerjaan mereka, dan
 * lencana yang menyala karena pesan orang lain membuat yang melihatnya membuka
 * Inbox untuk menemukan tab kerjanya sendiri kosong.
 */
class BelumDibaca
{
    public static function untuk(?User $pengguna): int
    {
        if (! $pengguna) {
            return 0;
        }

        return CrmConversation::query()
            ->where('status', CrmConversation::STATUS_AKTIF)
            ->where('unread_count', '>', 0)
            ->where(fn ($q) => $q
                ->whereNull('owner_user_id')
                ->orWhere('owner_user_id', $pengguna->id))
            ->count();
    }
}
