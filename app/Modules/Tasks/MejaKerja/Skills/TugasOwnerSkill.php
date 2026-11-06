<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

use App\Modules\Tasks\MejaKerja\Contracts\JadiSkillManajer;
use App\Modules\Tasks\MejaKerja\Contracts\SkillAdmin;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\TugasOwner\Models\TugasOwner;
use App\Modules\Tasks\TugasOwner\TugasOwnerService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Tugas dari Owner yang sedang giliran admin (menulis pemahaman / mengerjakan) → kartu di meja
 * admin pengerjanya, supaya ikut diurutkan bersama pekerjaan lain sesuai prioritas pilihan owner
 * (Utama / Normal / Rendah — lihat config/meja_prioritas.php). Satu kartu per giliran: kartu
 * selesai saat tugas pindah tahap, dan giliran berikutnya jadi kartu baru.
 */
class TugasOwnerSkill implements SkillAdmin
{
    use JadiSkillManajer;

    public const PRIORITAS = ['utama' => 'Utama', 'normal' => 'Normal', 'rendah' => 'Rendah'];

    public function key(): string { return 'tugas-owner'; }

    public function label(): string { return 'Tugas dari Owner di Meja Kerja'; }

    public function deskripsi(): string
    {
        return 'Tugas dari Owner yang sedang giliran admin (menulis pemahaman / mengerjakan) jadi kartu di meja pengerjanya, diurutkan sesuai prioritas pilihan owner: Utama paling atas, Normal setelah pekerjaan rutin teratas, Rendah paling bawah.';
    }

    public function peranBawaan(): string { return 'layanan'; }

    public function bobotBawaan(): int { return 6; }

    public function slaMenitBawaan(): int { return 1440; }

    public function tersedia(): bool
    {
        return Schema::hasColumn('tugas_owner', 'prioritas');
    }

    public function pindai(Carbon $sejak): iterable
    {
        foreach (TugasOwner::whereIn('tahap', TugasOwnerService::GILIRAN_PENGERJA)->get() as $t) {
            $peran = MejaKerjaPeran::where('user_id', $t->pengerja_id)->whereIn('peran', ['layanan', 'desain'])->orderBy('id')->value('peran');
            yield [
                'kunci'            => "tugas-owner:{$t->id}:{$t->tahap}:{$t->jumlah_revisi}",
                'judul'            => ($t->tahap === TugasOwner::RINGKASAN ? 'Tulis pemahaman — ' : 'Kerjakan — ') . $t->judul,
                'deskripsi'        => TugasOwner::LABEL_TAHAP[$t->tahap] ?? $t->tahap,
                'taskable_type'    => TugasOwner::class,
                'taskable_id'      => (int) $t->id,
                'assignee_user_id' => (int) $t->pengerja_id,
                'peran'            => $peran ?? $this->peranBawaan(),
                'meta'             => ['tahap' => $t->tahap, 'revisi' => (int) $t->jumlah_revisi, 'prioritas' => $t->prioritas ?: 'normal'],
                'tenggat_at'       => $t->tenggat?->copy()->endOfDay(),
                'dibuat_at'        => $t->updated_at ?? now(),
            ];
        }
    }

    public function keadaan(Task $task): ?array
    {
        $t = TugasOwner::find($task->taskable_id);
        if (!$t || $t->tahap === TugasOwner::BATAL) return ['cancelled', 'Tugas dibatalkan owner.'];
        if ($t->tahap !== ($task->meta['tahap'] ?? null) || (int) $t->jumlah_revisi !== (int) ($task->meta['revisi'] ?? 0)) {
            return ['done', 'Tugas lanjut ke tahap: ' . (TugasOwner::LABEL_TAHAP[$t->tahap] ?? $t->tahap) . '.'];
        }

        return null;
    }

    /** Owner mengubah prioritas / tenggat → letak kartu ikut berubah. */
    public function perbarui(Task $task): ?array
    {
        $t = TugasOwner::find($task->taskable_id);
        if (!$t) return null;
        $prioritas = $t->prioritas ?: 'normal';
        if ($prioritas === ($task->meta['prioritas'] ?? 'normal')) return null;

        return [
            'status'  => null,
            'catatan' => 'Prioritas diubah owner: ' . (self::PRIORITAS[$prioritas] ?? $prioritas),
            'meta'    => array_merge($task->meta ?? [], ['prioritas' => $prioritas]),
        ];
    }

    public function aksi(Task $task): array
    {
        return [['label' => 'Buka tugas', 'url' => route('tasks.meja.tugas.show', $task->taskable_id), 'utama' => true]];
    }

    public function selesaiManual(): bool { return false; }
}
