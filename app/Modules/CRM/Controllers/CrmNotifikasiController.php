<?php

namespace App\Modules\CRM\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmOutboxMessage;
use App\Models\CrmSetting;
use App\Modules\CRM\Services\CrmOutboxSender;
use App\Modules\CRM\Support\CrmRuntimeConfig;
use App\Modules\CRM\Support\JenisNotifikasi;
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

        /*
         * Hitungan per JENIS, bukan sekadar total. Pertanyaan yang sebenarnya
         * ditanyakan orang adalah "notifikasi dikirim ini jalan tidak?" —
         * dan itu tak terjawab oleh satu angka gabungan.
         */
        $hitungan = [];

        foreach (CrmOutboxMessage::selectRaw('event, status, COUNT(*) as total')
            ->groupBy('event', 'status')->get() as $baris) {
            $hitungan[$baris->event][$baris->status] = (int) $baris->total;
        }

        return view('erp.crm.notifikasi.index', [
            'jenis'   => JenisNotifikasi::katalog($hitungan),
            'daftar'  => $daftar,
            'jumlah'  => CrmOutboxMessage::selectRaw('status, COUNT(*) as total')
                ->groupBy('status')->pluck('total', 'status')->all(),
            'dryRun'  => app(ChatManager::class)->isDryRun(),
        ]);
    }

    /**
     * Nyalakan/matikan jenis notifikasi.
     *
     * Ditaruh di layar ini, bukan di Pengaturan, karena di sinilah orang
     * melihat akibatnya: teks yang dikirim dan berapa yang sudah berangkat.
     * Saklar yang jauh dari akibatnya adalah saklar yang ditekan tanpa tahu
     * apa yang berubah.
     */
    public function simpanJenis(Request $request)
    {
        $data = $request->validate(['aktif' => 'nullable|array']);

        $peta = [];

        foreach (array_keys(JenisNotifikasi::DAFTAR) as $event) {
            $peta[$event] = (bool) ($data['aktif'][$event] ?? false);
        }

        $setting = CrmSetting::for('apicoid');
        $setting->config = array_merge((array) $setting->config, ['notifikasi_aktif' => $peta]);
        $setting->save();

        CrmRuntimeConfig::forget();
        CrmRuntimeConfig::apply();

        $mati = array_keys(array_filter($peta, fn ($v) => ! $v));

        return back()->with('success', $mati
            ? 'Tersimpan. DIMATIKAN: ' . implode(', ', array_map([JenisNotifikasi::class, 'label'], $mati))
              . ' — pesanan yang memicunya tetap dicatat sebagai "dilewati".'
            : 'Tersimpan. Ketiga jenis notifikasi aktif.');
    }

    /**
     * Tombol manual pendamping cron — fitur otomatis selalu punya pemicu tangan,
     * dan logikanya satu (CrmOutboxSender) dipanggil dari dua tempat.
     */
    public function kirimSekarang(CrmOutboxSender $sender)
    {
        $hasil = $sender->kirimYangJatuhTempo();

        return back()->with('success', "Antrean dikuras: {$hasil['terkirim']} terkirim, {$hasil['gagal']} gagal, {$hasil['tertahan']} tertahan.");
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
