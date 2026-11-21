<?php

namespace App\Modules\Shipping\Services;

use App\Core\Inventory\Product;
use App\Modules\Shipping\ShippingManager;
use App\Modules\Sales\Services\PromotionService;

/**
 * Cek ongkir ala etalase: berat & dimensi paket dari produk, potongan ongkir dari promo.
 *
 * Satu tempat untuk checkout web dan agen AI WhatsApp (keputusan owner 16 Nov 2026:
 * potongan ongkir di WA harus sama persis dengan checkout web).
 */
class OngkirEtalaseService
{
    public function __construct(
        private ShippingManager $shipping,
        private PromotionService $promotions,
        private PackageDefaults $package,
    ) {}

    /**
     * Nama layanan untuk pembeli & pencocokan pilihan kurir. Jubelio sering mengulang nama
     * kurir di nama layanan ("Anteraja" + "Anteraja Same Day") — yang dobel tidak diulang.
     */
    public static function namaLayanan(array $r): string
    {
        $kurir   = trim((string) ($r['courier_name'] ?? ''));
        $layanan = trim((string) ($r['service_name'] ?? ''));

        return $kurir !== '' && stripos($layanan, $kurir) === 0 ? $layanan : trim($kurir . ' ' . $layanan);
    }

    /** Provider ongkir etalase — satu saja, supaya pembeli tidak disodori tarif ganda. */
    public function providerKey(): ?string
    {
        return $this->shipping->defaultProviderKey();
    }

    /** @return array<int, array<string, mixed>> wilayah tujuan dari provider etalase */
    public function cariWilayah(string $q): array
    {
        $key      = $this->providerKey();
        $provider = $key ? $this->shipping->provider($key) : null;

        return $provider ? ($provider->searchAreas($q)['areas'] ?? []) : [];
    }

    /**
     * Alamat ketikan pembeli ("Tembalang Semarang", "kec. coblong, bandung 40132") → wilayah,
     * urut paling cocok. Jubelio tak menemukan apa pun untuk kueri beberapa kata (lihat
     * PointAddressResolver), jadi dicari per kata/kode pos lalu dinilai dengan semua kata.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cariWilayahLonggar(string $teks): array
    {
        $bersih = mb_strtolower(preg_replace('/\b(kec(amatan)?|kel(urahan)?|kab(upaten)?|kota|desa|kode\s*pos)\b\.?/iu', ' ', $teks));
        $kata   = array_values(array_unique(array_filter(preg_split('/[\s,.\/]+/u', $bersih), fn ($w) => mb_strlen($w) >= 3)));

        $kueri = array_merge(preg_match('/\b\d{5}\b/', $teks, $kp) ? [$kp[0]] : [], [trim($teks)], array_filter($kata, fn ($w) => ! ctype_digit($w)));
        $calon = [];
        foreach (array_slice(array_unique($kueri), 0, 5) as $q) {
            foreach ($this->cariWilayah($q) as $a) {
                $calon[(string) ($a['id'] ?? '')] ??= $a;
            }
            // Hasil dari kode pos / kueri utuh cukup bila ada yang memuat semua kata.
            if (collect($calon)->contains(fn ($c) => $this->skorWilayah($c, $kata) === count($kata))) {
                break;
            }
        }

        $calon = array_values(array_filter($calon, fn ($a) => ($a['id'] ?? '') !== ''));
        usort($calon, fn ($x, $y) => $this->skorWilayah($y, $kata) <=> $this->skorWilayah($x, $kata));

        return $calon && $this->skorWilayah($calon[0], $kata) > 0 ? $calon : [];
    }

    /** Banyaknya kata pembeli yang muncul di nama wilayah (kelurahan, kecamatan, kota, provinsi, kode pos). */
    private function skorWilayah(array $a, array $kata): int
    {
        $nama = mb_strtolower(implode(' ', array_filter([$a['subdistrict'] ?? null, $a['district'] ?? null, $a['city'] ?? null,
            $a['province'] ?? null, $a['postal_code'] ?? null, $a['name'] ?? null])));

        return count(array_filter($kata, fn ($w) => str_contains($nama, $w)));
    }

