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
 * CAKUPANNYA SENGAJA SAMA dengan daftar bawaan yang terbuka saat menu CRM
 * ditekan: super admin melihat semua, agen biasa hanya miliknya. Lencana yang
 * menghitung lebih banyak dari yang muncul setelah diklik adalah cara tercepat
 * membuat orang berhenti mempercayainya — ia menjanjikan pekerjaan yang tidak
 * ada di layar yang ia buka.
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
            /*
             * Pembatas yang sama dengan dasarPercakapan() di CrmInboxController:
             * tanpa pilihan pemilik yang disengaja, agen biasa hanya melihat
             * chat yang ia pegang.
             */
            ->when(! $pengguna->isSuperAdmin(), fn ($q) => $q->where('owner_user_id', $pengguna->id))
            ->count();
    }
}
