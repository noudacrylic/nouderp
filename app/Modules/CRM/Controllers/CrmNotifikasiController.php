<?php

namespace App\Modules\CRM\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmOutboxMessage;
use App\Modules\CRM\Services\CrmOutboxSender;
use Illuminate\Http\Request;

/**
 * Daftar notifikasi pesanan: yang terkirim, gagal, menunggu, dan DILEWATI.
 *
 * Yang dilewati justru bagian terpentingnya. Pertanyaan yang pasti muncul
 * adalah "kenapa pelanggan ini tidak dapat kabar?", dan jawabannya (marketplace,
 * belum opt-in, nomor kosong) tersimpan di kolom alasan — layar ini yang
 * membuatnya bisa dibaca tanpa membuka basis data.
 */
class CrmNotifikasiController extends Controller
{
    public function index(Request $request)
    {
        $daftar = CrmOutboxMessage::query()
            ->with('salesOrder:id,order_number')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('event'), fn ($q) => $q->where('event', $request->event))
            ->orderByDesc('id')
            ->paginate(per_page_size())
            ->withQueryString();

        return view('erp.crm.notifikasi.index', [
            'daftar'  => $daftar,
            'jumlah'  => CrmOutboxMessage::selectRaw('status, COUNT(*) as total')
                ->groupBy('status')->pluck('total', 'status')->all(),
            'dryRun'  => app(ChatManager::class)->isDryRun(),
        ]);
    }

    /**
     * Tombol manual pendamping cron — fitur otomatis selalu punya pemicu tangan,
     * dan logikanya satu (CrmOutboxSender) dipanggil dari dua tempat.
     */
    public function kirimSekarang(CrmOutboxSender $sender)
    {
        $hasil = $sender->kirimYangJatuhTempo();

        return back()->with('success', "Antrean dikuras: {$hasil['terkirim']} terkirim, {$hasil['gagal']} gagal.");
    }

    /** Coba lagi satu notifikasi yang gagal. */
    public function ulangi(CrmOutboxMessage $outbox, CrmOutboxSender $sender)
    {
        if ($outbox->status === CrmOutboxMessage::STATUS_DILEWATI) {
            return back()->with('error', 'Notifikasi ini sengaja dilewati: ' . $outbox->reason);
        }

        $outbox->forceFill(['status' => CrmOutboxMessage::STATUS_MENUNGGU, 'scheduled_at' => null])->save();

        return $sender->kirimSatu($outbox)
            ? back()->with('success', 'Notifikasi terkirim.')
            : back()->with('error', 'Masih gagal: ' . $outbox->fresh()->reason);
    }
}
