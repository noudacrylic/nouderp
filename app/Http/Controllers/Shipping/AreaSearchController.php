<?php

namespace App\Http\Controllers\Shipping;

use App\Http\Controllers\Controller;
use App\Modules\Shipping\ShippingManager;
use Illuminate\Http\Request;

/**
 * Pencarian area kurir → area id. Provider-aware: ?provider=biteship|kiriminaja.
 * Biteship → area_id (kelurahan/kecamatan/kota). KiriminAja → kecamatan id.
 * Default = biteship (kompatibel dgn pemakaian lama). Dipakai form gudang (asal),
 * Cek Ongkir (tujuan), & popup alamat customer.
 */
class AreaSearchController extends Controller
{
    public function __invoke(Request $request, ShippingManager $manager)
    {
        $query = trim((string) $request->input('input', $request->input('q', '')));
        // Default = provider aktif pertama (RajaOngkir). Fallback biteship utk kompat lama.
        $key   = $request->input('provider') ?: ($manager->defaultProviderKey() ?? 'biteship');

        $provider = $manager->provider($key) ?: $manager->provider($manager->defaultProviderKey() ?? 'biteship');
        if (!$provider) {
            return response()->json(['success' => false, 'areas' => [], 'error' => 'Provider tidak dikenal.']);
        }

        if (mb_strlen($query) < 3) {
            return response()->json(['success' => true, 'provider' => $provider->key(), 'areas' => []]);
        }

        $result = $provider->searchAreas($query);

        return response()->json([
            'success'  => $result['success'],
            'provider' => $provider->key(),
            'areas'    => $result['areas'] ?? [],
            'error'    => $result['error'] ?? null,
        ]);
    }
}
