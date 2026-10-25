<?php

namespace App\Modules\KaryawanVirtual\Contracts;

/**
 * Satu kemampuan karyawan virtual. Skill hanya pembungkus: keputusan bisnisnya
 * tetap di service modul (mis. AutoProductionService), supaya tombol manual dan
 * jadwal memakai logika yang sama. Skill menerjemahkan hasil service itu ke
 * rincian jurnal yang bisa dibaca manusia.
 */
interface Skill
{
    public const NONAKTIF    = 'nonaktif';
    public const BAYANGAN    = 'bayangan';
    public const PERSETUJUAN = 'persetujuan';
    public const OTOMATIS    = 'otomatis';

    /** Status baris rincian jurnal. */
    public const DIBUAT      = 'dibuat';
    public const AKAN_DIBUAT = 'akan_dibuat';
    public const DILEWATI    = 'dilewati';
    /** Dokumen yang sudah ada diubah (mis. skor antrean OP dinaikkan). */
    public const DIUBAH      = 'diubah';
    public const GAGAL       = 'gagal';
    /** Mode persetujuan: usulan baru diajukan / usulan lama masih menunggu / usulan ditolak. */
    public const DIUSULKAN   = 'diusulkan';
    public const MENUNGGU    = 'menunggu';
    public const DITOLAK     = 'ditolak';

    public function label(): string;

    public function deskripsi(): string;

    /** Kapan skill ini bekerja, untuk ditampilkan (mis. "Tiap 30 menit"). */
    public function jadwal(): string;

    /** Yang diperiksa tiap jalan, untuk ringkasan (mis. "BOM"). */
    public function satuan(): string;

    /** Mode yang boleh dipilih. Satu mode saja = terkunci. */
    public function modeTersedia(): array;

    public function modeBawaan(): string;

    /** Alasan bila mode terkunci (ditampilkan di halaman), atau null. */
    public function alasanTerkunci(): ?string;

    /**
     * Kerjakan sekali. $simulasi = true (mode Bayangan) → periksa semuanya
     * tapi jangan mengubah data apa pun.
     *
     * @return array hasil mentah dari service modul
     */
    public function jalankan(bool $simulasi): array;

    /**
     * Hasil mentah → rincian jurnal.
     *
     * @return array<int, array{status: string, judul: string, alasan: ?string, tautan: ?string, dokumen: ?string}>
     */
    public function rincian(array $hasil): array;
}
