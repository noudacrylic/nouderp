<?php

namespace App\Modules\Tasks\MejaKerja;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use App\Modules\Tasks\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Meja Kerja Admin: tempat kerja admin & owner — Antre / Dikerjakan / Pending / Selesai hari
 * ini, tombol "Ambil berikutnya", dan pengaturan peran & skill (super admin).
 */
class MejaKerjaController extends Controller
{
    public function __construct(private MejaKerjaService $meja) {}

    public function index(Request $request)
    {
        $u = $request->user();
        $peranSaya = $this->meja->peranUser($u->id);
        $bolehSemua = $u->role === 'super_admin';
        $lihat = $request->input('lihat', 'saya');
        if ($lihat !== 'saya' && !($bolehSemua && ($lihat === 'semua' || isset(MejaKerjaPeran::PERAN[$lihat])))) $lihat = 'saya';

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
            'peranSaya' => $peranSaya, 'bolehSemua' => $bolehSemua,
            'dikerjakanSaya' => Task::where('assignee_user_id', $u->id)->where('status', 'in_progress')->count(),
        ]);
    }

    public function ambil(Request $request)
    {
        $u = $request->user();
        if ($this->penuh($u)) return back()->with('error', 'Selesaikan atau pending-kan dulu task yang sedang dikerjakan (maks ' . MejaKerjaService::MAKS_DIKERJAKAN . ').');

        $t = $this->meja->ambilBerikutnya($u);
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

    public function sinkron(Request $request)
    {
        $h = $this->meja->sinkron();
        return back()->with('success', "Disinkron: {$h['dibuat']} baru, {$h['selesai']} selesai otomatis, {$h['batal']} batal, {$h['lanjut']} dilanjutkan, {$h['alih']} dialihkan.");
    }

    public function pengaturan(Request $request)
    {
        $this->superAdmin($request);
        $skills = collect($this->meja->skills())->map(fn ($s) => ['skill' => $s, 'atur' => $this->meja->pengaturan($s)]);

        return view('erp.tasks.meja.pengaturan', [
            'skills' => $skills,
            'peran'  => MejaKerjaPeran::with('user:id,name')->orderBy('urutan')->get()->groupBy('peran'),
            'users'  => User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'role']),
        ]);
    }

    public function simpanPengaturan(Request $request)
    {
        $this->superAdmin($request);
        $data = $request->validate([
            'peran'               => 'array',
            'peran.*'             => 'array',
            'peran.*.*'           => 'nullable|integer|exists:users,id',
            'skill'               => 'array',
            'skill.*.peran'       => 'required|in:' . implode(',', array_keys(MejaKerjaPeran::PERAN)),
            'skill.*.bobot'       => 'required|integer|min:1|max:10',
            'skill.*.sla_menit'   => 'required|integer|min:5|max:10080',
            'skill.*.aktif'       => 'nullable|boolean',
        ]);

        DB::transaction(function () use ($data) {
            foreach (array_keys(MejaKerjaPeran::PERAN) as $p) {
                MejaKerjaPeran::where('peran', $p)->delete();
                foreach (array_values(array_unique(array_filter($data['peran'][$p] ?? []))) as $i => $uid) {
                    MejaKerjaPeran::create(['peran' => $p, 'user_id' => $uid, 'urutan' => $i + 1]);
                }
            }
            foreach ($this->meja->skills() as $key => $s) {
                $isi = $data['skill'][$key] ?? null;
                if (!$isi) continue;
                $atur = $this->meja->pengaturan($s);
                $aktif = !empty($isi['aktif']);
                $atur->update([
                    'peran' => $isi['peran'], 'bobot' => $isi['bobot'], 'sla_menit' => $isi['sla_menit'], 'aktif' => $aktif,
                    // Dinyalakan → hanya pekerjaan sejak saat ini; pekerjaan lama tidak membanjiri meja.
                    'aktif_sejak' => $aktif ? ($atur->aktif ? $atur->aktif_sejak : now()) : $atur->aktif_sejak,
                ]);
            }
        });

        return redirect()->route('tasks.meja.pengaturan')->with('success', 'Pengaturan Meja Kerja disimpan.');
    }

    private function penuh(User $u): bool
    {
        return Task::where('assignee_user_id', $u->id)->where('status', 'in_progress')->count() >= MejaKerjaService::MAKS_DIKERJAKAN;
    }

    /** Pemegang, rekan seperan (task belum dipegang), atau super admin. */
    private function bolehPegang(User $u, Task $t): void
    {
        $ok = $u->role === 'super_admin'
            || (int) $t->assignee_user_id === (int) $u->id
            || ($t->peran && in_array($t->peran, $this->meja->peranUser($u->id), true));
        abort_unless($ok, 403, 'Task ini milik peran lain.');
    }

    private function superAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === 'super_admin', 403, 'Hanya super admin yang boleh mengatur Meja Kerja.');
    }
}
