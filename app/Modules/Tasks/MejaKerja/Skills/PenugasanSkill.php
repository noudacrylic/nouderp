<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

use App\Modules\KaryawanVirtual\Contracts\PunyaPengaturan;
use App\Modules\KaryawanVirtual\Contracts\Skill;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;

/**
 * Penugasan Manajer Admin atas task yang SUDAH ada (semua skill & sumber): task Pending
 * menunggu pelanggan → kembali Dikerjakan begitu pelanggan membalas chat. (Pengganti admin
 * yang tak masuk kerja menyusul sebagai skill tersendiri.)
 *
 * Bukan SkillAdmin (tidak membuat task). Tanpa mode Bayangan: hanya memindah pemegang /
 * status task, tidak mengubah dokumen ERP.
 */
class PenugasanSkill implements Skill, PunyaPengaturan
{
    public function label(): string { return 'Atur penugasan'; }

    public function deskripsi(): string
    {
        return 'Task Pending "menunggu pelanggan" kembali Dikerjakan saat pelanggan membalas chat.';
    }

    public function jadwal(): string { return 'Tiap 2 menit'; }

    public function satuan(): string { return 'task'; }

    public function modeTersedia(): array { return [self::NONAKTIF, self::OTOMATIS]; }

    public function modeBawaan(): string { return self::OTOMATIS; }

    public function alasanTerkunci(): ?string { return null; }

    public function jalankan(bool $simulasi): array
    {
        return $simulasi ? [] : app(MejaKerjaService::class)->aturPenugasan();
    }

    public function rincian(array $hasil): array { return $hasil; }

    public function urlPengaturan(string $kode): string
    {
        return route('karyawan-virtual.admin.pengaturan', $kode);
    }
}
