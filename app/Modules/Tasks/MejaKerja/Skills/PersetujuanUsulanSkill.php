<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

use App\Modules\KaryawanVirtual\Models\KaryawanVirtualUsulan as Usulan;
use App\Modules\Tasks\MejaKerja\Contracts\SkillAdmin;
use App\Modules\Tasks\Models\Task;
use Carbon\Carbon;

/**
 * Persetujuan owner: usulan Karyawan Virtual yang menunggu keputusan → task di meja Owner.
 * Selesai sendiri begitu usulan disetujui/ditolak di halaman Karyawan Virtual (atau lewat
 * Telegram), batal bila kedaluwarsa/dibatalkan.
 */
class PersetujuanUsulanSkill implements SkillAdmin
{
    public function key(): string { return 'persetujuan-usulan'; }

    public function label(): string { return 'Persetujuan usulan Karyawan Virtual'; }

    public function deskripsi(): string
    {
        return 'Usulan Karyawan Virtual yang menunggu persetujuan → task di meja Owner, tertutup sendiri saat diputuskan.';
    }

    public function peranBawaan(): string { return 'owner'; }

    public function bobotBawaan(): int { return 7; }

    public function slaMenitBawaan(): int { return 240; }

    public function tersedia(): bool { return true; }

    public function pindai(Carbon $sejak): iterable
    {
        foreach (Usulan::menunggu()->where('created_at', '>=', $sejak)->orderBy('id')->get() as $u) {
            yield [
                'kunci'         => "usulan:{$u->id}",
                'judul'         => 'Setujui? ' . $u->judul,
                'deskripsi'     => $u->alasan,
                'taskable_type' => Usulan::class,
                'taskable_id'   => $u->id,
                'meta'          => ['karyawan' => $u->karyawan],
                'dibuat_at'     => $u->created_at,
            ];
        }
    }

    public function keadaan(Task $task): ?array
    {
        $u = Usulan::find($task->taskable_id);
        return match ($u?->status) {
            null                                        => ['cancelled', 'Usulan tidak ditemukan.'],
            Usulan::DISETUJUI                           => ['done', 'Disetujui.'],
            Usulan::DITOLAK                             => ['done', 'Ditolak.'],
            Usulan::KEDALUWARSA, Usulan::BATAL, Usulan::GAGAL => ['cancelled', 'Usulan ' . $u->status . '.'],
            default                                     => null,
        };
    }

    public function aksi(Task $task): array
    {
        return [['label' => 'Tinjau usulan', 'url' => route('karyawan-virtual.show', $task->meta['karyawan'] ?? ''), 'utama' => true]];
    }

    public function selesaiManual(): bool { return false; }
}
