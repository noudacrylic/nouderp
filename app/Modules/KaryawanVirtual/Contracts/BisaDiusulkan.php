<?php

namespace App\Modules\KaryawanVirtual\Contracts;

/**
 * Skill yang bisa bermode "Minta persetujuan": hasil pemeriksaannya (jalankan
 * dengan $simulasi = true) diubah jadi usulan, dan tiap usulan dikerjakan satu
 * per satu setelah disetujui manusia.
 *
 * Syarat: rincian() skill ini harus 1:1 dengan baris hasil (indeks sama), karena
 * penanda usulan ditempel ke rincian berdasarkan indeks hasil.
 */
interface BisaDiusulkan extends Skill
{
    /**
     * Hasil simulasi → usulan, di-key indeks baris hasilnya.
     *
     * @return array<int, array{kunci: string, judul: string, alasan: ?string, data: array}>
     */
    public function usulan(array $hasil): array;

    /**
     * Kerjakan satu usulan yang sudah disetujui. WAJIB memeriksa ulang syaratnya
     * (keadaan bisa berubah sejak diusulkan) — bila tak lagi memenuhi, kembalikan
     * baris "dilewati" beserta alasannya, jangan memaksa.
     *
     * @return array hasil mentah, format sama dengan jalankan()
     */
    public function kerjakanUsulan(array $data): array;

    /** Berapa jam usulan boleh menunggu sebelum kedaluwarsa. */
    public function masaBerlakuJam(): int;
}
