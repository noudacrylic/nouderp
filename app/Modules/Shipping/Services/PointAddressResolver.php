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
 *
 * Area dipilih dengan MENCOCOKKAN kecamatan/kelurahan/kota, bukan mengambil hasil
 * pertama pencarian kode pos. Dua jebakan nyata (19 Nov 2026, titik Polda Jateng):
 *  - kode pos OSM bisa keliru (Mugassari tercatat 50228), dan satu kode pos di kamus
 *    agregator mencakup kelurahan berjauhan — "50228" → Kemijen, Semarang Timur;
 *  - ejaan kelurahan beda antar sumber ("Mugassari" OSM vs "Mugasari" Jubelio), dan
 *    Jubelio tak menemukan apa pun untuk kueri beberapa kata.
 * Karena itu kueri dimulai dari KECAMATAN (mengembalikan semua kelurahannya), lalu
 * kandidat dinilai; tanpa kecocokan kecamatan/kelurahan, area dibiarkan kosong —
 * lebih baik admin memilih sendiri daripada diisi tempat yang salah.
 */
class PointAddressResolver
{
    /**
     * @return array{address:?string, area_id:?string, area_label:?string, postal_code:?string, error:?string}
     */
    public function resolve(float $lat, float $lng, ?ShippingProvider $provider = null): array
    {
        $geo  = $this->reverseGeocode($lat, $lng);
        $area = $provider && $geo['address'] ? $this->cocokkanArea($provider, $geo) : null;

        return [
            'address'     => $geo['address'],
            'area_id'     => isset($area['id']) ? (string) $area['id'] : null,
            'area_label'  => $area['name'] ?? null,
            'postal_code' => $area['postal_code'] ?? $geo['postal'],
            'error'       => $geo['address']
                ? ($area ? null : 'Alamat ketemu tapi area kurir belum cocok — pilih kecamatan tujuan manual; kurir instant tetap dari titik lokasi.')
                : 'Alamat tidak ditemukan dari koordinat (kurir instant tetap bisa dari titik lokasi).',
        ];
    }

    /**
     * Cari kandidat dari beberapa kueri, nilai tiap kandidat, ambil yang terbaik.
     */
    private function cocokkanArea(ShippingProvider $provider, array $geo): ?array
    {
        $kueri = array_values(array_unique(array_filter([
            $geo['district'],
            $geo['village'],
            $geo['postal'],
            collect([$geo['village'], $geo['district'], $geo['city']])->filter()->implode(' ') ?: null,
        ], fn ($q) => $q !== null && mb_strlen(trim((string) $q)) >= 3)));

        $terbaik = null;
        $skorTerbaik = 0;
        $dilihat = [];

        foreach ($kueri as $q) {
            $search = $provider->searchAreas(trim((string) $q));
            if (!($search['success'] ?? false)) {
                continue;
            }
            foreach ($search['areas'] ?? [] as $area) {
                $id = (string) ($area['id'] ?? '');
                if ($id === '' || isset($dilihat[$id])) {
                    continue;
                }
                $dilihat[$id] = true;

                $skor = $this->skor($area, $geo);
                if ($skor > $skorTerbaik) {
                    $terbaik = $area;
                    $skorTerbaik = $skor;
                }
            }
            // Kelurahan + kecamatan sudah cocok — tak perlu kueri berikutnya.
            if ($skorTerbaik >= 6) {
                break;
            }
        }

        return $terbaik;
    }

