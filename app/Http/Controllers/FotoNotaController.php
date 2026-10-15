<?php

namespace App\Http\Controllers;

use App\Models\DocumentAttachment;
use App\Services\FotoNotaService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

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
        $att = $this->lampiran($request->route('jenis'), $id, $lampiran);
        abort_unless($att->path && Storage::disk($att->disk)->exists($att->path), 404);

        $nama = $att->original_name ?: basename($att->path);
        return Storage::disk($att->disk)->response($att->path, $nama, [
            'Content-Type'  => $att->mime ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=86400',
        ], 'inline');
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
