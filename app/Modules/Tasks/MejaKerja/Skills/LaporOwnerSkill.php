<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

use App\Modules\Tasks\LaporOwner\LaporOwner;
use App\Modules\Tasks\MejaKerja\Contracts\JadiSkillManajer;
use App\Modules\Tasks\MejaKerja\Contracts\SkillOwner;
use App\Modules\Tasks\Models\Task;
use Carbon\Carbon;

/**
 * Laporan admin yang belum dibalas → kartu di Meja Owner. Selesai saat owner membalas, atau
 * lewat tombol Selesai bila cukup dibaca.
 */
class LaporOwnerSkill implements SkillOwner
{
    use JadiSkillManajer;

    public const KUNCI_KV = 'owner.lapor-owner';

    public function key(): string { return 'lapor-owner'; }

    public function label(): string { return 'Laporan dari admin'; }

    public function deskripsi(): string
    {
        return 'Laporan yang dikirim admin lewat tombol Lapor ke Owner → kartu di Meja Owner; balasan owner kembali ke pelapor.';
    }

    /** Laporan ditulis manusia dan sudah ditunggu: nonaktif/bayangan sama dengan laporan hilang. */
    public function modeBawaan(): string { return self::OTOMATIS; }

    public function peranBawaan(): string { return 'owner'; }

    public function bobotBawaan(): int { return 7; }

    public function slaMenitBawaan(): int { return 480; }

    public function tersedia(): bool { return true; }

    public function satuan(): string { return 'laporan'; }

    public function pindai(Carbon $sejak): iterable
    {
        foreach (LaporOwner::with('pelapor:id,name')->whereNull('dibalas_at')->orderBy('id')->get() as $l) {
            yield [
                'kunci'         => "lapor-owner:{$l->id}",
                'judul'         => ($l->penting ? '⚠️ ' : '') . mb_strimwidth($l->isi, 0, 90, '…') . ' (dari ' . $l->pelapor?->name . ')',
                'deskripsi'     => $l->isi,
                'taskable_type' => LaporOwner::class,
                'taskable_id'   => $l->id,
                'dibuat_at'     => $l->created_at,
                'sla_faktor'    => $l->penting ? 0.25 : 1,
            ];
        }
    }

    public function keadaan(Task $task): ?array
    {
        $l = LaporOwner::find($task->taskable_id);
        if (!$l) return ['cancelled', 'Laporan tidak ditemukan.'];

        return $l->dibalas_at ? ['done', 'Dibalas owner.'] : null;
    }

    public function aksi(Task $task): array
    {
        $l = LaporOwner::find($task->taskable_id);
        if (!$l) return [];

        $aksi = [[
            'label'      => 'Balas',
            'utama'      => true,
            'kirim'      => route('tasks.meja.lapor.balas', $l->id),
            'teks'       => '',
            'keterangan' => 'Balasan dikirim ke ' . ($l->pelapor?->name ?? 'pelapor') . ' dan kartu selesai.',
            'tombol'     => 'Kirim balasan',
            'tab_ini'    => true,
        ]];
        if ($l->foto_path) $aksi[] = ['label' => 'Lihat foto', 'url' => route('tasks.meja.lapor.foto', $l->id)];

        return $aksi;
    }

    public function selesaiManual(): bool { return true; }
}
