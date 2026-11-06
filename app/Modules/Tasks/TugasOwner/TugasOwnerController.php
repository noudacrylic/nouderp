<?php

namespace App\Modules\Tasks\TugasOwner;

use App\Http\Controllers\Controller;
use App\Modules\Galeri\Services\GaleriException;
use App\Modules\Tasks\TugasOwner\Models\TugasOwner as Tugas;
use App\Modules\Tasks\TugasOwner\Models\TugasOwnerLampiran as Lampiran;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Halaman Tugas dari Owner; dibuka dari tombol di Meja Kerja. */
class TugasOwnerController extends Controller
{
    public function __construct(private TugasOwnerService $layanan) {}

    public function index(Request $request)
    {
        $u = $request->user();
        $semua = $this->layanan->untuk($u)->with('pengerja:id,name')->latest('id')->limit(200)->get();
        $aktif = $semua->reject(fn (Tugas $t) => in_array($t->tahap, [Tugas::SELESAI, Tugas::BATAL], true));

        return view('erp.tasks.tugas-owner.index', [
            'giliranSaya' => $aktif->filter(fn (Tugas $t) => $this->layanan->giliran($u, $t))->values(),
            'menungguLain' => $aktif->reject(fn (Tugas $t) => $this->layanan->giliran($u, $t))->values(),
            'riwayat'     => $semua->diff($aktif)->take(30)->values(),
            'owner'       => $this->layanan->owner($u),
            'calon'       => $this->layanan->calonPengerja(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->layanan->owner($request->user()), 403, 'Hanya owner yang memberi tugas.');
        $data = $request->validate([
            'judul'         => 'required|string|max:200',
            'jenis'         => ['required', Rule::in(array_keys(config('tugas_owner.jenis')))],
            'pengerja_id'   => 'required|integer|exists:users,id',
            'tenggat'       => 'nullable|date',
            'prioritas'     => ['nullable', Rule::in(array_keys(\App\Modules\Tasks\MejaKerja\Skills\TugasOwnerSkill::PRIORITAS))],
            'tanpa_periksa' => 'nullable|boolean',
        ]);

        $t = Tugas::create([
            'judul' => $data['judul'], 'jenis' => $data['jenis'], 'pengerja_id' => $data['pengerja_id'], 'tenggat' => $data['tenggat'] ?? null, 'prioritas' => $data['prioritas'] ?? 'normal',
            'wajib_periksa' => !$request->boolean('tanpa_periksa'), 'dibuat_oleh' => $request->user()->id, 'tahap' => Tugas::DRAF,
        ]);

        return redirect()->route('tasks.meja.tugas.show', $t->id);
    }

    public function show(Request $request, Tugas $tugas)
    {
        $u = $request->user();
        abort_unless($this->layanan->bolehLihat($u, $tugas), 403, 'Tugas ini bukan untuk Anda.');
        $simpan = $this->layanan->penyimpanan();

        return view('erp.tasks.tugas-owner.show', [
            't'         => $tugas->load(['pembuat:id,name', 'pengerja:id,name', 'pesan.user:id,name', 'pesan.lampiran']),
            'aksi'      => $this->layanan->aksiTersedia($u, $tugas),
            'tersambung' => $simpan->tersambung(),
            'potongan'  => $simpan->ukuranPotongan(),
            'maksMb'    => (int) config('galeri.maks_mb'),
            'bolehAturPrioritas' => $this->layanan->owner($u) && !in_array($tugas->tahap, [Tugas::SELESAI, Tugas::BATAL], true),
        ]);
    }

    /** Owner mengubah letak tugas ini di meja admin (Utama / Normal / Rendah). */
    public function prioritas(Request $request, Tugas $tugas)
    {
        abort_unless($this->layanan->owner($request->user()), 403, 'Hanya owner yang mengatur prioritas.');
        $data = $request->validate(['prioritas' => ['required', Rule::in(array_keys(\App\Modules\Tasks\MejaKerja\Skills\TugasOwnerSkill::PRIORITAS))]]);
        $tugas->update(['prioritas' => $data['prioritas']]);

        return back()->with('success', 'Prioritas tugas: ' . \App\Modules\Tasks\MejaKerja\Skills\TugasOwnerSkill::PRIORITAS[$data['prioritas']] . '. Letak kartunya di meja admin menyesuaikan dalam ±2 menit.');
    }

    public function aksi(Request $request, Tugas $tugas, string $aksi)
    {
        abort_unless($this->layanan->bolehLihat($request->user(), $tugas), 403, 'Tugas ini bukan untuk Anda.');
        $data = $request->validate(['isi' => 'nullable|string|max:5000', 'lampiran' => 'nullable|array', 'lampiran.*' => 'integer']);

        try {
            $this->layanan->jalankan($tugas, $aksi, $request->user(), $data['isi'] ?? null, $data['lampiran'] ?? []);
        } catch (TugasOwnerException $e) {
            abort_if($e->status === 403, 403, $e->getMessage());
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('tasks.meja.tugas.show', $tugas->id);
    }

    public function unggah(Request $request, Tugas $tugas)
    {
        abort_unless($this->layanan->bolehLihat($request->user(), $tugas), 403, 'Tugas ini bukan untuk Anda.');
        $request->validate([
            'id' => 'required|string', 'offset' => 'required|integer|min:0', 'total' => 'required|integer|min:1',
            'nama' => 'required|string|max:255', 'rekaman' => 'nullable|in:suara,layar', 'potongan' => 'required|file',
        ]);

        try {
            return response()->json($this->layanan->terimaPotongan($tugas, $request->user(), (string) $request->input('id'), (int) $request->input('offset'),
                (int) $request->input('total'), (string) $request->input('nama'), $request->input('rekaman'), $request->file('potongan')));
        } catch (GaleriException $e) {
            return $e->diterima !== null
                ? response()->json(['pesan' => $e->getMessage(), 'diterima' => $e->diterima], 409)
                : response()->json(['pesan' => $e->getMessage()], 422);
        }
    }

    /** Melayani Range: video brief bisa digeser. */
    public function lampiran(Request $request, Lampiran $lampiran)
    {
        abort_unless($this->layanan->bolehLihat($request->user(), $lampiran->tugas), 403);
        try {
            $abs = $this->layanan->pathLampiran($lampiran);
        } catch (GaleriException) {
            abort(404);
        }

        return $request->boolean('unduh')
            ? response()->download($abs, $lampiran->nama)
            : response()->file($abs, ['Cache-Control' => 'private, max-age=86400']);
    }
}