    /**
     * 0 = tidak layak. Syarat minimum: kecamatan cocok, atau kelurahan + kota cocok.
     * Kota yang jelas BEDA langsung menggugurkan.
     *
     * Bagian area dibandingkan dengan SEMUA nama wilayah dari OSM, bukan per kolom:
     * OSM tak konsisten — di Jakarta "Jakarta Pusat" ada di city_district dan kota
     * berisi "Daerah Khusus Ibukota Jakarta", di Semarang kecamatan ada di district.
     */
    private function skor(array $area, array $geo): int
    {
        $semua    = array_values(array_filter(array_map([$this, 'normal'], $geo['names'])));
        $tingkKel = array_values(array_filter(array_map([$this, 'normal'], $geo['villages'])));
        // Kelurahan hanya dicocokkan ke nama tingkat kelurahan — kalau tidak, kelurahan
        // yang kebetulan senama kecamatannya (Tegalsari, Tegalsari) mengalahkan yang benar.
        $kena = function (?string $nilai, bool $kelurahan = false) use ($semua, $tingkKel): ?bool {
            $n = $this->normal($nilai);
            if ($n === '') {
                return null;
            }
            foreach ($kelurahan ? $tingkKel : $semua as $g) {
                if ($this->mirip($n, $g)) {
                    return true;
                }
            }

            return false;
        };

        // Provider tanpa field terstruktur (mis. RajaOngkir): "Kel, Kec, Kota, Prov. kodepos".
        $bagian = array_map('trim', explode(',', (string) ($area['name'] ?? '')));
        $kel  = $kena($area['subdistrict'] ?? null, true) ?? $kena($bagian[0] ?? null, true);
        $kec  = $kena($area['district'] ?? null)    ?? (count($bagian) >= 3 ? $kena($bagian[1]) : null);
        $kota = $kena($area['city'] ?? null)        ?? (count($bagian) >= 3 ? $kena($bagian[2]) : null);
        $pos  = $geo['postal'] && (string) ($area['postal_code'] ?? '') === (string) $geo['postal'];

        if ($kota === false) {
            return 0;
        }
        if (!$kec && !($kel && $kota)) {
            return 0;
        }

        return ($kec ? 2 : 0) + ($kel ? 4 : 0) + ($kota ? 1 : 0) + ($pos ? 1 : 0);
    }

    /** "Mugassari" ≈ "Mugasari"; "Kota Semarang" ≈ "KOTA SEMARANG" ≈ "Semarang". */
    private function normal(?string $s): string
    {
        $s = mb_strtolower(trim((string) $s));
        $s = preg_replace('/\b(kota|kabupaten|kab|adm|administrasi|kecamatan|kec|kelurahan|kel|desa)\b\.?/u', '', $s);
        $s = preg_replace('/[^a-z0-9]/u', '', $s);
        $s = preg_replace('/(.)\1+/u', '$1', $s);   // huruf kembar → satu (ss→s, oo→o)

        return preg_replace('/\d+$/', '', $s) ?: $s;  // buang "50249" di ekor bagian nama
    }

    private function mirip(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        return min(strlen($a), strlen($b)) >= 6 && levenshtein($a, $b) <= 1;
    }

    /**
     * Reverse geocode Nominatim → bagian alamat terstruktur.
     *
     * @return array{address:?string, postal:?string, village:?string, district:?string, city:?string, names:list<string>, villages:list<string>}
     */
    private function reverseGeocode(float $lat, float $lng): array
    {
        $kosong = ['address' => null, 'postal' => null, 'village' => null, 'district' => null, 'city' => null, 'names' => [], 'villages' => []];

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
                $a = $data['address'] ?? [];

                return [
                    'address'  => $data['display_name'],
                    'postal'   => $a['postcode'] ?? null,
                    'village'  => $a['village'] ?? $a['suburb'] ?? $a['neighbourhood'] ?? null,
                    'district' => $a['city_district'] ?? $a['district'] ?? $a['municipality'] ?? $a['subdistrict'] ?? null,
                    'city'     => $a['city'] ?? $a['town'] ?? $a['county'] ?? $a['regency'] ?? null,
                    'villages' => array_values(array_filter(array_map(
                        fn ($k) => $a[$k] ?? null,
                        ['village', 'suburb', 'neighbourhood', 'quarter', 'hamlet'],
                    ))),
                    'names'    => array_values(array_filter(array_map(
                        fn ($k) => $a[$k] ?? null,
                        ['village', 'suburb', 'neighbourhood', 'quarter', 'hamlet', 'city_district', 'district',
                         'municipality', 'subdistrict', 'city', 'town', 'county', 'regency'],
                    ))),
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('Reverse geocode Nominatim gagal', ['error' => $e->getMessage()]);
        }

        return $kosong;
    }
}
