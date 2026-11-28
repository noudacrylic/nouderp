<?php

namespace App\Modules\Analysis\CustomPrice\Recipes;

use App\Modules\Analysis\CustomPrice\Recipe;

/**
 * Tempat Obat kantong (TO-8-M2-2, ready 19 × 8 × 6 cm) ukuran custom.
 *
 * Susunan panel dari pemilik (25 Nov 2026), P = panjang, L = lebar, T = tinggi:
 *   Bawah L×(P+0,5) ×1 · Samping T×(P+0,5) ×2 · Depan-belakang L×T ×2 · Kantong L×(T×0,7) ×1
 * Kantong = pelat depan tempat kertas label obat. 6 komponen dilem.
 *
 * Panjang lem = keliling bawah 2((P+0,5)+L) + 4 rusuk tegak (4T) + 2 sisi tegak kantong.
 * Dengan rumus rakit 15 dtk/komponen + 2,2 dtk/cm ini menghasilkan ±4,7 mnt untuk 19×8×6,
 * timer nyata 4,9 mnt (2 OP × 104 pcs).
 */
class TempatObatRecipe implements Recipe
{
    /** Ukuran produk ready → SKU-nya. SKU TO tidak memuat ukuran, jadi dipetakan tangan. */
    private const READY = ['19x8x6' => 'TO-8-M2-2'];

    public function code(): string
    {
        return 'tempat_obat';
    }

    public function label(): string
    {
        return 'Tempat Obat';
    }

    public function formView(): string
    {
        return 'erp.analisa.harga-custom.resep.tempat-obat';
    }

    public function params(): array
    {
        return [
            'tambah_panjang'  => ['label' => 'Tambahan panjang bawah & samping', 'default' => 0.5, 'unit' => 'cm'],
            'kantong_tinggi'  => ['label' => 'Tinggi kantong (× T)',             'default' => 0.7, 'unit' => '× T'],
            'kantong_sisi_lem' => ['label' => 'Sisi kantong yang dilem',         'default' => 2,   'unit' => 'sisi tegak'],
        ];
    }

    public function materialRoles(): array
    {
        return ['kantong' => 'Kantong'];
    }

    public function normalize(array $raw): array
    {
        $n = fn ($k) => max(0.0, (float) clean_number($raw[$k] ?? 0));

        return ['p' => $n('p'), 'l' => $n('l'), 't' => $n('t')];
    }

    public function validate(array $input): array
    {
        if ($input['p'] <= 0 || $input['l'] <= 0 || $input['t'] <= 0) {
            return ['Panjang, lebar, dan tinggi tempat obat wajib diisi.'];
        }

        return [];
    }

    public function build(array $input, array $params): array
    {
        $P  = $input['p'];
        $L  = $input['l'];
        $T  = $input['t'];
        $Pp = $P + (float) $params['tambah_panjang'];
        $Tk = $T * (float) $params['kantong_tinggi'];

        $panels = [
            ['bagian' => 'Bawah',          'role' => 'utama',   'p' => $Pp, 'l' => $L,  'qty' => 1.0],
            ['bagian' => 'Samping',        'role' => 'utama',   'p' => $Pp, 'l' => $T,  'qty' => 2.0],
            ['bagian' => 'Depan-belakang', 'role' => 'utama',   'p' => $L,  'l' => $T,  'qty' => 2.0],
            ['bagian' => 'Kantong',        'role' => 'kantong', 'p' => $L,  'l' => $Tk, 'qty' => 1.0],
        ];
        $panels = array_values(array_filter($panels, fn ($p) => $p['p'] > 0 && $p['l'] > 0));

        return [
            'panels'          => $panels,
            'accessories'     => [],
            'lem_cm'          => 2 * ($Pp + $L) + 4 * $T + (float) $params['kantong_sisi_lem'] * $Tk,
            'komponen'        => 6.0,
            'accessory_count' => 0.0,
            'outer'           => ['p' => $Pp, 'l' => $L, 't' => $T],
            'description'     => $this->fmt($P) . ' x ' . $this->fmt($L) . ' x ' . $this->fmt($T) . ' cm',
        ];
    }

    public function readySkus(array $input): array
    {
        $out = [];
        foreach ([[$input['p'] ?? 0, $input['l'] ?? 0], [$input['l'] ?? 0, $input['p'] ?? 0]] as [$a, $b]) {
            $key = $this->fmt($a) . 'x' . $this->fmt($b) . 'x' . $this->fmt($input['t'] ?? 0);
            if (isset(self::READY[$key])) {
                $out[] = self::READY[$key];
            }
        }

        return array_values(array_unique($out));
    }

    private function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');
    }
}
