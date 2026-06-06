<?php

namespace App\Http\Controllers\Shipping;

use App\Http\Controllers\Controller;
use App\Modules\Shipping\Providers\BiteshipProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reverse geocode: koordinat (link Google Maps / "lat,long") → alamat + area Biteship.
 * Dipakai di Cek Ongkir untuk kurir instant: paste titik lokasi → tahu alamatnya.
 *
 * Biteship hanya punya pencarian area dari teks, jadi koordinat di-reverse dulu via
 * OpenStreetMap Nominatim (gratis, tanpa API key), lalu hasilnya dipakai mencari area_id.
 */
class ReverseGeocodeController extends Controller
{
    public function __invoke(Request $request, BiteshipProvider $biteship)
    {
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

        // 1) Reverse geocode via OpenStreetMap Nominatim (gratis, tanpa API key).
        $address       = null;
        $postal        = null;
        $localityQuery = null;
        try {
            $res = Http::withHeaders([
                'User-Agent' => 'NoudERP/1.0 (cek-ongkir reverse geocode)',
            ])->timeout(10)->get('https://nominatim.openstreetmap.org/reverse', [
                'format'          => 'jsonv2',
                'lat'             => $lat,
                'lon'             => $lng,
                'addressdetails'  => 1,
                'accept-language' => 'id',
            ]);
            $data = $res->json() ?? [];

            if ($res->successful() && !empty($data['display_name'])) {
                $address  = $data['display_name'];
                $a        = $data['address'] ?? [];
                $postal   = $a['postcode'] ?? null;
                $village  = $a['village'] ?? $a['suburb'] ?? $a['neighbourhood'] ?? null;
                $district = $a['city_district'] ?? $a['district'] ?? $a['municipality'] ?? $a['subdistrict'] ?? null;
                $city     = $a['city'] ?? $a['town'] ?? $a['county'] ?? $a['regency'] ?? null;
                $localityQuery = collect([$village, $district, $city])->filter()->implode(' ');
            }
        } catch (\Throwable $e) {
            Log::warning('Reverse geocode Nominatim gagal', ['error' => $e->getMessage()]);
        }

        // 2) Cari area_id Biteship: prioritas kode pos, fallback nama lokasi.
        $area = null;
        foreach (array_filter([$postal, $localityQuery]) as $q) {
            $q = trim((string) $q);
            if (mb_strlen($q) < 3) {
                continue;
            }
            $search = $biteship->searchAreas($q);
            if (($search['success'] ?? false) && !empty($search['areas'])) {
                $area = $search['areas'][0];
                break;
            }
        }

        return response()->json([
            'success'     => true,
            'latitude'    => $lat,
            'longitude'   => $lng,
            'address'     => $address,
            'area_id'     => $area['id'] ?? null,
            'area_label'  => $area['name'] ?? null,
            'postal_code' => $area['postal_code'] ?? $postal,
            'error'       => $address
                ? ($area ? null : 'Alamat ketemu tapi area Biteship belum cocok — kurir instant tetap bisa dari titik lokasi.')
                : 'Alamat tidak ditemukan dari koordinat (kurir instant tetap bisa dari titik lokasi).',
        ]);
    }
}
