<?php

namespace App\Http\Controllers;

use App\Models\ErpNotification;
use Illuminate\Http\Request;

/**
 * Lonceng notifikasi ERP: daftar, hitungan belum dibaca, dan menandai terbaca.
 *
 * Seluruhnya dibatasi ke milik pengguna yang login — tidak ada satu pun jalur
 * di sini yang menerima user_id dari luar. Notifikasi memuat cuplikan chat
 * pelanggan, dan id yang bisa diketik di URL akan membuat isinya bisa dibaca
 * orang yang bukan penerimanya.
 */
class NotifikasiErpController extends Controller
{
    /** Sebanyak apa yang ditampilkan di panel. Cukup untuk digulir, bukan diarsipkan. */
    private const BATAS = 20;

    public function index(Request $request)
    {
        $userId = (int) $request->user()->id;

        $daftar = ErpNotification::milik($userId)
            ->latest('updated_at')
            ->limit(self::BATAS)
            ->get();

        return response()->json([
            'belum_dibaca' => ErpNotification::milik($userId)->belumDibaca()->count(),
            'daftar'       => $daftar->map(fn (ErpNotification $n) => [
                'id'     => $n->id,
                'jenis'  => $n->jenis,
                'judul'  => $n->judul,
                'isi'    => $n->isi,
                'url'    => $n->url,
                'dibaca' => $n->sudahDibaca(),
                'waktu'  => $n->updated_at?->diffForHumans(),
            ])->values(),
        ]);
    }

    /** Tandai satu terbaca — dipanggil saat notifikasinya diklik. */
    public function baca(Request $request, ErpNotification $notifikasi)
    {
        abort_unless($notifikasi->user_id === (int) $request->user()->id, 404);

        if (! $notifikasi->sudahDibaca()) {
            $notifikasi->forceFill(['read_at' => now()])->save();
        }

        return response()->json(['ok' => true]);
    }

    /** Tandai semua terbaca. */
    public function bacaSemua(Request $request)
    {
        $jumlah = ErpNotification::milik((int) $request->user()->id)
            ->belumDibaca()
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true, 'jumlah' => $jumlah]);
    }
}
