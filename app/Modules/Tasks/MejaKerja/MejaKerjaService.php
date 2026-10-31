<?php

namespace App\Modules\Tasks\MejaKerja;

use App\Models\User;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\KaryawanVirtual\Contracts\Skill;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Modules\Tasks\MejaKerja\Contracts\SkillAdmin;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaSkill;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskHistory;
use Illuminate\Support\Facades\Log;

/**
 * Mesin Meja Kerja Admin. Yang memutuskan KAPAN bekerja adalah karyawan virtual Manajer
 * Admin (mode tiap skill, jurnal); service ini yang mengerjakan: buat task dari pekerjaan
 * terbuka, tutup/batalkan task yang sumbernya beres, perpindahan status, dan skor urutan kerja.
 *
 * Pengerja DITENTUKAN Manajer Admin saat task dibuat: anggota meja peran (Desain, Layanan,
 * Owner) yang bebannya paling ringan — bukan siapa yang menekan tombol. Meja tanpa anggota
 * tetap terisi; task-nya ditugaskan begitu meja punya anggota (skill Atur penugasan).
 *
 * Status: open = Antre, pending = menunggu (wajib alasan), done / cancelled. in_progress hanya
 * untuk skill yang punya masa kerja nyata (butuhMulai, mis. Desain): ditandai di kartu Antre.
 */
class MejaKerjaService
{
    /** Kode karyawan virtual pemilik skill admin (config/karyawan_virtual.php). */
    public const KARYAWAN = 'manajer-admin';

    /** Batas task ber-Mulai (skill butuhMulai) yang dikerjakan bersamaan per orang. */
    public const MAKS_DIKERJAKAN = 3;

    public function __construct(private KaryawanVirtualService $kv) {}

    /** @return array<string, SkillAdmin> key pendek => skill */
    public function skills(): array
    {
        $out = [];
        foreach ($this->kv->karyawan(self::KARYAWAN)['skills'] ?? [] as $kelas) {
            $s = app($kelas);
            if ($s instanceof SkillAdmin) $out[$s->key()] = $s;
        }
        return $out;
    }

    public function skill(?string $key): ?SkillAdmin
    {
        return $key ? ($this->skills()[$key] ?? null) : null;
    }

    /** Boleh ditandai selesai dengan tombol? Skill bisa memutuskan per task (selesaiManualUntuk). */
    public static function bolehSelesaiManual(?SkillAdmin $s, Task $t): bool
    {
        if (!$s) return true;

        return method_exists($s, 'selesaiManualUntuk') ? $s->selesaiManualUntuk($t) : $s->selesaiManual();
    }

    /**
     * Skill yang pekerjaannya punya masa kerja nyata (desain di CorelDraw) memakai tombol Mulai.
     * Skill lain cukup Antre → selesai sendiri saat dokumen ERP-nya dibuat.
     */
    public static function butuhMulai(?SkillAdmin $s): bool
    {
        return $s !== null && method_exists($s, 'butuhMulai') && $s->butuhMulai();
    }

    public static function kunciKv(SkillAdmin $s): string
    {
        return 'admin.' . $s->key();
    }

    /** Pengaturan peran/bobot/tenggat skill; dibuat dengan nilai bawaan bila belum ada. */
    public function pengaturan(SkillAdmin $s): MejaKerjaSkill
    {
        return MejaKerjaSkill::firstOrCreate(['skill' => $s->key()], [
            'peran' => $s->peranBawaan(), 'bobot' => $s->bobotBawaan(), 'sla_menit' => $s->slaMenitBawaan(),
        ]);
    }

    /** Skill sedang Otomatis (benar-benar membuat task)? */
    public function aktif(string $key): bool
    {
        $s = $this->skill($key);
        return $s !== null && $this->kv->mode(self::kunciKv($s)) === Skill::OTOMATIS;
    }

    /**
     * Semua skill Manajer Admin dijalankan sesuai modenya (jadwal tiap 2 menit & tombol
     * Sinkron di Meja Kerja). Satu skill gagal tidak menghentikan yang lain.
     *
     * @return array{dibuat: int, selesai: int, batal: int, lanjut: int}
     */
    public function sinkron(): array
    {
        $hasil = ['dibuat' => 0, 'selesai' => 0, 'batal' => 0, 'lanjut' => 0];

        $cfg = $this->kv->karyawan(self::KARYAWAN) ?? [];
        foreach (array_diff(array_keys($cfg['skills'] ?? []), $cfg['jadwal_sendiri'] ?? []) as $key) {
            try {
                $jalan = $this->kv->jalankan($key, KaryawanVirtualService::PEMICU_JADWAL);
                foreach ($jalan['hasil'] as $r) {
                    if (isset($r['jenis'], $hasil[$r['jenis']])) $hasil[$r['jenis']]++;
                }
            } catch (\Throwable $e) {
                Log::warning('MejaKerja: skill gagal disinkron', ['skill' => $key, 'error' => $e->getMessage()]);
            }
        }

        return $hasil;
    }