    /**
     * Titik (shareloc / pin peta) → alamat + area kurir + kode pos. Kurir instan menghitung
     * dari koordinat, tapi Jubelio tetap mewajibkan kode pos & area tujuan.
     *
     * @return array{address:?string, area_id:?string, area_label:?string, postal_code:?string, error:?string}
     */
    public function wilayahDariTitik(float $lat, float $lng): array
    {
        $key = $this->providerKey();

        return app(PointAddressResolver::class)->resolve($lat, $lng, $key ? $this->shipping->provider($key) : null);
    }

    /**
     * Tarif per layanan + `discount` (potongan ongkir promo).
     *
     * @param  array<int, array{product_id:int, qty:numeric}>  $items
     * @return array{rates: array, errors: array}
     */
    public function tarif(array $tujuan, array $items): array
    {
        $res = $this->shipping->rates([
            'provider'                => $this->providerKey(),
            // Area id ke SEMUA kunci provider — nilainya memang dari provider aktif itu sendiri.
            'destination_area_id'     => $tujuan['destination_area_id'] ?? null,
            'destination_jubelio_id'  => $tujuan['destination_area_id'] ?? null,
            'destination_postal_code' => $tujuan['destination_postal_code'] ?? null,
            'mode'                    => $tujuan['mode'] ?? 'regular',
            'destination_latitude'    => $tujuan['destination_latitude'] ?? null,
            'destination_longitude'   => $tujuan['destination_longitude'] ?? null,
            'items'                   => $this->rateItems($items),
        ]);

        // Rincian barang WAJIB ikut: promo ongkir yang dibatasi daftar produk menghitung
        // ambang belanjanya hanya dari produk itu — tanpa items ambangnya selalu 0.
        $basis = $this->goodsBasis($items);
        $rates = collect($res['rates'] ?? [])->map(function ($r) use ($basis) {
            $gross = (float) ($r['price'] ?? 0);
            $disc  = $this->promotions->resolveShippingDiscount($basis['subtotal'], $gross, null, $basis['items']);
            $r['discount'] = $disc ? (float) min($disc['discount_amount'], $gross) : 0;

            return $r;
        })->all();

        return ['rates' => $rates, 'errors' => $res['errors'] ?? []];
    }

    /**
     * Item untuk cek ongkir. Berat WAJIB lewat PackageDefaults (aturan yang sama dengan form
     * Pengiriman ERP): bundle yang belum ditimbang dulu terkirim sebagai 1 gram. Dimensi ikut
     * karena untuk barang besar berat volumetriknya yang ditagih kurir.
     */
    public function rateItems(array $items): array
    {
        $ids = collect($items)->pluck('product_id')->map(fn ($v) => (int) $v)->unique();
        $products = Product::whereIn('id', $ids)->get()->keyBy('id');

        return collect($items)->map(function ($it) use ($products) {
            $p = $products[(int) $it['product_id']] ?? null;

            $row = [
                'name'     => 'Paket',
                'weight'   => max(1, $p ? $this->package->beratSatuan($p) : 0),
                'quantity' => max(1, (int) $it['qty']),
            ];

            foreach (['length' => 'length_cm', 'width' => 'width_cm', 'height' => 'height_cm'] as $k => $col) {
                if ($p && (float) ($p->{$col} ?? 0) > 0) {
                    $row[$k] = (float) $p->{$col};
                }
            }

            return $row;
        })->all();
    }

    /**
     * Dasar promo ongkir: subtotal barang (setelah diskon item) + rincian barangnya
     * (product_id/qty/unit_price) untuk promo ongkir yang dibatasi daftar produk.
     *
     * @return array{subtotal: float, items: array}
     */
    public function goodsBasis(array $items): array
    {
        $ids = collect($items)->pluck('product_id')->map(fn ($v) => (int) $v)->unique();
        $products = Product::whereIn('id', $ids)->where('is_sellable', true)->get()->keyBy('id');

        $input = [];
        foreach ($items as $it) {
            $p = $products->get((int) $it['product_id']);
            if (! $p) {
                continue;
            }
            $input[] = ['product_id' => $p->id, 'qty' => (float) $it['qty'], 'unit_price' => (float) ($p->display_price ?? 0)];
        }
        $discounts = $input ? $this->promotions->resolveItemDiscounts($input) : [];

        $subtotal = 0.0;
        foreach ($input as $pi) {
            $disc = (float) ($discounts[$pi['product_id']]['discount_amount'] ?? 0);
            $subtotal += $pi['qty'] * $pi['unit_price'] - $disc;
        }

        return ['subtotal' => max(0, $subtotal), 'items' => $input];
    }
}
