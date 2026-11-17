<?php

namespace App\Modules\Galeri\Controllers;

use App\Models\User;
use App\Modules\Galeri\Models\GaleriKotak;
use App\Modules\Galeri\Services\GaleriException;
use App\Modules\Galeri\Services\GaleriService;
use App\Modules\Galeri\Services\KotakPortoService;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Kotak Porto (lihat KotakPortoService):
 *   - PWA `/cs/porto`: unggah foto/video dari galeri HP (utuh) ke kotak, lihat & hapus isinya.
 *   - Meja Kerja (laptop): daftar kotak, pratinjau potong/putar, lampirkan ke kartu tahap foto.
 */
class PortoController extends Controller
{
    public function __construct(private KotakPortoService $kotak, private GaleriService $galeri, private MejaKerjaService $meja) {}

    // ───────────── PWA ─────────────

    public function index()
    {
        $this->jagaPwa();

        return view('cs.porto', [
            'items'    => $this->kotak->belumDipakai(),
            'potongan' => $this->galeri->ukuranPotongan(),
            'maksMb'   => (int) config('galeri.maks_mb'),
            'hari'     => (int) config('galeri.kotak_hari', 14),
        ]);
    }

    public function unggah(Request $request)
    {
        $this->jagaPwa();
        $request->validate([
            'id' => 'required|string', 'offset' => 'required|integer|min:0', 'total' => 'required|integer|min:1',
            'nama' => 'required|string|max:255', 'potongan' => 'required|file',
        ]);

        try {
            return response()->json($this->kotak->terima((int) $request->user()->id, (string) $request->input('id'),
                (int) $request->input('offset'), (int) $request->input('total'), (string) $request->input('nama'), $request->file('potongan')));
        } catch (GaleriException $e) {
            if ($e->diterima !== null) return response()->json(['pesan' => $e->getMessage(), 'diterima' => $e->diterima], 409);
            return response()->json(['pesan' => $e->getMessage()], 422);
        }
    }

    public function hapus(GaleriKotak $kotak)
    {
        $this->jagaPwa();
        $this->kotak->hapus($kotak);

        return back()->with('success', 'Dihapus dari Kotak Porto.');
    }

    // ───────────── Bersama (PWA & laptop) ─────────────

    public function thumb(GaleriKotak $kotak)
    {
        $this->jagaLihat();
        $t = $this->galeri->thumbnailAbs($kotak->abs(), 'kotak:' . $kotak->id);
        abort_unless($t, 404);

        return response()->file($t, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=2592000']);
    }

    public function berkas(GaleriKotak $kotak)
    {
        $this->jagaLihat();
        abort_unless(is_file($kotak->abs()), 404);

        return response()->file($kotak->abs(), ['Cache-Control' => 'private, max-age=86400']);
    }

    // ───────────── Meja Kerja (laptop) ─────────────

    public function daftar()
    {
        $this->jagaLihat();

        return response()->json(['items' => $this->kotak->belumDipakai()->map(fn (GaleriKotak $k) => [
            'id'        => $k->id,
            'nama'      => $k->nama,
            'jenis'     => $k->jenis,
            'ukuran'    => $k->ukuran,
            'oleh'      => $k->user?->name,
            'waktu'     => $k->created_at->timezone(config('app.timezone'))->translatedFormat('d M H:i'),
            'thumb'     => route('tasks.meja.porto.thumb', $k->id),
            'berkas'    => route('tasks.meja.porto.berkas', $k->id),
            'pratinjau' => route('tasks.meja.porto.pratinjau', $k->id),
            'bisa_edit' => $this->kotak->bisaDiedit($k),
        ])->values()]);
    }

    public function pratinjau(Request $request, GaleriKotak $kotak)
    {
        $this->jagaLihat();
        try {
            $out = $this->kotak->pratinjau($kotak, (int) $request->query('putar', 0));
        } catch (GaleriException) {
            abort(404);
        }

        return response()->file($out, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=86400']);
    }

    public function lampirkan(Request $request, Task $task)
    {
        $u = $request->user();
        $this->bolehPegang($u, $task);
        abort_unless(in_array($task->status, ['open', 'in_progress', 'pending'], true), 422, 'Kartu sudah ditutup.');
        $skill = $this->meja->skill($task->skill);
        abort_unless($skill && method_exists($skill, 'soUntukFoto') && (($task->meta ?? [])['tahap'] ?? null) === 'foto', 422, 'Kartu ini belum di tahap foto.');
        $soId = $skill->soUntukFoto($task);
        abort_unless($soId, 422, 'Kartu ini tidak tertaut pesanan.');

        $data = $request->validate([
            'pilihan'            => 'required|array|min:1',
            'pilihan.*.id'       => 'required|integer',
            'pilihan.*.putar'    => 'nullable|integer',
            'pilihan.*.potong'   => 'nullable|array',
            'pilihan.*.potong.x' => 'required_with:pilihan.*.potong|numeric|between:0,1',
            'pilihan.*.potong.y' => 'required_with:pilihan.*.potong|numeric|between:0,1',
            'pilihan.*.potong.w' => 'required_with:pilihan.*.potong|numeric|between:0,1',
            'pilihan.*.potong.h' => 'required_with:pilihan.*.potong|numeric|between:0,1',
        ]);

        try {
            $n = $this->kotak->lampirkan($soId, $data['pilihan'], (int) $u->id);
        } catch (GaleriException $e) {
            return response()->json(['pesan' => $e->getMessage()], 422);
        }
        $this->meja->ubahStatus($task, 'done', $u, "Foto dilampirkan ($n berkas)");

        return response()->json(['pesan' => "$n berkas dilampirkan — kartu selesai."]);
    }

    // ───────────── Penjaga ─────────────

    private function jagaPwa(): void
    {
        abort_unless(user_can_access('crm.galeri'), 403, 'Akun ini belum diberi akses Galeri.');
    }

    /** Pemegang Galeri, anggota meja kerja, atau super admin. */
    private function jagaLihat(): void
    {
        $u = auth()->user();
        abort_unless($u && ($u->role === 'super_admin' || user_can_access('crm.galeri') || $this->meja->peranUser($u->id)), 403);
    }

    private function bolehPegang(User $u, Task $t): void
    {
        $ok = $u->role === 'super_admin'
            || (int) $t->assignee_user_id === (int) $u->id
            || ($t->peran && in_array($t->peran, $this->meja->peranUser($u->id), true));
        abort_unless($ok, 403, 'Task ini milik peran lain.');
    }
}
