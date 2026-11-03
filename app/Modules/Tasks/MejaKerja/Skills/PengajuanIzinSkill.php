<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

use App\Models\User;
use App\Modules\SDM\Models\IzinRequest;
use App\Modules\SDM\Services\IzinKeputusanService;
use App\Modules\Tasks\MejaKerja\Contracts\JadiSkillManajer;
use App\Modules\Tasks\MejaKerja\Contracts\PunyaJawaban;
use App\Modules\Tasks\MejaKerja\Contracts\SkillOwner;
use App\Modules\Tasks\Models\Task;
use Carbon\Carbon;

/**
 * Pengajuan izin karyawan (PWA) yang menunggu → kartu Setujui/Tolak di Meja Owner. Semua yang
 * masih menunggu ikut masuk, termasuk yang diajukan sebelum skill dinyalakan. Kartu selesai
 * sendiri begitu diputus, dari mana pun keputusannya (kartu, Telegram, halaman SDM).
 */
class PengajuanIzinSkill implements SkillOwner, PunyaJawaban
{
    use JadiSkillManajer;

    public function key(): string { return 'pengajuan-izin'; }

    public function label(): string { return 'Pengajuan izin karyawan'; }

    public function deskripsi(): string
    {
        return 'Pengajuan izin yang masuk dari aplikasi karyawan → kartu Setujui/Tolak di Meja Owner. Saat Otomatis, tombolnya juga dikirim ke Telegram.';
    }

    /** Skill baru: dilihat dulu di Bayangan sebelum kartunya sungguh dibuat. */
    public function modeBawaan(): string { return self::BAYANGAN; }

    public function peranBawaan(): string { return 'owner'; }

    public function bobotBawaan(): int { return 8; }

    public function slaMenitBawaan(): int { return 240; }

    public function tersedia(): bool { return true; }

    public function satuan(): string { return 'pengajuan'; }

    public function pindai(Carbon $sejak): iterable
    {
        $keputusan = app(IzinKeputusanService::class);

        foreach (IzinRequest::with('karyawan')->where('status', 'pending')->orderBy('id')->get() as $r) {
            $peringatan = $keputusan->peringatan($r);
            $tanggal = $r->tanggal->translatedFormat('d M Y') . ($r->tanggal_akhir ? ' s/d ' . $r->tanggal_akhir->translatedFormat('d M Y') : '');

            yield [
                'kunci'         => IzinKeputusanService::kunciKartu($r),
                'judul'         => ($peringatan ? '⚠️ ' : '') . 'Izin ' . $r->karyawan?->name . ': ' . $r->typeLabel() . ' · ' . $tanggal,
                'deskripsi'     => trim('Alasan: ' . $r->alasan . "\n" . collect($peringatan)->map(fn ($p) => "⚠️ $p")->implode("\n")),
                'taskable_type' => IzinRequest::class,
                'taskable_id'   => $r->id,
                'dibuat_at'     => $r->created_at,
            ];
        }
    }

    public function keadaan(Task $task): ?array
    {
        $r = IzinRequest::find($task->taskable_id);
        return match ($r?->status) {
            null       => ['cancelled', 'Pengajuan tidak ditemukan.'],
            'approved' => ['done', 'Disetujui.'],
            'rejected' => ['done', 'Ditolak.'],
            default    => null,
        };
    }

    public function aksi(Task $task): array
    {
        return [['label' => 'Tinjau pengajuan', 'url' => route('sdm.pengajuan-izin.show', $task->taskable_id), 'utama' => true]];
    }

    public function selesaiManual(): bool { return false; }

    public function jawaban(Task $task): array
    {
        return [['kunci' => 'setujui', 'label' => '✅ Setujui', 'utama' => true], ['kunci' => 'tolak', 'label' => '❌ Tolak']];
    }

    public function jawab(Task $task, string $kunci, User $u): string
    {
        return app(IzinKeputusanService::class)->putuskan(IzinRequest::findOrFail($task->taskable_id), $kunci === 'setujui', $u);
    }
}
