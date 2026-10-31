<?php

namespace App\Modules\Tasks\MejaKerja;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Marketplace\Shopee\Services\ShopeeSapaPembeliService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Tasks\MejaKerja\Contracts\PunyaJawaban;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use App\Modules\Tasks\Models\Task;
use Illuminate\Http\Request;

/**
 * Meja Kerja Admin: tempat kerja admin & owner — Antre (urut skor) / Pending. Task selesai
 * sendiri saat dokumen ERP-nya dibuat; riwayat selesai ada di Daftar task. Tab = meja peran
 * (Layanan, Desain, Owner): anggota meja melihat mejanya sendiri, super admin semua meja.
 * Skill, anggota & penugasan diatur Manajer Admin.
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

        $tasks = $q->whereIn('status', ['open', 'in_progress', 'pending'])->get();

        // Pratinjau skill mode Bayangan — hanya owner/super admin, baca saja.
        $tampilBayangan = $request->input('bayangan', '1') === '1';
        $semuaBayangan = $bolehSemua ? $this->meja->bayangan() : collect();
        $bayangan = collect();
        if ($tampilBayangan) {
            $bayangan = $semuaBayangan->filter(fn (Task $t) => match ($lihat) {
                'saya'  => in_array($t->peran, $peranSaya, true),
                'semua' => true,
                default => $t->peran === $lihat,
            });
            $tasks = $tasks->concat($bayangan);
        }

        $skor = $tasks->mapWithKeys(fn (Task $t) => [$t->id => $this->meja->skor($t)]);
        // Yang sedang dikerjakan (skill ber-Mulai) di atas Antre, sisanya urut skor.
        $kolom = [
            'open'    => $tasks->where('status', 'in_progress')->sortBy('mulai_at')
                ->concat($tasks->where('status', 'open')->sortByDesc(fn ($t) => $skor[$t->id]))->values(),
            'pending' => $tasks->where('status', 'pending')->sortBy('pending_sejak')->values(),
        ];

        return view('erp.tasks.meja.index', [
            'kolom' => $kolom, 'skor' => $skor, 'skills' => $this->meja->skills(), 'lihat' => $lihat,
            'peranSaya' => $peranSaya, 'bolehSemua' => $bolehSemua, 'tabPeran' => $tabPeran,
            'bayangan' => $bayangan, 'tampilBayangan' => $tampilBayangan,
            'bayanganPerMeja' => $semuaBayangan->countBy('peran'),
            'jumlahAntre' => Task::where('status', 'open')->whereIn('peran', array_keys($tabPeran))
                ->groupBy('peran')->selectRaw('peran, COUNT(*) n')->pluck('n', 'peran'),
        ]);
    }

    /** Hanya skill yang punya masa kerja nyata (butuhMulai, mis. Desain). */
    public function mulai(Request $request, Task $task)
    {
        $u = $request->user();
        $this->bolehPegang($u, $task);
        abort_unless(MejaKerjaService::butuhMulai($this->meja->skill($task->skill)), 422, 'Task ini tidak perlu dimulai — langsung kerjakan dari tombolnya.');
        abort_unless($task->status === 'open', 422, 'Task tidak bisa dimulai dari status ini.');
        if ($this->penuh($u)) {
            return back()->with('error', 'Maksimal ' . MejaKerjaService::MAKS_DIKERJAKAN . ' task dikerjakan bersamaan.');
        }
        $this->meja->mulai($task, $u);

        return back();
    }

    public function lanjutkan(Request $request, Task $task)
    {
        $u = $request->user();
        $this->bolehPegang($u, $task);
        abort_unless($task->status === 'pending', 422, 'Hanya task Pending yang bisa dilanjutkan.');
        $this->meja->lanjutkan($task, $u);

        return back();
    }

    public function pending(Request $request, Task $task)
    {
        $u = $request->user();
        $this->bolehPegang($u, $task);
        // Tombol alasan cepat mengirim 'alasan'; kotak isian mengirim 'alasan_lain'.
        $alasan = trim((string) ($request->input('alasan') ?: $request->input('alasan_lain')));
        if ($alasan === '') return back()->with('error', 'Isi alasan pending.');
        abort_unless(in_array($task->status, ['open', 'in_progress'], true), 422, 'Task ini tidak bisa di-pending.');
        $this->meja->pending($task, $u, mb_substr($alasan, 0, 255));

        return back();
    }

    public function selesai(Request $request, Task $task)
    {
        $u = $request->user();
        $this->bolehPegang($u, $task);
        $skill = $this->meja->skill($task->skill);
        abort_unless(MejaKerjaService::bolehSelesaiManual($skill, $task), 422, 'Task ini selesai otomatis saat pekerjaannya tuntas di ERP.');
        abort_unless(in_array($task->status, ['open', 'in_progress', 'pending'], true), 422, 'Task sudah ditutup.');
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
        abort_unless(in_array($task->status, ['open', 'in_progress', 'pending'], true), 422, 'Task sudah ditutup.');
        $kunci = (string) $request->input('jawaban');
        abort_unless(collect($skill->jawaban($task))->contains('kunci', $kunci), 422, 'Jawaban tidak berlaku untuk task ini.');

        return back()->with('success', $skill->jawab($task, $kunci, $u));
    }

    /**
     * Mulai Chat Shopee dari kartu Desain: sapa pembeli duluan (kartu pesanan + teks), lalu
     * buka percakapannya di CRM. Chat yang sudah ada langsung dibuka tanpa mengirim apa pun.
     */
    public function chatShopee(Request $request, int $so, ShopeeSapaPembeliService $sapa)
    {
        $u = $request->user();
        abort_unless($u->role === 'super_admin' || $this->meja->peranUser($u->id), 403, 'Hanya anggota meja kerja.');
        $data = $request->validate(['teks' => 'required|string|max:1000']);

        $no = (string) SalesOrder::whereKey($so)->value('customer_po_number');
        abort_unless(str_starts_with($no, 'SP-'), 422, 'Bukan pesanan Shopee.');

        $h = $sapa->sapa(substr($no, 3), $data['teks'], $u->id);
        if ($h['error']) return back()->with('error', $h['error']);
        if (!$h['conversation_id']) {
            return back()->with('success', 'Sapaan terkirim ke pembeli ' . $no . '. Percakapannya muncul di CRM dalam ±1 menit.');
        }

        return redirect()->route('crm.inbox.show', $h['conversation_id'])
            ->with('success', $h['terkirim'] ? 'Sapaan terkirim ke pembeli ' . $no . '.' : 'Chat dengan pembeli ini sudah ada — tidak mengirim ulang.');
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
