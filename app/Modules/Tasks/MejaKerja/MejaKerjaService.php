<?php

namespace App\Modules\Tasks\MejaKerja;

use App\Models\User;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\Production\Services\ExecutorScheduleResolver;
use App\Modules\Tasks\MejaKerja\Contracts\SkillAdmin;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaSkill;
use App\Modules\Tasks\MejaKerja\Skills\DesainSkill;
use App\Modules\Tasks\MejaKerja\Skills\PersetujuanUsulanSkill;
use App\Modules\Tasks\MejaKerja\Skills\QcSkill;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskHistory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Mesin Meja Kerja Admin: daftar Skill Admin, sinkron (buat task dari pekerjaan terbuka,
 * tutup/batalkan task yang sumbernya beres), penugasan per peran (utama → cadangan bila tak
 * absen masuk), perpindahan status, dan skor urutan kerja.
 *
 * Status: open = Antre, in_progress = Dikerjakan, pending = menunggu (wajib alasan),
 * done / cancelled.
 */
class MejaKerjaService
{
    public const SKILL = [DesainSkill::class, QcSkill::class, PersetujuanUsulanSkill::class];

    /** Batas task yang dipegang bersamaan per orang. */
    public const MAKS_DIKERJAKAN = 3;

    /** @return array<string, SkillAdmin> */
    public function skills(): array
    {
        $out = [];
        foreach (self::SKILL as $kelas) {
            $s = app($kelas);
            $out[$s->key()] = $s;
        }
        return $out;
    }

    public function skill(?string $key): ?SkillAdmin
    {
        return $key ? ($this->skills()[$key] ?? null) : null;
    }

    /** Pengaturan skill; baris dibuat dengan nilai bawaan (nonaktif) bila belum ada. */
    public function pengaturan(SkillAdmin $s): MejaKerjaSkill
    {
        return MejaKerjaSkill::firstOrCreate(['skill' => $s->key()], [
            'aktif' => false, 'peran' => $s->peranBawaan(), 'bobot' => $s->bobotBawaan(), 'sla_menit' => $s->slaMenitBawaan(),
        ]);
    }

    /** @return array{dibuat: int, selesai: int, batal: int, lanjut: int, alih: int} */
    public function sinkron(): array
    {
        $hasil = ['dibuat' => 0, 'selesai' => 0, 'batal' => 0, 'lanjut' => 0, 'alih' => 0];

        foreach ($this->skills() as $key => $skill) {
            $atur = $this->pengaturan($skill);
            if (!$atur->aktif || !$skill->tersedia()) continue;

            try {
                $hasil = $this->tutupYangBeres($skill, $hasil);
                foreach ($skill->pindai($atur->aktif_sejak ?? now()) as $kerja) {
                    if (Task::where('kunci_sumber', $kerja['kunci'])->exists()) continue;
                    $this->buat($skill, $atur, $kerja);
                    $hasil['dibuat']++;
                }
            } catch (\Throwable $e) {
                Log::warning('MejaKerja: skill gagal disinkron', ['skill' => $key, 'error' => $e->getMessage()]);
            }
        }

        $hasil['lanjut'] = $this->lanjutkanYangDibalas();
        $hasil['alih']   = $this->alihkanAntreKeYangHadir();

        return $hasil;
    }

    private function tutupYangBeres(SkillAdmin $skill, array $hasil): array
    {
        Task::where('skill', $skill->key())->whereIn('status', ['open', 'in_progress', 'pending'])->get()
            ->each(function (Task $t) use ($skill, &$hasil) {
                if (!($k = $skill->keadaan($t))) return;
                [$status, $catatan] = $k;
                $this->ubahStatus($t, $status, null, $catatan);
                $hasil[$status === 'done' ? 'selesai' : 'batal']++;
            });

        return $hasil;
    }

