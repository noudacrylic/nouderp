<?php

namespace App\Modules\Shipping\Services;

use App\Modules\Shipping\Contracts\ShippingProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Koordinat → alamat + area kurir.
 *
 * Agregator kurir hanya punya pencarian area dari TEKS, jadi koordinat di-reverse dulu
 * via OpenStreetMap Nominatim (gratis, tanpa API key), lalu hasilnya dipakai mencari
 * area di provider yang sedang aktif. Dipakai dua tempat: "Lacak Alamat" di Cek Ongkir
 * (operator ERP, peta Leaflet) dan peta Google di checkout etalase — keduanya butuh
 * jawaban yang sama, jadi logikanya duduk di sini, bukan di controller.
 */
class PointAddressResolver
{
    /**
     * @return array{address:?string, area_id:?string, area_label:?string, postal_code:?string, error:?string}
     */
    public function resolve(float $lat, float $lng, ?ShippingProvider $provider = null): array
    {
        [$address, $postal, $localityQuery] = $this->reverseGeocode($lat, $lng);

        // Prioritas kode pos, fallback nama lokasi — kode pos jauh lebih presisi
        // untuk mencocokkan area di kamus wilayah agregator.
        $area = null;
        if ($provider) {
            foreach (array_filter([$postal, $localityQuery]) as $q) {
                $q = trim((string) $q);
                if (mb_strlen($q) < 3) {
                    continue;
                }
                $search = $provider->searchAreas($q);
                if (($search['success'] ?? false) && !empty($search['areas'])) {
                    $area = $search['areas'][0];
                    break;
                }
            }
        }

        return [
            'address'     => $address,
            'area_id'     => isset($area['id']) ? (string) $area['id'] : null,
            'area_label'  => $area['name'] ?? null,
            'postal_code' => $area['postal_code'] ?? $postal,
            'error'       => $address
                ? ($area ? null : 'Alamat ketemu tapi area kurir belum cocok — kurir instant tetap bisa dari titik lokasi.')
                : 'Alamat tidak ditemukan dari koordinat (kurir instant tetap bisa dari titik lokasi).',
        ];
    }

    /**
     * Reverse geocode Nominatim → [alamat lengkap, kode pos, teks lokasi untuk pencarian area].
     *
     * @return array{0:?string, 1:?string, 2:?string}
     */
    private function reverseGeocode(float $lat, float $lng): array
    {
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
                $a        = $data['address'] ?? [];
                $village  = $a['village'] ?? $a['suburb'] ?? $a['neighbourhood'] ?? null;
                $district = $a['city_district'] ?? $a['district'] ?? $a['municipality'] ?? $a['subdistrict'] ?? null;
                $city     = $a['city'] ?? $a['town'] ?? $a['county'] ?? $a['regency'] ?? null;

                return [
                    $data['display_name'],
                    $a['postcode'] ?? null,
                    collect([$village, $district, $city])->filter()->implode(' ') ?: null,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('Reverse geocode Nominatim gagal', ['error' => $e->getMessage()]);
        }

        return [null, null, null];
    }
}
