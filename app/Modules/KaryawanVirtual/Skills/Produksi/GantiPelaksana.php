<?php

namespace App\Modules\KaryawanVirtual\Skills\Produksi;

use App\Modules\KaryawanVirtual\Contracts\DiajukanManusia;
use App\Modules\Production\Services\PergantianPelaksanaService;

/**
 * Oper langkah produksi ke pelaksana lain saat pelaksana lama masih aktif (sakit, dipindah).
 * Usulannya diajukan dari tombol "Ajukan Pergantian" di Proses Produksi, dikirim ke Telegram,
 * dan dikerjakan PergantianPelaksanaService::ganti() setelah disetujui.
 *
 * Selalu minta persetujuan: mengganti orang di tengah pekerjaan memengaruhi siapa yang
 * dinilai dan mencoret sampel waktu, jadi owner yang memutus.
 */
class GantiPelaksana implements DiajukanManusia
{
    public const KEY = PergantianPelaksanaService::SKILL;

    public function __construct(private PergantianPelaksanaService $service) {}

    public function label(): string
    {
        return 'Ganti pelaksana langkah produksi';
    }

    public function deskripsi(): string
    {
        return 'Meneruskan pengajuan "Ajukan Pergantian" dari Proses Produksi ke owner lewat Telegram. Setelah '
            . 'disetujui, pelaksana lama dicopot dari langkah, penggantinya dipasang, dan langkah itu dicoret dari '
            . 'sampel Analisa › Waktu Produksi (waktunya bukan satu orang dari awal). Selama menunggu, langkah tetap '
            . 'dikerjakan pelaksana lama. Karyawan yang dinonaktifkan di SDM tidak lewat sini: langkahnya langsung '
            . 'dilepas jadi "Tanpa pelaksana".';
    }

    public function jadwal(): string
    {
        return 'Saat ada pengajuan dari Proses Produksi';
    }

    public function satuan(): string
    {
        return 'langkah';
    }

    public function modeTersedia(): array
    {
        return [self::PERSETUJUAN];
    }

    public function modeBawaan(): string
    {
        return self::PERSETUJUAN;
    }

    public function alasanTerkunci(): ?string
    {
        return 'Mengganti orang di tengah pekerjaan selalu diputus owner.';
    }

    /** Tidak memeriksa apa pun sendiri — usulannya datang dari tombol. */
    public function jalankan(bool $simulasi): array
    {
        return [];
    }

    public function usulan(array $hasil): array
    {
        return [];
    }

    public function kerjakanUsulan(array $data): array
    {
        return [$this->service->ganti($data)];
    }

    public function masaBerlakuJam(): int
    {
        return 24;
    }

    public function rincian(array $hasil): array
    {
        return array_map(fn (array $r) => [
            'status'  => match ($r['jenis']) {
                'diganti'  => self::DIBUAT,
                'diajukan' => self::DIUSULKAN,
                default    => self::DILEWATI,
            },
            'judul'   => trim(($r['order_number'] ?? '') . ' · ' . $r['langkah'], ' ·')
                . ($r['dari'] !== '' ? ": {$r['dari']} → {$r['ke']}" : ''),
            'alasan'  => $r['alasan'] ?? null,
            'dokumen' => $r['order_number'] ?? null,
            'tautan'  => !empty($r['order_id']) ? route('production.orders.show', $r['order_id']) : null,
        ], $hasil);
    }
}
