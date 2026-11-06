<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

use App\Modules\KaryawanVirtual\Contracts\Skill;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Modules\Tasks\MejaKerja\PenggantiAdminService;

/**
 * Prioritas & pengganti admin (6 Nov 2026). Dua tugas:
 *  1. URUTAN — kartu Meja Layanan & Meja Desain diurutkan menurut config/meja_prioritas.php
 *     (PrioritasMeja) selama skill ini tidak Nonaktif. Urutan tidak mengubah data apa pun.
 *  2. PENGGANTI — admin yang tidak berangkat: chat + kartu tertentu dioper ke meja cadangan,
 *     dikembalikan saat ia hadir (PenggantiAdminService). Bayangan: hanya dicatat di jurnal.
 */
class PrioritasSkill implements Skill
{
    public const KEY = 'admin.prioritas';

    public function label(): string { return 'Prioritas & pengganti admin'; }

    public function deskripsi(): string
    {
        return 'Mengurutkan kartu Meja Layanan & Meja Desain menurut prioritas (retur < 24 jam, QC, purchasing, … / desain, desain gambar, …; Tugas dari Owner sesuai pilihan owner). '
            . 'Admin yang tidak berangkat (izin disetujui, atau belum scan fingerprint jam ' . config('meja_prioritas.jam_batas_scan') . '): '
            . 'layanan → chat + retur < 24 jam + QC dioper ke admin desain; desain → chat + kartu desain dioper ke admin layanan. Dikembalikan begitu ia scan.';
    }

    public function jadwal(): string { return 'Tiap 2 menit'; }

    public function satuan(): string { return 'kartu & chat'; }

    public function modeTersedia(): array { return [self::NONAKTIF, self::BAYANGAN, self::OTOMATIS]; }

    public function modeBawaan(): string { return self::NONAKTIF; }

    public function alasanTerkunci(): ?string { return null; }

    public function jalankan(bool $simulasi): array
    {
        return app(PenggantiAdminService::class)->jalankan($simulasi);
    }

    public function rincian(array $hasil): array { return $hasil; }

    /** Urutan prioritas berlaku? (mode apa pun selain Nonaktif) */
    public static function urutanAktif(): bool
    {
        try {
            return app(KaryawanVirtualService::class)->mode(self::KEY) !== self::NONAKTIF;
        } catch (\Throwable) {
            return false;
        }
    }
}