    private function buat(SkillAdmin $skill, MejaKerjaSkill $atur, array $k): Task
    {
        $lahir = $k['dibuat_at'] ?? now();
        $task = Task::create([
            'title'            => mb_substr($k['judul'], 0, 255),
            'description'      => $k['deskripsi'] ?? null,
            'creator_user_id'  => $this->pemilikSistem(),
            'assignee_user_id' => $this->pelaksana($atur->peran)?->id,
            'status'           => 'open',
            'priority'         => 'normal',
            'taskable_type'    => $k['taskable_type'] ?? null,
            'taskable_id'      => $k['taskable_id'] ?? null,
            'source'           => 'skill',
            'skill'            => $skill->key(),
            'peran'            => $atur->peran,
            'kunci_sumber'     => $k['kunci'],
            'tenggat_at'       => $lahir->copy()->addMinutes($atur->sla_menit),
            'meta'             => $k['meta'] ?? null,
        ]);
        $task->forceFill(['created_at' => $lahir])->saveQuietly();
        $this->catat($task, null, 'dibuat', 'Dibuat oleh skill ' . $skill->label());

        return $task;
    }

    // ── Penugasan ───────────────────────────────────────────────────────────────

    /** Orang peran ini yang hadir hari ini (urutan terkecil); tak ada yang hadir → yang utama. */
    public function pelaksana(string $peran): ?User
    {
        $orang = $this->orangPeran($peran);
        return $orang->first(fn (User $u) => $this->hadir($u)) ?? $orang->first();
    }

    /** @return Collection<int, User> */
    public function orangPeran(string $peran): Collection
    {
        return MejaKerjaPeran::with('user')->where('peran', $peran)->orderBy('urutan')->get()
            ->pluck('user')->filter(fn ($u) => $u && $u->is_active)->values();
    }

    /** @return string[] peran milik user */
    public function peranUser(int $userId): array
    {
        return MejaKerjaPeran::where('user_id', $userId)->pluck('peran')->all();
    }

    /** Hadir = sudah scan masuk hari ini; user tanpa data karyawan (owner) selalu dianggap hadir. */
    public function hadir(User $u): bool
    {
        if (!$u->karyawan_id) return true;
        return (bool) app(ExecutorScheduleResolver::class)->hasCheckedIn((int) $u->karyawan_id, now());
    }

    /** Task Antre milik orang yang tidak hadir dipindah ke rekan seperan yang hadir. */
    private function alihkanAntreKeYangHadir(): int
    {
        $n = 0;
        Task::whereNotNull('peran')->where('status', 'open')->get()->groupBy('peran')
            ->each(function ($tasks, $peran) use (&$n) {
                $ganti = $this->pelaksana($peran);
                if (!$ganti) return;
                foreach ($tasks as $t) {
                    $pemegang = $t->assignee_user_id ? User::find($t->assignee_user_id) : null;
                    if ($pemegang && ($pemegang->id === $ganti->id || $this->hadir($pemegang))) continue;
                    $t->update(['assignee_user_id' => $ganti->id]);
                    $this->catat($t, null, 'dialihkan', "Dialihkan ke {$ganti->name}" . ($pemegang ? " ({$pemegang->name} belum hadir)" : ''));
                    $n++;
                }
            });

        return $n;
    }

    // ── Perpindahan status ─────────────────────────────────────────────────────

    public function mulai(Task $t, User $u): void
    {
        $t->assignee_user_id = $u->id;
        $this->ubahStatus($t, 'in_progress', $u, $t->status === 'pending' ? 'Dilanjutkan' : 'Mulai dikerjakan');
    }

    /** Task Antre dengan skor tertinggi di peran user → langsung dikerjakan. */
    public function ambilBerikutnya(User $u): ?Task
    {
        $peran = $this->peranUser($u->id);
        $calon = Task::where('status', 'open')
            ->where(fn ($q) => $q->where('assignee_user_id', $u->id)->orWhere(fn ($w) => $w->whereIn('peran', $peran)->whereNull('assignee_user_id')))
            ->get()->sortByDesc(fn (Task $t) => $this->skor($t))->first();

        if ($calon) $this->mulai($calon, $u);
        return $calon;
    }

