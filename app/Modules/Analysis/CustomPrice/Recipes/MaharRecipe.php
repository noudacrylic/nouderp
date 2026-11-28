<?php

namespace App\Modules\Analysis\CustomPrice\Recipes;

use App\Modules\Analysis\CustomPrice\Recipe;

/**
 * Frame Mahar (AM-PxLxT) ukuran custom — produk ready yang diminta beda ukuran, tebal, atau
 * warna body (mis. body putih, tutup bening).
 *
 * Susunan panel dari pemilik (25 Nov 2026):
 *   Bawah P×L ×1 · Samping P×T ×2 · Depan-belakang L×T ×2 · Sliding P×1,2 cm ×4 · Tutup P×L ×1
 * Tutup tidak dilem (geser), jadi komponen dilem = 9. Sliding ikut bahan body.
 *
 * Panjang lem = keliling bawah 2(P+L) + 4 rusuk tegak (4T) + 4 sliding sepanjang P. Bentuk
 * rumus ini diuji ke timer Mahar ready 25×25 s/d 60×40 dan Tempat Obat — meleset ±6%.
 */
class MaharRecipe implements Recipe
{
    public function code(): string
    {
        return 'mahar';
    }

    public function label(): string
    {
        return 'Frame Mahar';
    }

    public function formView(): string
    {
        return 'erp.analisa.harga-custom.resep.mahar';
    }

    public function params(): array
    {
        return [
            'sliding_lebar'  => ['label' => 'Lebar sliding',  'default' => 1.2, 'unit' => 'cm'],
            'sliding_jumlah' => ['label' => 'Jumlah sliding', 'default' => 4,   'unit' => 'pcs'],
        ];
    }

    public function materialRoles(): array
    {
        return ['tutup' => 'Tutup'];
    }

    public function normalize(array $raw): array
    {
        $n = fn ($k) => max(0.0, (float) clean_number($raw[$k] ?? 0));

        return ['p' => $n('p'), 'l' => $n('l'), 't' => $n('t')];
    }

    public function validate(array $input): array
    {
        if ($input['p'] <= 0 || $input['l'] <= 0 || $input['t'] <= 0) {
            return ['Panjang, lebar, dan tebal frame wajib diisi.'];
        }

        return [];
    }

    public function build(array $input, array $params): array
    {
        $P = $input['p'];
        $L = $input['l'];
        $T = $input['t'];
        $slidingLebar  = (float) $params['sliding_lebar'];
        $slidingJumlah = (float) $params['sliding_jumlah'];

        $panels = [
            ['bagian' => 'Bawah',          'role' => 'utama', 'p' => $P, 'l' => $L,            'qty' => 1.0],
            ['bagian' => 'Samping',        'role' => 'utama', 'p' => $P, 'l' => $T,            'qty' => 2.0],
            ['bagian' => 'Depan-belakang', 'role' => 'utama', 'p' => $L, 'l' => $T,            'qty' => 2.0],
            ['bagian' => 'Sliding',        'role' => 'utama', 'p' => $P, 'l' => $slidingLebar, 'qty' => $slidingJumlah],
            ['bagian' => 'Tutup',          'role' => 'tutup', 'p' => $P, 'l' => $L,            'qty' => 1.0],
        ];
        $panels = array_values(array_filter($panels, fn ($p) => $p['qty'] > 0 && $p['l'] > 0));

        return [
            'panels'          => $panels,
            'accessories'     => [],
            'lem_cm'          => 2 * ($P + $L) + 4 * $T + $slidingJumlah * $P,
            'komponen'        => 1 + 2 + 2 + $slidingJumlah,
            'accessory_count' => 0.0,
            'outer'           => ['p' => $P, 'l' => $L, 't' => $T],
            'description'     => $this->fmt($P) . ' x ' . $this->fmt($L) . ' x ' . $this->fmt($T) . ' cm',
        ];
    }

    /** Mahar ready ber-SKU AM-{P}x{L}x{T}; dicoba dua arah karena 40x30 kadang ditulis 30x40. */
    public function readySkus(array $input): array
    {
        $p = $this->fmt($input['p'] ?? 0);
        $l = $this->fmt($input['l'] ?? 0);
        $t = $this->fmt($input['t'] ?? 0);

        return array_values(array_unique(["AM-{$p}x{$l}x{$t}", "AM-{$l}x{$p}x{$t}"]));
    }

    private function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');
    }
}