    /**
     * Satu kali kerja sebuah skill → rincian jurnal karyawan virtual. Bayangan ($simulasi):
     * hanya mencatat task yang AKAN dibuat; yang sudah tercatat di jalan sebelumnya ditandai
     * dilewati supaya jurnal tidak berulang tiap 2 menit.
     *
     * @return array<int, array{status: string, judul: string, alasan: ?string, tautan: ?string, dokumen: ?string, jenis?: ?string, kunci?: string}>
     */
    public function kerjakan(SkillAdmin $skill, bool $simulasi): array
    {
        if (!$skill->tersedia()) return [];

        $atur  = $this->pengaturan($skill);
        $state = $this->kv->state(self::kunciKv($skill));
        $sejak = $state->mode_diubah_at ?? now();
        $rows  = $simulasi ? [] : $this->tutupYangBeres($skill);

        $sudahDicatat = $simulasi ? collect($state->rincian_terakhir ?? [])->pluck('kunci')->filter()->flip() : collect();
        $peranLabel = MejaKerjaPeran::PERAN[$atur->peran] ?? $atur->peran;

        foreach ($skill->pindai($sejak) as $kerja) {
            if (Task::where('kunci_sumber', $kerja['kunci'])->exists()) continue;

            $task = $simulasi ? $this->calon($skill, $atur, $kerja) : $this->buat($skill, $atur, $kerja);
            $ulang = isset($sudahDicatat[$kerja['kunci']]);
            $rows[] = [
                'status'  => !$simulasi ? Skill::DIBUAT : ($ulang ? Skill::DILEWATI : Skill::AKAN_DIBUAT),
                'judul'   => $task->title,
                'alasan'  => $ulang ? 'Sudah tercatat di jalan sebelumnya.' : "Masuk Meja $peranLabel.",
                'tautan'  => collect($skill->aksi($task))->firstWhere('utama')['url'] ?? null,
                'dokumen' => null,
                'jenis'   => $simulasi ? null : 'dibuat',
                'kunci'   => $kerja['kunci'],
            ];
        }

        return $rows;
    }

    /**
     * Pratinjau kartu dari skill yang sedang mode Bayangan — dihitung langsung dengan pindai
     * yang sama seperti Otomatis, TIDAK disimpan. Untuk owner menimbang beban tiap meja
     * sebelum skill diserahkan ke admin. Task-nya bertanda `bayangan` & ber-id negatif.
     *
     * @return \Illuminate\Support\Collection<int, Task>
     */
    public function bayangan(): \Illuminate\Support\Collection
    {
        $out = collect();
        foreach ($this->skills() as $skill) {
            if (!$skill->tersedia() || $this->kv->mode(self::kunciKv($skill)) !== Skill::BAYANGAN) continue;

            $atur  = $this->pengaturan($skill);
            $sejak = $this->kv->state(self::kunciKv($skill))->mode_diubah_at ?? now();
            try {
                foreach ($skill->pindai($sejak) as $kerja) {
                    if (Task::where('kunci_sumber', $kerja['kunci'])->exists()) continue;

                    [$lahir, $isi] = $this->atribut($skill, $atur, $kerja);
                    $t = new Task($isi);
                    $t->id = -($out->count() + 1);
                    $t->created_at = $lahir;
                    $t->setAttribute('bayangan', true);
                    // Skill yang langsung mem-pending kartunya (mis. retur lama) menandainya di meta.
                    if ($alasan = $kerja['meta']['pending'] ?? null) {
                        $t->status = 'pending';
                        $t->pending_alasan = $alasan;
                        $t->pending_sejak = now();
                    }
                    $out->push($t);
                }
            } catch (\Throwable $e) {
                Log::warning('MejaKerja: pratinjau bayangan gagal', ['skill' => $skill->key(), 'error' => $e->getMessage()]);
            }
        }

        return $out;
    }

    /** Skill Atur penugasan: tugaskan task meja yang belum berpengerja + lanjutkan Pending yang dibalas. */
    public function aturPenugasan(): array
    {
        return [...$this->tugaskanYangKosong(), ...$this->lanjutkanYangDibalas()];
    }

