<?php

namespace App\Http\Controllers;

use App\Models\DocumentAttachment;
use App\Services\FotoNotaService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Foto nota di dokumen. Satu controller untuk semua jenis dokumen; jenisnya
 * datang dari `->defaults('jenis', …)` di route. Route-nya sengaja diberi nama
 * di bawah prefix dokumen masing-masing (mis. `purchasing.invoices.nota.*`)
 * supaya hak menu dokumen itu yang berlaku — tanpa entri baru di
 * config/menu_permissions.php.
 *
 * Unggah & hapus tetap bisa setelah dokumen di-POST: dokumen terkunci untuk
 * diedit, tapi notanya sering baru sempat difoto belakangan.
 */
class FotoNotaController extends Controller
{
    public function __construct(private FotoNotaService $service) {}

    public function store(Request $request, $id)
    {
        $jenis = $request->route('jenis');
        $doc   = $this->dokumen($jenis, $id);

        $request->validate(
            FotoNotaService::rules() + ['nota' => 'required|array|max:' . FotoNotaService::MAKS_BERKAS],
            FotoNotaService::messages() + ['nota.required' => 'Pilih foto nota dulu.'],
        );

        $n = $this->service->simpanDariRequest($jenis, $doc, $request);

        return back()->with($n ? 'success' : 'error', $n
            ? $n . ' foto nota tersimpan.'
            : 'Foto nota gagal disimpan. Coba lagi.');
    }

    public function lihat(Request $request, $id, $lampiran)
    {
        return FotoNotaService::respon($this->lampiran($request->route('jenis'), $id, $lampiran));
    }

    /** Tempel foto dari Kotak Nota (kiriman PWA HP) ke dokumen ini. */
    public function tempel(Request $request, $id)
    {
        $jenis = $request->route('jenis');
        $doc   = $this->dokumen($jenis, $id);
        $request->validate(['lampiran_id' => 'required|integer']);

        $att = FotoNotaService::kotak()->findOrFail($request->integer('lampiran_id'));
        try {
            $this->service->tempel($att, $jenis, $doc, $request->user()?->id);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Foto dari HP ditempel ke dokumen ini.');
    }

    /** Putar foto 90°. Dipanggil fetch dari kartu foto nota → JSON berisi URL versi baru. */
    public function putar(Request $request, $id, $lampiran)
    {
        $jenis = $request->route('jenis');
        $att   = $this->lampiran($jenis, $id, $lampiran);
        $request->validate(['arah' => 'required|in:kiri,kanan']);

        try {
            $this->service->putar($att, $request->input('arah'));
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'pesan' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'url' => FotoNotaService::urlLihat($jenis, $att)]);
    }

    public function destroy(Request $request, $id, $lampiran)
    {
        $att = $this->lampiran($request->route('jenis'), $id, $lampiran);
        $this->service->arsipkan($att, $request->user()?->id);

        return back()->with('success', 'Foto nota dihapus (diarsipkan).');
    }

    private function dokumen(string $jenis, $id)
    {
        $kelas = FotoNotaService::modelUntuk($jenis);
        return $kelas::findOrFail($id);
    }

    private function lampiran(string $jenis, $id, $lampiran): DocumentAttachment
    {
        FotoNotaService::modelUntuk($jenis);
        return DocumentAttachment::where('doc_type', $jenis)
            ->where('doc_id', $id)
            ->findOrFail($lampiran);
    }
}
