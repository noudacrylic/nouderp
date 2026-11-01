<?php

namespace App\Modules\KaryawanVirtual\Contracts;

/**
 * Skill persetujuan yang usulannya diajukan MANUSIA lewat tombol (UsulanService::ajukanSatu),
 * bukan hasil pemeriksaan berkala. Pemeriksaan (jalankan) tidak menghasilkan usulan, jadi
 * usulan yang menunggu tidak boleh dibatalkan hanya karena pemeriksaan tak mengusulkannya.
 */
interface DiajukanManusia extends BisaDiusulkan
{
}
