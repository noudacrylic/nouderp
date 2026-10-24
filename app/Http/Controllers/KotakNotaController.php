<?php

namespace App\Http\Controllers;

use App\Models\DocumentAttachment;
use App\Services\FotoNotaService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Kotak Nota: foto nota yang dikirim dari PWA HP (/cs/nota) dan belum
 * ditempel ke dokumen. Menempelnya dari halaman dokumen di ERP
 * (FotoNotaController::tempel), bukan dari sini — dokumennyalah yang tahu
 * fotonya milik siapa.
 *
 * Penjaganya FotoNotaService::bolehNota() (punya akses salah satu dokumen
 * yang bisa dilampiri), bukan menu tersendiri: menu PWA dipisah dari menu
 * ERP, dan CS yang khusus chat tidak boleh melihat nota pembelian.
 */
class KotakNotaController extends Controller
{
    public function __construct(private FotoNotaService $service) {}

    // ───────────── ERP ─────────────

    public function index()
    {
        $this->jaga();
        $kotak = FotoNotaService::kotak()->with('uploader:id,name')->get();
        return view('erp.nota-kotak.index', compact('kotak'));
    }

    /** JSON isi kotak untuk panel "Ambil dari HP" — supaya kiriman HP terbaru muncul tanpa reload. */
    public function daftar()
    {
        $this->jaga();
        return response()->json(FotoNotaService::kotakUntukPanel());
    }

    public function gambar($lampiran)
    {
        $this->jaga();
        return FotoNotaService::respon(FotoNotaService::kotak()->findOrFail($lampiran));
    }

    public function destroy(Request $request, $lampiran)
    {
        $this->jaga();
        $this->service->arsipkan(FotoNotaService::kotak()->findOrFail($lampiran), $request->user()?->id);
        return back()->with('success', 'Foto nota dihapus dari kotak (diarsipkan).');
    }

    // ───────────── Dari chat CRM ─────────────

    /**
     * Nota yang dikirim pemasok lewat WA → Kotak Nota langsung dari menu pesan,
     * tanpa unduh lalu unggah ulang. Semua lampiran foto/PDF pesan itu ikut.
     */
    public function dariChat(Request $request, \App\Modules\CRM\Models\CrmMessage $message)
    {
        $this->jaga();

        $chat    = $message->conversation;
        $catatan = 'Dari chat ' . ($chat?->display_name ?: $chat?->contact_key ?: '—')
            . ' · ' . optional($message->created_at)->format('d/m/Y H:i');

        $hasil = $message->attachments->map(fn ($a) => $this->service->simpanDariChat($a, $request->user()->id, $catatan));

        $ok    = $hasil->filter(fn ($h) => $h === 'ok')->count();
        $sudah = $hasil->filter(fn ($h) => $h === 'sudah')->count();

        if ($ok) {
            return response()->json(['success' => true,
                'pesan' => $ok . ' berkas masuk Kotak Nota. Tempel ke fakturnya lewat tombol "Ambil dari HP".']);
        }

        return response()->json(['success' => false, 'pesan' => $sudah
            ? 'Berkas ini sudah ada di Kotak Nota (atau sudah ditempel ke dokumen).'
            : 'Tidak ada foto/PDF yang bisa dijadikan nota di pesan ini.'], 422);
    }

    // ───────────── PWA HP (/cs/nota) ─────────────

    public function pwaIndex(Request $request)
    {
        $this->jaga();
        $milikku = DocumentAttachment::where('uploaded_by', $request->user()->id)
            ->where('created_at', '>=', now()->subDays(14))
            ->latest('id')
            ->limit(60)
            ->get();
        $menunggu = FotoNotaService::kotak()->count();

        return view('cs.nota', compact('milikku', 'menunggu'));
    }

    public function pwaStore(Request $request)
    {
        $this->jaga();
        $request->validate(
            FotoNotaService::rules() + [
                'nota'    => 'required|array|max:' . FotoNotaService::MAKS_BERKAS,
                'catatan' => 'nullable|string|max:190',
            ],
            FotoNotaService::messages() + ['nota.required' => 'Ambil foto nota dulu.'],
        );

        $files = array_filter((array) $request->file('nota', []), fn ($f) => $f && $f->isValid());
        $n = $this->service->simpanKeKotak($files, $request->user()->id, $request->input('catatan'));

        return redirect()->route('cs.nota')->with($n ? 'success' : 'error', $n
            ? $n . ' foto terkirim ke ERP. Tempel ke fakturnya dari PC lewat tombol "Ambil dari HP".'
            : 'Foto gagal terkirim. Coba lagi.');
    }

    /** Foto kiriman sendiri — termasuk yang sudah ditempel, supaya riwayatnya bisa dilihat. */
    public function pwaGambar(Request $request, $lampiran)
    {
        $this->jaga();
        $att = DocumentAttachment::where('uploaded_by', $request->user()->id)->findOrFail($lampiran);
        return FotoNotaService::respon($att);
    }

    /** Batal kirim: hanya foto sendiri yang belum ditempel. */
    public function pwaDestroy(Request $request, $lampiran)
    {
        $this->jaga();
        $att = FotoNotaService::kotak()->where('uploaded_by', $request->user()->id)->findOrFail($lampiran);
        $this->service->arsipkan($att, $request->user()->id);
        return redirect()->route('cs.nota')->with('success', 'Foto dibatalkan.');
    }

    private function jaga(): void
    {
        abort_unless(FotoNotaService::bolehNota(), 403, 'Akun ini tidak punya akses ke Pembelian / Pengeluaran.');
    }
}