    /**
     * Pengerja untuk task baru di meja $peran: anggota meja dengan task terbuka paling sedikit
     * (seri → urutan anggota). null = meja belum punya anggota.
     */
    public function pilihPengerja(string $peran): ?int
    {
        $anggota = MejaKerjaPeran::where('peran', $peran)->orderBy('urutan')->pluck('user_id');
        if ($anggota->isEmpty()) return null;

        $beban = Task::whereIn('assignee_user_id', $anggota)->whereIn('status', ['open', 'in_progress', 'pending'])
            ->groupBy('assignee_user_id')->selectRaw('assignee_user_id, COUNT(*) n')->pluck('n', 'assignee_user_id');

        return $anggota->sortBy(fn ($id, $i) => [(int) ($beban[$id] ?? 0), $i])->first();
    }

    /**
     * Task meja yang belum berpengerja, atau pengerjanya sudah keluar dari meja itu → ditugaskan
     * ke anggota meja. Task yang sedang dikerjakan (in_progress) tidak dipindah.
     */
    private function tugaskanYangKosong(): array
    {
        $rows = [];
        $anggota = MejaKerjaPeran::all()->groupBy('peran')->map(fn ($g) => $g->pluck('user_id')->all());

        Task::whereNotNull('peran')->whereIn('status', ['open', 'pending'])->orderBy('id')->get()
            ->each(function (Task $t) use ($anggota, &$rows) {
                $meja = $anggota[$t->peran] ?? [];
                if (!$meja || in_array((int) $t->assignee_user_id, $meja, true)) return;
                if (!($uid = $this->pilihPengerja($t->peran))) return;

                $t->assignee_user_id = $uid;
                $t->save();
                $nama = User::where('id', $uid)->value('name');
                $this->catat($t, null, 'ditugaskan', "Ditugaskan ke $nama");
                $rows[] = ['status' => Skill::DIUBAH, 'judul' => $t->title, 'alasan' => "Ditugaskan ke $nama.", 'tautan' => null, 'dokumen' => null, 'jenis' => null];
            });

        return $rows;
    }

    private function tutupYangBeres(SkillAdmin $skill): array
    {
        $rows = [];
        Task::where('skill', $skill->key())->whereIn('status', ['open', 'in_progress', 'pending'])->get()
            ->each(function (Task $t) use ($skill, &$rows) {
                if (!($k = $skill->keadaan($t))) {
                    if (method_exists($skill, 'perbarui') && ($row = $this->perbarui($skill, $t))) $rows[] = $row;
                    return;
                }
                [$status, $catatan] = $k;
                $this->ubahStatus($t, $status, null, $catatan);
                $rows[] = [
                    'status' => Skill::DIUBAH, 'judul' => $t->title,
                    'alasan' => ($status === 'done' ? 'Selesai otomatis: ' : 'Dibatalkan: ') . $catatan,
                    'tautan' => null, 'dokumen' => null, 'jenis' => $status === 'done' ? 'selesai' : 'batal',
                ];
            });

        return $rows;
    }

    /**
     * Skill yang kartunya berpindah fase mengikuti sumbernya (mis. retur Shopee: periksa →
     * banding → hasil): judul/keterangan/tenggat diganti, status dipindah bila diminta.
     */
    private function perbarui(SkillAdmin $skill, Task $t): ?array
    {
        if (!($u = $skill->perbarui($t))) return null;

        $t->forceFill(array_filter([
            'title'       => isset($u['title']) ? mb_substr($u['title'], 0, 255) : null,
            'description' => $u['description'] ?? null,
            'meta'        => $u['meta'] ?? null,
            'tenggat_at'  => $u['tenggat_at'] ?? null,
        ], fn ($v) => $v !== null));

        if ($u['status'] === 'pending' && $t->status !== 'pending') {
            $t->pending_alasan = mb_substr($u['catatan'], 0, 255);
            $this->ubahStatus($t, 'pending', null, 'Pending: ' . $u['catatan']);
        } elseif ($u['status'] && $u['status'] !== $t->status) {
            $t->pending_alasan = null;
            $this->ubahStatus($t, $u['status'], null, $u['catatan']);
        } else {
            $t->save();
            $this->catat($t, null, 'diperbarui', $u['catatan']);
        }

        return [
            'status' => Skill::DIUBAH, 'judul' => $t->title, 'alasan' => $u['catatan'],
            'tautan' => collect($skill->aksi($t))->firstWhere('utama')['url'] ?? null, 'dokumen' => null, 'jenis' => null,
        ];
    }