    public function pending(Task $t, User $u, string $alasan): void
    {
        $t->pending_alasan = mb_substr($alasan, 0, 255);
        $this->ubahStatus($t, 'pending', $u, 'Pending: ' . $alasan);
    }

    public function selesai(Task $t, User $u): void
    {
        $this->ubahStatus($t, 'done', $u, 'Ditandai selesai');
    }

    /**
     * Satu-satunya pintu perubahan status: menjaga mulai_at, durasi pending, done_at, dan
     * riwayat — bahan penilaian kinerja.
     */
    public function ubahStatus(Task $t, string $status, ?User $u, string $catatan): void
    {
        $dari = $t->status;
        if ($dari === 'pending' && $t->pending_sejak) {
            $t->pending_detik = (int) $t->pending_detik + (int) $t->pending_sejak->diffInSeconds(now());
            $t->pending_sejak = null;
        }
        if ($status === 'in_progress') {
            $t->mulai_at ??= now();
            $t->pending_alasan = null;
        }
        if ($status === 'pending') $t->pending_sejak = now();
        if (in_array($status, ['done', 'cancelled'], true)) $t->done_at = now();

        $t->status = $status;
        $t->save();
        $this->catat($t, $u, $status, $catatan);
    }

    /** Pending menunggu pelanggan: begitu pelanggan membalas chat WA tertaut → kembali Dikerjakan. */
    private function lanjutkanYangDibalas(): int
    {
        $n = 0;
        Task::where('status', 'pending')->whereNotNull('meta')->get()->each(function (Task $t) use (&$n) {
            $cid = $t->meta['crm_conversation_id'] ?? null;
            if (!$cid || !$t->pending_sejak) return;
            $masuk = CrmConversation::where('id', $cid)->value('last_inbound_at');
            if ($masuk && $t->pending_sejak->lt($masuk)) {
                $this->ubahStatus($t, 'in_progress', null, 'Pelanggan membalas chat — dilanjutkan');
                $n++;
            }
        });

        return $n;
    }

    // ── Skor ───────────────────────────────────────────────────────────────────

    /**
     * Urutan kerja = bobot skill × urgensi tenggat. Urgensi 1 di awal, naik saat mendekati
     * tenggat, dan terus naik sesudah lewat (tiap SLA penuh terlambat +1).
     */
    public function skor(Task $t): float
    {
        $bobot = $t->skill ? (MejaKerjaSkill::where('skill', $t->skill)->value('bobot') ?? 5) : ($t->priority === 'high' ? 8 : 4);
        if (!$t->tenggat_at) return $bobot;

        $total = max(60, $t->created_at->diffInSeconds($t->tenggat_at));
        $pakai = $t->created_at->diffInSeconds(now(), false) / $total;
        $urgensi = match (true) {
            $pakai < 0.5 => 1.0,
            $pakai < 0.8 => 1.5,
            $pakai < 1.0 => 2.0,
            default      => 3.0 + ($pakai - 1.0),
        };

        return round($bobot * $urgensi, 2);
    }

    private function catat(Task $t, ?User $u, string $aksi, string $ringkas): void
    {
        TaskHistory::create(['task_id' => $t->id, 'user_id' => $u?->id, 'action' => mb_substr($aksi, 0, 50), 'summary' => mb_substr($ringkas, 0, 255), 'created_at' => now()]);
    }

    /** creator_user_id task skill: owner pertama, lalu super admin pertama. */
    private function pemilikSistem(): int
    {
        return (int) (MejaKerjaPeran::where('peran', 'owner')->orderBy('urutan')->value('user_id')
            ?? User::where('role', 'super_admin')->orderBy('id')->value('id'));
    }
}
