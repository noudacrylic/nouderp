<?php

namespace App\Http\Controllers\Shipping;

use App\Http\Controllers\Controller;
use App\Modules\Shipping\Services\PointAddressResolver;
use App\Modules\Shipping\ShippingManager;
use Illuminate\Http\Request;

/**
 * Reverse geocode: koordinat (link Google Maps / "lat,long") → alamat + area kurir.
 * Dipakai di Cek Ongkir untuk kurir instant: paste titik lokasi → tahu alamatnya.
 *
 * Penerjemahannya sendiri ada di PointAddressResolver, dipakai bareng peta checkout
 * etalase supaya jawabannya tidak beda antara operator dan pembeli.
 */
class ReverseGeocodeController extends Controller
{
    public function __invoke(Request $request, ShippingManager $manager, PointAddressResolver $resolver)
    {
        // Provider pencari area = yang AKTIF. Dulu dipaku ke Biteship; begitu Biteship
        // dinonaktifkan, provider() mengembalikan null dan "Lacak Alamat" gagal diam-diam
        // (alamat & kode pos tidak pernah terisi, kurir instant lalu tak bisa dihitung).
        // Pemanggil lama bisa saja masih mengirim ?provider=biteship. Jangan menolak —
        // jatuhkan ke provider yang aktif, karena tujuan endpoint ini cuma menerjemahkan
        // koordinat jadi alamat + area.
        $provider = $manager->provider((string) $request->input('provider'))
            ?: $manager->provider((string) $manager->defaultProviderKey());

        if (!$provider) {
            return response()->json([
                'success' => false,
                'error'   => 'Belum ada kurir aktif. Aktifkan agregator di Settings → Integrasi.',
            ]);
        }
        $point = trim((string) $request->input('point', ''));
        $coord = parse_lat_long($point);

        if ($coord['latitude'] === null) {
            return response()->json([
                'success' => false,
                'error'   => 'Koordinat tidak terbaca. Paste link Google Maps atau format "lat,long" (mis. -7.79,110.36).',
            ]);
        }

        $lat = $coord['latitude'];
        $lng = $coord['longitude'];

        return response()->json([
            'success'   => true,
            'latitude'  => $lat,
            'longitude' => $lng,
        ] + $resolver->resolve($lat, $lng, $provider));
    }
}