    /** @return array{0: \Carbon\Carbon, 1: array} saat lahir + atribut task baru */
    private function atribut(SkillAdmin $skill, MejaKerjaSkill $atur, array $k): array
    {
        $lahir = $k['dibuat_at'] ?? now();
        return [$lahir, [
            'title'            => mb_substr($k['judul'], 0, 255),
            'description'      => $k['deskripsi'] ?? null,
            'creator_user_id'  => $this->kv->akun(self::KARYAWAN)->id,
            'assignee_user_id' => $this->pilihPengerja($atur->peran),
            'status'           => 'open',
            'priority'         => 'normal',
            'taskable_type'    => $k['taskable_type'] ?? null,
            'taskable_id'      => $k['taskable_id'] ?? null,
            'source'           => 'skill',
            'skill'            => $skill->key(),
            'peran'            => $atur->peran,
            'kunci_sumber'     => $k['kunci'],
            // sla_faktor: pekerjaan yang lebih mendesak dari biasanya (mis. laporan barang sudah habis).
            // tenggat_at dari skill: batas dari luar (mis. batas respons penjual di Shopee).
            'tenggat_at'       => $k['tenggat_at'] ?? $lahir->copy()->addMinutes((int) round($atur->sla_menit * ($k['sla_faktor'] ?? 1))),
            'meta'             => $k['meta'] ?? null,
        ]];
    }

    /** Task bayangan: tidak disimpan, hanya untuk judul & tombol di jurnal. */
    private function calon(SkillAdmin $skill, MejaKerjaSkill $atur, array $k): Task
    {
        return new Task($this->atribut($skill, $atur, $k)[1]);
    }

    private function buat(SkillAdmin $skill, MejaKerjaSkill $atur, array $k): Task
    {
        [$lahir, $isi] = $this->atribut($skill, $atur, $k);
        $task = Task::create($isi);
        $task->forceFill(['created_at' => $lahir])->saveQuietly();
        $this->catat($task, null, 'dibuat', 'Dibuat oleh Manajer Admin — ' . $skill->label());
        // Skill yang perlu menautkan sumbernya ke task (mis. laporan Lapor Stok → task_id).
        if (method_exists($skill, 'setelahDibuat')) $skill->setelahDibuat($task, $k);

        return $task;
    }

    // ── Anggota meja ───────────────────────────────────────────────────────────

    /** @return string[] meja peran tempat user jadi anggota */
    public function peranUser(int $userId): array
    {
        return MejaKerjaPeran::where('user_id', $userId)->pluck('peran')->all();
    }

    // ── Perpindahan status ─────────────────────────────────────────────────────

    /** Hanya skill butuhMulai. Pengerja tetap tugasan Manajer Admin; diisi bila meja belum punya anggota. */
    public function mulai(Task $t, User $u): void
    {
        $t->assignee_user_id ??= $u->id;
        $this->ubahStatus($t, 'in_progress', $u, 'Mulai dikerjakan');
    }

    /** Pending → kembali: skill ber-Mulai yang sudah dimulai ke Dikerjakan, lainnya ke Antre. */
    public function lanjutkan(Task $t, ?User $u, string $catatan = 'Dilanjutkan'): void
    {
        $ke = self::butuhMulai($this->skill($t->skill)) && $t->mulai_at ? 'in_progress' : 'open';
        $this->ubahStatus($t, $ke, $u, $catatan);
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
        if ($status === 'in_progress') $t->mulai_at ??= now();
        if (in_array($status, ['open', 'in_progress'], true)) $t->pending_alasan = null;
        if ($status === 'pending') $t->pending_sejak = now();
        if (in_array($status, ['done', 'cancelled'], true)) $t->done_at = now();

        $t->status = $status;
        $t->save();
        $this->catat($t, $u, $status, $catatan);
    }

    /** Pending menunggu pelanggan: begitu pelanggan membalas chat WA tertaut → dilanjutkan. */
    private function lanjutkanYangDibalas(): array
    {
        $rows = [];
        Task::where('status', 'pending')->whereNotNull('meta')->get()->each(function (Task $t) use (&$rows) {
            $cid = $t->meta['crm_conversation_id'] ?? null;
            if (!$cid || !$t->pending_sejak) return;
            $masuk = CrmConversation::where('id', $cid)->value('last_inbound_at');
            if ($masuk && $t->pending_sejak->lt($masuk)) {
                $this->lanjutkan($t, null, 'Pelanggan membalas chat — dilanjutkan');
                $rows[] = ['status' => Skill::DIUBAH, 'judul' => $t->title, 'alasan' => 'Pelanggan membalas chat — dilanjutkan.', 'tautan' => null, 'dokumen' => null, 'jenis' => 'lanjut'];
            }
        });

        return $rows;
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
}
