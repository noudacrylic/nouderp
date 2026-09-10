<?php

namespace App\Modules\CRM\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Agents\AgenRunner;
use App\Modules\CRM\Models\CrmAgent;
use App\Modules\CRM\Models\CrmAgentKnowledge;
use App\Modules\CRM\Models\CrmAgentRun;
use App\Modules\CRM\Models\CrmConversation;
use Illuminate\Http\Request;

/**
 * Layar Agen: mengatur, memberi pengetahuan, dan MENGUJI agen AI.
 *
 * Ronde 1 tidak punya jalur balas otomatis sama sekali. Satu-satunya cara agen
 * berjalan adalah tombol Uji di sini — sengaja, supaya seluruh putaran
 * "unggah pengetahuan → uji → perbaiki" bisa dikerjakan tanpa satu pun kalimat
 * sampai ke pelanggan sungguhan.
 */
class CrmAgenController extends Controller
{
    /** Batas isi pengetahuan. Bukan soal ruang — lihat komentar di simpanPengetahuan(). */
    private const MAKS_PENGETAHUAN = 200_000;

    public function index()
    {
        $agen = CrmAgent::orderBy('id')->get();

        // Biaya bulan berjalan per agen — angka yang menjawab "sebenarnya
        // berapa" tanpa perlu menebak dari daftar harga.
        $bulanIni = CrmAgentRun::query()
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('agent_id, COUNT(*) as jumlah, SUM(biaya_rp) as biaya')
            ->groupBy('agent_id')
            ->get()
            ->keyBy('agent_id');

        return view('erp.crm.agen.index', [
            'agen'     => $agen,
            'bulanIni' => $bulanIni,
            'induk'    => (bool) config('crm.agen.aktif', false),
        ]);
    }

    public function edit(CrmAgent $agen)
    {
        return view('erp.crm.agen.edit', [
            'agen'        => $agen,
            'pengetahuan' => $agen->pengetahuan()->with('penulis')->orderByDesc('versi')->get(),
            'aktif'       => $agen->pengetahuanAktif(),
            'model'       => array_keys((array) config('crm.agen.tarif', [])),
            'runs'        => $agen->runs()->latest('id')->limit(20)->get(),
        ]);
    }

    public function update(Request $request, CrmAgent $agen)
    {
        $data = $request->validate([
            'nama'         => ['required', 'string', 'max:80'],
            'persona'      => ['nullable', 'string', 'max:8000'],
            'model'        => ['nullable', 'string', 'max:60'],
            'maks_giliran' => ['required', 'integer', 'min:1', 'max:12'],
            'is_active'    => ['nullable', 'boolean'],
        ]);

        $agen->update($data + ['is_active' => $request->boolean('is_active')]);

        return back()->with('success', 'Pengaturan agen disimpan.');
    }

    /**
     * Simpan pengetahuan sebagai VERSI BARU — dari berkas unggahan atau dari
     * kotak teks yang sudah disunting.
     *
     * Berkas diubah jadi teks lalu ditaruh di kolom, bukan disimpan di disk.
     * Isinya memang teks, ia perlu bisa disunting sesudah diunggah, ikut
     * dicadangkan bersama basis data, dan dibandingkan antar versi — semuanya
     * hilang kalau ia jadi berkas di storage.
     */
    public function simpanPengetahuan(Request $request, CrmAgent $agen)
    {
        $request->validate([
            'isi'     => ['nullable', 'string', 'max:' . self::MAKS_PENGETAHUAN],
            'berkas'  => ['nullable', 'file', 'mimetypes:text/plain,text/markdown,text/x-markdown', 'max:2048'],
            'catatan' => ['nullable', 'string', 'max:255'],
        ], [
            'berkas.mimetypes' => 'Berkas harus teks biasa (.md atau .txt).',
        ]);

        $sumber = null;
        $isi    = (string) $request->input('isi', '');

        /*
         * Berkas MENANG atas kotak teks kalau keduanya terkirim: yang baru saja
         * dipilih orang adalah yang ia maksud, dan kotak teks di layar itu masih
         * berisi versi lama yang belum sempat ia hapus.
         */
        if ($request->hasFile('berkas')) {
            $berkas = $request->file('berkas');
            $isi    = (string) file_get_contents($berkas->getRealPath());
            $sumber = $berkas->getClientOriginalName();
        }

        $isi = trim($isi);

        if ($isi === '') {
            return back()->with('error', 'Pengetahuan kosong — tidak ada yang disimpan.');
        }

        if (mb_strlen($isi) > self::MAKS_PENGETAHUAN) {
            return back()->with('error', 'Pengetahuan terlalu panjang (maksimal '
                . number_format(self::MAKS_PENGETAHUAN, 0, ',', '.') . ' karakter).');
        }

        $versi = CrmAgentKnowledge::simpanVersiBaru(
            $agen,
            $isi,
            $request->input('catatan'),
            $sumber,
            $request->user()?->id
        );

        return back()->with('success', 'Pengetahuan versi ' . $versi->versi . ' disimpan dan dipakai.');
    }

    /** Kembalikan satu versi lama jadi yang berlaku. */
    public function pakaiPengetahuan(CrmAgent $agen, CrmAgentKnowledge $pengetahuan)
    {
        abort_unless($pengetahuan->agent_id === $agen->id, 404);

        $pengetahuan->pakai();

        return back()->with('success', 'Versi ' . $pengetahuan->versi . ' sekarang yang dipakai.');
    }

    /**
     * Jalankan agen sekali, TANPA mengirim apa pun ke pelanggan.
     *
     * Dua sumber pesan: diketik bebas, atau diambil dari percakapan nyata —
     * yang kedua jauh lebih jujur, karena nada agen hanya bisa dinilai
     * berdampingan dengan kalimat pelanggan yang sebenarnya.
     */
    public function uji(Request $request, CrmAgent $agen, AgenRunner $runner)
    {
        $data = $request->validate([
            'pesan'           => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'integer', 'exists:crm_conversations,id'],
        ]);

        $percakapan = $data['conversation_id'] ?? null
            ? CrmConversation::find($data['conversation_id'])
            : null;

        $hasil = $runner->jalankan(
            $agen,
            $data['pesan'],
            $percakapan,
            CrmAgentRun::MODE_UJI,
            $request->user()?->id
        );

        return response()->json([
            'teks'   => $hasil['teks'],
            'status' => $hasil['status'],
            'galat'  => $hasil['galat'],
            'jejak'  => $hasil['jejak'],
            'biaya'  => $hasil['run'] ? 'Rp' . number_format((float) $hasil['run']->biaya_rp, 0, ',', '.') : null,
            'token'  => $hasil['run'] ? [
                'masuk'  => $hasil['run']->token_masuk,
                'cache'  => $hasil['run']->token_cache,
                'keluar' => $hasil['run']->token_keluar,
            ] : null,
            'durasi' => $hasil['run']?->durasi_ms,
        ]);
    }
}
