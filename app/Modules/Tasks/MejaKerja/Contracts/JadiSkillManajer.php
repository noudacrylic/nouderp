<?php

namespace App\Modules\Tasks\MejaKerja\Contracts;

use App\Modules\Tasks\MejaKerja\MejaKerjaService;

/**
 * Sisi karyawan virtual sebuah SkillAdmin: jalan tiap 2 menit lewat meja-kerja:sinkron,
 * mode Nonaktif / Bayangan (catat task yang AKAN dibuat, tanpa membuatnya) / Otomatis.
 * Tanpa mode persetujuan — membagi kerja ke manusia tidak mengubah dokumen apa pun.
 */
trait JadiSkillManajer
{
    public function jadwal(): string { return 'Tiap 2 menit'; }

    public function satuan(): string { return 'task'; }

    public function modeTersedia(): array { return [self::NONAKTIF, self::BAYANGAN, self::OTOMATIS]; }

    public function modeBawaan(): string { return self::NONAKTIF; }

    public function alasanTerkunci(): ?string { return null; }

    public function jalankan(bool $simulasi): array
    {
        return app(MejaKerjaService::class)->kerjakan($this, $simulasi);
    }

    /** Hasil kerjakan() sudah berupa rincian jurnal. */
    public function rincian(array $hasil): array { return $hasil; }

    public function urlPengaturan(string $kode): string
    {
        return route('karyawan-virtual.admin.pengaturan', $kode) . '#skill-' . $this->key();
    }
}
