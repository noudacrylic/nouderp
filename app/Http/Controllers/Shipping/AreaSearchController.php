<?php

namespace App\Http\Controllers\Shipping;

use App\Http\Controllers\Controller;
use App\Modules\Shipping\Providers\BiteshipProvider;
use Illuminate\Http\Request;

/**
 * Pencarian area Biteship (kelurahan/kecamatan/kota) → area_id.
 * Dipakai oleh form gudang (asal) & Cek Ongkir (tujuan).
 */
class AreaSearchController extends Controller
{
    public function __invoke(Request $request, BiteshipProvider $biteship)
    {
        $query = trim((string) $request->input('input', $request->input('q', '')));

        if (mb_strlen($query) < 3) {
            return response()->json(['success' => true, 'areas' => []]);
        }

        $result = $biteship->searchAreas($query);

        return response()->json([
            'success' => $result['success'],
            'areas'   => $result['areas'] ?? [],
            'error'   => $result['error'] ?? null,
        ]);
    }
}
