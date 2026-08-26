<?php

namespace App\Modules\Analysis\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analysis\Support\AnalysisCache;

/**
 * Tombol "Hitung ulang" di halaman Analisa.
 *
 * Angka Analisa sudah dihitung ulang sendiri begitu ada data yang berubah (sidik jari
 * data — lihat AnalysisCache). Tombol ini tetap ada karena sidik jari punya celah yang
 * jujur harus diakui: perubahan lewat `DB::table()->update()` pada tabel tanpa kolom
 * `updated_at` tidak menggeser cap apa pun. Tanpa tombol, satu-satunya jalan keluar dari
 * keadaan itu adalah menunggu programmer.
 */
class AnalysisCacheController extends Controller
{
    public function refresh(AnalysisCache $cache)
    {
        $cache->bump();

        return back()->with('success', 'Angka Analisa dihitung ulang dari data terbaru.');
    }
}
