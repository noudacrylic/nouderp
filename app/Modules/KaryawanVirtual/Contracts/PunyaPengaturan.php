<?php

namespace App\Modules\KaryawanVirtual\Contracts;

/**
 * Skill yang punya halaman pengaturan sendiri (data yang dipakai skill, bukan mode) —
 * kartunya di halaman karyawan virtual menampilkan tombol "⚙ Pengaturan".
 */
interface PunyaPengaturan
{
    /** URL halaman pengaturan skill ini untuk karyawan virtual $kode. */
    public function urlPengaturan(string $kode): string;
}
