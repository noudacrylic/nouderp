<?php

namespace App\Modules\POS\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink;
use App\Modules\Marketplace\Jubelio\Services\JubelioOrderSyncService;

/**
 * Tombol "Bukan retur — batal sebelum dikirim" di kartu tab Retur.
 *
 * Garisnya: batal SEBELUM paket diserahkan ke kurir = pembatalan (void), SESUDAHNYA =
 * retur. Kasus yang terlanjur dibuka sebagai retur sebelum garis itu ada dikoreksi admin
 * dari sini; logikanya di JubelioOrderSyncService::koreksiReturJadiBatal.
 */
class KoreksiReturBatalController extends Controller
{
    public function __invoke(int $so, JubelioOrderSyncService $sync)
    {
        $link = JubelioOrderLink::where('sales_order_id', $so)->firstOrFail();

        $hasil = $sync->koreksiReturJadiBatal($link);

        return back()->with($hasil['success'] ? 'success' : 'error', $hasil['message']);
    }
}
