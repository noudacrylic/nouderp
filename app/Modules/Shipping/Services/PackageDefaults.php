<?php

namespace App\Modules\Shipping\Services;

use App\Core\Inventory\BundleComponent;
use App\Core\Inventory\Product;
use App\Core\Inventory\ProductBundle;

/**
 * Berat & dimensi paket bawaan untuk sebuah dokumen (SO / Faktur).
 *
 * Urutannya SELALU: yang sudah tersimpan di dokumen dulu (hasil timbang di sub-tab "Perlu
 * Ukur" atau isian Cek Ongkir), baru taksiran dari master produk. Tidak pernah angka karangan
 * seperti "1 kg" — 1 kg yang salah menghasilkan ongkir yang salah, dan operator tidak punya
 * petunjuk bahwa angka itu cuma tebakan aplikasi.
 *
 * Dimensi ditaksir dengan mengambil yang TERBESAR per sumbu, bukan dijumlah: angkanya hanya
 * titik awal supaya operator mengoreksi, bukan mengetik dari nol.
 *
 * Bundle dipakai berat & dimensi SENDIRINYA. Lihat beratProduk().
 *
 * Satu-satunya sumber aturan ini; dipakai form Pengiriman di SO/Faktur, form ukur di
 * Pemrosesan Pesanan, dan popup generate resi di Surat Jalan.
 */
class PackageDefaults
{
    /** Jenis produk tanpa wujud fisik — tidak menambah berat maupun dimensi paket. */
    private const TANPA_FISIK = ['service', 'non_stock'];

    /**
     * @param  object|null  $doc    SalesOrder / SalesInvoice (punya package_* & items)
     * @param  iterable|null $items Baris yang dipakai menaksir; default items milik $doc
     * @return array{weight_gram:int|null,length:float|null,width:float|null,height:float|null,
     *               estimated_weight:bool,estimated_dimensions:bool}
     */
    public function for(?object $doc, ?iterable $items = null): array
    {
        $weight = (int) ($doc->package_weight_gram ?? 0);
        $len    = (float) ($doc->package_length ?? 0);
        $wid    = (float) ($doc->package_width ?? 0);
        $hei    = (float) ($doc->package_height ?? 0);

        $perluBerat  = $weight <= 0;
        // Dimensi ditaksir hanya bila KETIGANYA kosong: satu sumbu terisi berarti sudah
        // pernah diukur orang, dan menimpanya dengan taksiran akan menghapus kerja itu.
        $perluDimensi = $len <= 0 && $wid <= 0 && $hei <= 0;

        if ($perluBerat || $perluDimensi) {
            [$estWeight, $estL, $estW, $estH] = $this->taksirDariProduk($items ?? ($doc->items ?? []));

            if ($perluBerat) {
                $weight = $estWeight;
            }
            if ($perluDimensi) {
                [$len, $wid, $hei] = [$estL, $estW, $estH];
            }
        }

        return [
            'weight_gram'          => $weight ?: null,
            'length'               => $len ?: null,
            'width'                => $wid ?: null,
            'height'               => $hei ?: null,
            // Penanda "ini masih tebakan" — dipakai UI untuk memberi tahu operator, dan untuk
            // memutuskan boleh/tidaknya angka ini ditimpa perhitungan ulang.
            'estimated_weight'     => $perluBerat && $weight > 0,
            'estimated_dimensions' => $perluDimensi && ($len > 0 || $wid > 0 || $hei > 0),
        ];
    }

    /** Berat total (gram) saja — untuk pemanggil yang tidak peduli dimensi. */
    public function weightFor(?object $doc, ?iterable $items = null): int
    {
        return (int) ($this->for($doc, $items)['weight_gram'] ?? 0);
    }

    /** @return array{0:int,1:float,2:float,3:float} [berat, p, l, t] */
    private function taksirDariProduk(iterable $items): array
    {
        $weight = 0.0;
        $len = $wid = $hei = 0.0;

        foreach ($items as $item) {
            $p = $item->product ?? null;
            if (!$p || in_array($p->sale_type ?? null, self::TANPA_FISIK, true)) {
                continue;
            }

            $qty = (float) ($item->qty ?? 0) * (float) ($item->conversion_to_base ?? 1);
            $weight += $this->beratProduk($p) * $qty;
            $len = max($len, (float) ($p->length_cm ?? 0));
            $wid = max($wid, (float) ($p->width_cm ?? 0));
            $hei = max($hei, (float) ($p->height_cm ?? 0));
        }

        return [(int) round($weight), $len, $wid, $hei];
    }

    /**
     * Berat satu satuan produk.
     *
     * Bundle dipakai BERAT SENDIRINYA, bukan jumlah berat komponennya. Bundle di
     * sini bukan sekadar gabungan barang — ia produk yang sudah dikemas (bubble,
     * peti kayu), dan kemasan itulah yang membuat beratnya berbeda dari isinya.
     * Menjumlahkan komponen membuang berat kemasan, dan ongkirnya kurang tagih.
     *
     * Komponen hanya jadi CADANGAN, dipakai kalau bundle-nya belum pernah
     * ditimbang. Itu masih jauh lebih baik daripada 0, tapi tetap taksiran yang
     * merendahkan — bundle yang berat sendirinya kosong sebaiknya dilengkapi di
     * master produk.
     */
    private function beratProduk(object $p): float
    {
        $own = (float) ($p->weight_gram ?? 0);
        if ($own > 0 || ($p->sale_type ?? null) !== 'bundle' || empty($p->id)) {
            return $own;
        }

        return $this->beratKomponenBundle((int) $p->id);
    }

    /** Σ(berat komponen x qty) — cadangan untuk bundle yang belum ditimbang. */
    private function beratKomponenBundle(int $bundleId): float
    {
        $components = BundleComponent::where('bundle_product_id', $bundleId)->get();
        $qtyField   = 'qty';

        // Dua tabel bersejarah untuk hal yang sama; yang lama masih dipakai
        // sebagian data lama.
        if ($components->isEmpty()) {
            $components = ProductBundle::where('bundle_product_id', $bundleId)->get();
            $qtyField   = 'qty_required';
        }

        $total = 0.0;
        foreach ($components as $c) {
            $comp = Product::find($c->component_product_id);
            if ($comp) {
                $total += (float) ($comp->weight_gram ?? 0) * (float) ($c->{$qtyField} ?? 1);
            }
        }

        return $total;
    }
}
