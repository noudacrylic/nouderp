<?php

namespace App\Http\Controllers\Cs;

use App\Models\ErpNotification;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Kerangka PWA CRM (`/cs`) — aplikasi chat CS di HP.
 *
 * Controller ini memegang dua layar yang berdiri di luar percakapan: Notifikasi & Profil.
 * Daftar chat & dialognya dilayani CrmInboxController (data ruang kerja yang
 * sama dengan Inbox desktop).
 */
class ShellController extends Controller
{
    /** Sebanyak apa yang ditampilkan. Cukup untuk digulir, bukan diarsipkan. */
    private const BATAS_NOTIF = 30;

    public function notifikasi(Request $request)
    {
        $userId = (int) $request->user()->id;

        $daftar = ErpNotification::milik($userId)
            ->latest('updated_at')
            ->limit(self::BATAS_NOTIF)
            ->get();

        return view('cs.notifikasi', [
            'daftar'         => $daftar,
            'navBelumDibaca' => $this->belumDibaca(),
        ]);
    }

    public function profil()
    {
        return view('cs.profil', [
            'navBelumDibaca' => $this->belumDibaca(),
        ]);
    }

    private function belumDibaca(): int
    {
        return ErpNotification::milik((int) auth()->id())->belumDibaca()->count();
    }
}
