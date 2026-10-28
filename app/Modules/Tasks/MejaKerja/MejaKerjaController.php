<?php

namespace App\Modules\Tasks\MejaKerja;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Tasks\MejaKerja\Contracts\PunyaJawaban;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use App\Modules\Tasks\Models\Task;
use Illuminate\Http\Request;

/**
 * Meja Kerja Admin: tempat kerja admin & owner — Antre / Dikerjakan / Pending / Selesai hari
 * ini, tombol "Ambil berikutnya". Tab = meja peran (Layanan, Desain, Owner): anggota meja
 * melihat mejanya sendiri, super admin semua meja. Skill & anggota diatur di Manajer Admin.
 */
class MejaKerjaController extends Controller
{
    public function __construct(private MejaKerjaService $meja) {}

    public function index(Request $request)
    {
        $u = $request->user();
        $peranSaya = $this->meja->peranUser($u->id);
        $bolehSemua = $u->role === 'super_admin';
        $tabPeran = $this->tabPeran($u);
        $lihat = $request->input('lihat', 'saya');
        if ($lihat !== 'saya' && !($lihat === 'semua' ? $bolehSemua : isset($tabPeran[$lihat]))) $lihat = 'saya';

        $q = Task::with('assignee:id,name');
        if ($lihat === 'saya') {
            $q->where(fn ($w) => $w->where('assignee_user_id', $u->id)
                ->orWhere(fn ($x) => $x->whereIn('peran', $peranSaya)->whereNull('assignee_user_id')));
        } elseif ($lihat === 'semua') {
            $q->whereNotNull('peran');
        } else {
            $q->where('peran', $lihat);
        }

        $tasks = $q->where(fn ($w) => $w->whereIn('status', ['open', 'in_progress', 'pending'])
                ->orWhere(fn ($x) => $x->where('status', 'done')->where('done_at', '>=', today())))
            ->get();

        $skor = $tasks->mapWithKeys(fn (Task $t) => [$t->id => $this->meja->skor($t)]);
        $kolom = [
            'open'        => $tasks->where('status', 'open')->sortByDesc(fn ($t) => $skor[$t->id])->values(),
            'in_progress' => $tasks->where('status', 'in_progress')->sortBy('mulai_at')->values(),
            'pending'     => $tasks->where('status', 'pending')->sortBy('pending_sejak')->values(),
            'done'        => $tasks->where('status', 'done')->sortByDesc('done_at')->values(),
        ];

        return view('erp.tasks.meja.index', [
            'kolom' => $kolom, 'skor' => $skor, 'skills' => $this->meja->skills(), 'lihat' => $lihat,
            'peranSaya' => $peranSaya, 'bolehSemua' => $bolehSemua, 'tabPeran' => $tabPeran,
            'jumlahAntre' => Task::where('status', 'open')->whereIn('peran', array_keys($tabPeran))
                ->groupBy('peran')->selectRaw('peran, COUNT(*) n')->pluck('n', 'peran'),
            'dikerjakanSaya' => Task::where('assignee_user_id', $u->id)->where('status', 'in_progress')->count(),
        ]);
    }

    public function ambil(Request $request)
    {
        $u = $request->user();
        if ($this->penuh($u)) return back()->with('error', 'Selesaikan atau pending-kan dulu task yang sedang dikerjakan (maks ' . MejaKerjaService::MAKS_DIKERJAKAN . ').');

        // Dari meja yang sedang dibuka; "Meja saya" = semua meja tempat user jadi anggota.
        $lihat = (string) $request->input('lihat');
        $t = $this->meja->ambilBerikutnya($u, isset($this->tabPeran($u)[$lihat]) ? [$lihat] : null);
        return back()->with($t ? 'success' : 'info', $t ? "Dikerjakan: {$t->title}" : 'Antre kosong — tidak ada pekerjaan untuk diambil.');
    }

    public function mulai(Request $request, Task $task)
    {
        $u = $request->user();
        $this->bolehPegang($u, $task);
        if ($task->status === 'open' && $this->penuh($u)) {
            return back()->with('error', 'Maksimal ' . MejaKerjaService::MAKS_DIKERJAKAN . ' task dikerjakan bersamaan.');
        }
        abort_unless(in_array($task->status, ['open', 'pending'], true), 422, 'Task tidak bisa dimulai dari status ini.');
        $this->meja->mulai($task, $u);

        return back();
    }

    public function pending(Request $request, Task $task)
    {
        $u = $request->user();
        $this->bolehPegang($u, $task);
        // Tombol alasan cepat mengirim 'alasan'; kotak isian mengirim 'alasan_lain'.
        $alasan = trim((string) ($request->input('alasan') ?: $request->input('alasan_lain')));
        if ($alasan === '') return back()->with('error', 'Isi alasan pending.');
        abort_unless($task->status === 'in_progress', 422, 'Hanya task yang sedang dikerjakan yang bisa di-pending.');
        $this->meja->pending($task, $u, mb_substr($alasan, 0, 255));

        return back();
    }

    public function selesai(Request $request, Task $task)
    {
        $u = $request->user();
        $this->bolehPegang($u, $task);
        $skill = $this->meja->skill($task->skill);
        abort_if($skill && !$skill->selesaiManual(), 422, 'Task ini selesai otomatis saat pekerjaannya tuntas di ERP.');
        abort_unless(in_array($task->status, ['in_progress', 'pending'], true), 422, 'Mulai dulu task-nya.');
        $this->meja->selesai($task, $u);

        return back()->with('success', 'Selesai: ' . $task->title);
    }

    /** Jawaban pilihan task (mis. hasil cek persediaan) — skill PunyaJawaban. */
    public function jawab(Request $request, Task $task)
    {
        $u = $request->user();
        $this->bolehPegang($u, $task);
        $skill = $this->meja->skill($task->skill);
        abort_unless($skill instanceof PunyaJawaban, 422, 'Task ini tidak memakai jawaban.');
        abort_unless(in_array($task->status, ['in_progress', 'pending'], true), 422, 'Mulai dulu task-nya.');
        $kunci = (string) $request->input('jawaban');
        abort_unless(collect($skill->jawaban($task))->contains('kunci', $kunci), 422, 'Jawaban tidak berlaku untuk task ini.');

        return back()->with('success', $skill->jawab($task, $kunci, $u));
    }

    public function sinkron(Request $request)
    {
        $h = $this->meja->sinkron();
        return back()->with('success', "Disinkron: {$h['dibuat']} baru, {$h['selesai']} selesai otomatis, {$h['batal']} batal, {$h['lanjut']} dilanjutkan.");
    }

    /** @return array<string, string> meja peran yang boleh dibuka user: super admin semua, lainnya meja tempat ia anggota. */
    private function tabPeran(User $u): array
    {
        return $u->role === 'super_admin'
            ? MejaKerjaPeran::PERAN
            : array_intersect_key(MejaKerjaPeran::PERAN, array_flip($this->meja->peranUser($u->id)));
    }

    private function penuh(User $u): bool
    {
        return Task::where('assignee_user_id', $u->id)->where('status', 'in_progress')->count() >= MejaKerjaService::MAKS_DIKERJAKAN;
    }

    /** Pemegang, anggota meja peran task, atau super admin. */
    private function bolehPegang(User $u, Task $t): void
    {
        $ok = $u->role === 'super_admin'
            || (int) $t->assignee_user_id === (int) $u->id
            || ($t->peran && in_array($t->peran, $this->meja->peranUser($u->id), true));
        abort_unless($ok, 403, 'Task ini milik peran lain.');
    }
}
