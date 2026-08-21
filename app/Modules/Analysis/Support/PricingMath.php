<?php

namespace App\Modules\Analysis\Support;

/**
 * Aritmetika penetapan harga jual — dipisah dari service supaya bisa diuji telanjang.
 *
 * ── SATU ATURAN YANG MENENTUKAN SEGALANYA ─────────────────────────────────
 *
 * Potongan marketplace dipungut dari HARGA JUAL, bukan dari HPP. Karena itu menaikkan
 * harga juga menaikkan potongannya, dan mencari harga dari target keuntungan tidak bisa
 * sekadar "HPP + sekian persen" — harus dibalik:
 *
 *     untung = harga − HPP − (harga × p + F)
 *            = harga (1 − p) − F − HPP
 *     harga  = (HPP × (1 + markup) + F) ÷ (1 − p)
 *
 * ── BIAYA TETAP DITANGGUNG SEKALI PER PESANAN ─────────────────────────────
 *
 * F (proses pesanan, premi, biaya Jubelio) dipungut per pesanan, bukan per barang. Di
 * skenario grosir F dibagi jumlah barang, dan itulah sebabnya pesanan borongan terasa jauh
 * lebih ringan potongannya walau persentasenya sama persis — bukan karena marketplace
 * memberi tarif khusus.
 *
 * Keuntungan diukur sebagai MARKUP (untung ÷ HPP), sesuai cara pemilik menetapkan harga
 * dari modal. Margin (untung ÷ harga) tetap dihitung sebagai pembanding karena itu ukuran
 * yang dipakai laporan keuangan.
 */
class PricingMath
{
    /** Potongan atas sebuah nilai transaksi: persentase + biaya tetap sekali. */
    public static function deduction(float $amount, float $percent, float $fixed): float
    {
        return $amount * ($percent / 100) + $fixed;
    }

    /**
     * Satu skenario penjualan utuh.
     *
     * @param float $hpp   HPP per unit
     * @param float $price harga jual per unit
     * @param int   $qty   jumlah barang dalam satu pesanan (grosir > 1)
     */
    public static function scenario(?float $hpp, ?float $price, float $percent, float $fixed, int $qty = 1): array
    {
        $qty     = max(1, $qty);
        $price   = $price !== null ? (float) $price : null;
        $revenue = $price === null ? null : $price * $qty;
        $cost    = $hpp === null ? null : (float) $hpp * $qty;

        if ($revenue === null) {
            return [
                'qty' => $qty, 'price' => null, 'revenue' => null, 'cost' => $cost,
                'deduction' => null, 'deduction_percent' => null,
                'profit' => null, 'markup_percent' => null, 'margin_percent' => null,
            ];
        }

        $deduction = self::deduction($revenue, $percent, $fixed);
        $profit    = $cost === null ? null : $revenue - $deduction - $cost;

        return [
            'qty'               => $qty,
            'price'             => $price,
            'revenue'           => $revenue,
            'cost'              => $cost,
            'deduction'         => $deduction,
            // Persentase EFEKTIF: sudah termasuk biaya tetap yang terbagi rata.
            'deduction_percent' => $revenue > 0 ? $deduction / $revenue * 100 : null,
            'profit'            => $profit,
            'markup_percent'    => ($profit !== null && $cost > 0) ? $profit / $cost * 100 : null,
            'margin_percent'    => ($profit !== null && $revenue > 0) ? $profit / $revenue * 100 : null,
        ];
    }

    /**
     * Harga jual per unit yang menghasilkan markup yang diminta.
     * null kalau potongannya ≥ 100% — berapapun harganya tidak akan pernah untung.
     */
    public static function priceForMarkup(?float $hpp, float $markupPercent, float $percent, float $fixed, int $qty = 1): ?float
    {
        if ($hpp === null || $hpp <= 0 || $percent >= 100) {
            return null;
        }

        $qty = max(1, $qty);

        return ($hpp * (1 + $markupPercent / 100) + $fixed / $qty) / (1 - $percent / 100);
    }

    /**
     * Diskon terbesar yang masih tidak merugi, dalam persen dari harga jual.
     *
     * Titik impasnya: harga(1 − p) − F − HPP = 0  →  harga_min = (HPP + F) ÷ (1 − p).
     * null kalau harganya sudah rugi sejak awal — di situ pertanyaannya bukan lagi berapa
     * diskon yang aman, melainkan kenapa harganya segitu.
     */
    public static function maxDiscountPercent(?float $hpp, ?float $price, float $percent, float $fixed): ?float
    {
        if ($hpp === null || $price === null || $price <= 0 || $percent >= 100) {
            return null;
        }

        $minPrice = ($hpp + $fixed) / (1 - $percent / 100);

        return $minPrice >= $price ? null : (1 - $minPrice / $price) * 100;
    }

    /** Pembulatan ke atas — harga jual tidak pernah dibulatkan ke bawah, itu memakan untung. */
    public static function roundUp(?float $price, int $step = 100): ?float
    {
        if ($price === null || $step < 1) {
            return $price;
        }

        return ceil($price / $step) * $step;
    }
}
