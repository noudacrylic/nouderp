<?php

namespace App\Modules\Tasks\MejaKerja;

use App\Models\User;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\KaryawanVirtual\Contracts\Skill;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskHistory;

/**
 * Pengganti admin yang tidak berangkat (config('meja_prioritas.pengganti')): chat CRM yang
 * dipegangnya + kartu kategori tertentu dioper ke anggota meja cadangan yang hadir, lalu
 * dikembalikan begitu ia hadir. Yang dioper ditandai (meta.dioper_dari di kartu,
 * dioper_dari_user_id di chat) — oper manual tidak ikut dikembalikan.
 *
 * Kartu pindahan tampil di meja penerima (meta.dioper_ke_peran) dan diurutkan menurut urutan
 * meja itu (PrioritasMeja). Kartu yang sedang dikerjakan / Pending tidak dipindah.
 */
class PenggantiAdminService
{
    public function __construct(private KehadiranAdmin $hadir) {}

    /** @return array<int, array{status: string, judul: string, alasan: ?string, tautan: ?string, dokumen: ?string}> rincian jurnal */
    public function jalankan(bool $simulasi): array
    {
        $rows = [];
        foreach ((array) config('meja_prioritas.pengganti', []) as $dari => $aturan) {
            foreach (MejaKerjaPeran::where('peran', $dari)->orderBy('urutan')->pluck('user_id') as $uid) {
                $uid = (int) $uid;
                $rows = array_merge($rows, $this->hadir->tidakMasuk($uid)
                    ? $this->oper($uid, $dari, $aturan, $simulasi)
                    : $this->kembalikan($uid, $simulasi));
            }
        }

        return $rows;
    }

    private function oper(int $uid, string $dari, array $aturan, bool $simulasi): array
    {
        $nama = User::whereKey($uid)->value('name');
        $ke   = $this->cadangan($aturan['ke'], $uid);
        if (!$ke) {
            return [];   // tak ada cadangan yang hadir (mis. hari libur) — tidak ada yang dipindah
        }

        $rows = [];
        $kartu = Task::where('assignee_user_id', $uid)->where('peran', $dari)->where('status', 'open')->get()
            ->filter(fn (Task $t) => in_array(PrioritasMeja::kategori($t), (array) $aturan['kartu'], true));
        foreach ($kartu as $t) {
            if (!$simulasi) {
                $t->assignee_user_id = $ke->id;
                $t->meta = array_merge($t->meta ?? [], ['dioper_dari' => $uid, 'dioper_ke_peran' => $aturan['ke']]);
                $t->save();
                $this->catat($t, "Dioper ke {$ke->name}: $nama tidak berangkat");
            }
            $rows[] = $this->baris($simulasi, $t->title, "$nama tidak berangkat → kartu dioper ke {$ke->name}.");
        }

        $chat = CrmConversation::where('owner_user_id', $uid)->where('status', CrmConversation::STATUS_AKTIF)->get();
        foreach ($chat as $c) {
            if (!$simulasi) {
                // Angka belum dibaca TIDAK dipaksa 1: ini oper seluruh isi inbox, bukan satu chat
                // yang perlu disorot — memaksanya membuat ratusan chat lama tampak baru (8 Nov 2026)
                // dan tandanya tertinggal saat chat dikembalikan.
                $c->forceFill(['owner_user_id' => $ke->id, 'dioper_dari_user_id' => $uid])->save();
            }
        }
        if ($chat->isNotEmpty()) {
            $rows[] = $this->baris($simulasi, "{$chat->count()} chat $nama", "$nama tidak berangkat → chat dioper ke {$ke->name}.");
        }

        return $rows;
    }

    private function kembalikan(int $uid, bool $simulasi): array
    {
        $nama = User::whereKey($uid)->value('name');
        $rows = [];

        $kartu = Task::where('meta->dioper_dari', $uid)->whereIn('status', ['open', 'pending'])->get();
        foreach ($kartu as $t) {
            if (!$simulasi) {
                $m = $t->meta ?? [];
                unset($m['dioper_dari'], $m['dioper_ke_peran']);
                $t->assignee_user_id = $uid;
                $t->meta = $m;
                $t->save();
                $this->catat($t, "Dikembalikan ke $nama (sudah hadir)");
            }
            $rows[] = $this->baris($simulasi, $t->title, "$nama sudah hadir → kartu dikembalikan.");
        }

        $chat = CrmConversation::where('dioper_dari_user_id', $uid)->get();
        foreach ($chat as $c) {
            if (!$simulasi) {
                // Dipegang orang lain lagi (dioper manual) → cukup hapus tandanya.
                $kembali = $c->status === CrmConversation::STATUS_AKTIF && MejaKerjaPeran::whereIn('peran', array_keys((array) config('meja_prioritas.pengganti')))
                    ->where('user_id', $c->owner_user_id)->exists();
                $ubah = ['dioper_dari_user_id' => null];
                if ($kembali) $ubah['owner_user_id'] = $uid;
                $c->forceFill($ubah)->save();
            }
        }
        if ($chat->isNotEmpty()) {
            $rows[] = $this->baris($simulasi, "{$chat->count()} chat $nama", "$nama sudah hadir → chat dikembalikan.");
        }

        return $rows;
    }

    /** Anggota meja $peran yang hadir dengan beban paling ringan. */
    private function cadangan(string $peran, int $kecuali): ?User
    {
        $calon = MejaKerjaPeran::where('peran', $peran)->orderBy('urutan')->pluck('user_id')
            ->map(fn ($id) => (int) $id)->reject(fn ($id) => $id === $kecuali || $this->hadir->tidakMasuk($id))->values();
        if ($calon->isEmpty()) return null;

        $beban = Task::whereIn('assignee_user_id', $calon)->whereIn('status', ['open', 'in_progress'])
            ->groupBy('assignee_user_id')->selectRaw('assignee_user_id, COUNT(*) n')->pluck('n', 'assignee_user_id');

        return User::find($calon->sortBy(fn ($id, $i) => [(int) ($beban[$id] ?? 0), $i])->first());
    }

    private function baris(bool $simulasi, string $judul, string $alasan): array
    {
        return ['status' => $simulasi ? Skill::AKAN_DIBUAT : Skill::DIUBAH, 'judul' => $judul, 'alasan' => $alasan, 'tautan' => null, 'dokumen' => null];
    }

    private function catat(Task $t, string $ringkas): void
    {
        TaskHistory::create(['task_id' => $t->id, 'user_id' => null, 'action' => 'dioper', 'summary' => mb_substr($ringkas, 0, 255), 'created_at' => now()]);
    }
}
