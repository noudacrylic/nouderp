<?php

namespace App\Modules\Analysis\CustomPrice\Recipes;

use App\Modules\Analysis\CustomPrice\Recipe;

/**
 * Kotak hadap atas — dasar kotak saran, kotak amal, die cast, kandang, dan semua produk yang
 * bentuknya kotak terbuka ke atas. Kotak hadap samping (lemari/kabinet) resep lain.
 *
 * Disepakati dengan pemilik 15 Okt 2026:
 *  - P×L×T adalah ukuran LUAR dan dipakai apa adanya. Tidak ada koreksi tebal: setiap panel
 *    dihitung ukuran penuh, jadi hasilnya selalu sedikit LEBIH BESAR dari kenyataan. Disengaja —
 *    "dibuat yang lebih besar biayanya untuk menghindari kerugian".
 *  - Sekat tiga jenis, masing-masing diisi jumlahnya: P×T (memanjang), L×T (melintang),
 *    P×L (mendatar/susun).
 *  - Kepala = P × tinggi kepala. Kantong maksimal satu, depan/samping hanya keterangan.
 *  - Tutup P×L; engsel = produk ERP, geser = rail 1 cm × P sebanyak 4.
 *  - Lubang (slot amal/saran) diabaikan — pengaruhnya ke biaya kecil.
 */
class KotakRecipe implements Recipe
{
    public function code(): string
    {
        return 'kotak';
    }

    public function label(): string
    {
        return 'Kotak (hadap atas)';
    }

    public function formView(): string
    {
        return 'erp.analisa.harga-custom.resep.kotak';
    }

    public function params(): array
    {
        return [
            'harga_grendel'         => ['label' => 'Harga grendel (pengunci beli)', 'default' => 10000, 'unit' => 'Rp'],
            'harga_pengunci_buatan' => ['label' => 'Harga pengunci bikin sendiri',  'default' => 3000,  'unit' => 'Rp'],
            'rail_lebar'            => ['label' => 'Lebar rail tutup geser',        'default' => 1,     'unit' => 'cm'],
            'rail_jumlah'           => ['label' => 'Jumlah rail tutup geser',       'default' => 4,     'unit' => 'pcs'],
        ];
    }

    public function materialRoles(): array
    {
        return [
            'alas'    => 'Alas',
            'dinding' => 'Dinding',
            'sekat'   => 'Sekat',
            'kepala'  => 'Kepala',
            'kantong' => 'Kantong',
            'tutup'   => 'Tutup & rail',
        ];
    }

    public function normalize(array $raw): array
    {
        $n = fn ($k) => max(0.0, (float) clean_number($raw[$k] ?? 0));

        $tipe  = in_array($raw['tutup_tipe'] ?? '', ['engsel', 'geser'], true) ? $raw['tutup_tipe'] : 'engsel';
        $kunci = in_array($raw['pengunci'] ?? '', ['beli', 'buat'], true) ? $raw['pengunci'] : 'tidak';

        return [
            'p' => $n('p'),
            'l' => $n('l'),
            't' => $n('t'),

            'sekat_pt' => (int) $n('sekat_pt'),
            'sekat_lt' => (int) $n('sekat_lt'),
            'sekat_pl' => (int) $n('sekat_pl'),

            'kepala_tinggi' => $n('kepala_tinggi'),

            'kantong'        => !empty($raw['kantong']),
            'kantong_posisi' => ($raw['kantong_posisi'] ?? '') === 'samping' ? 'samping' : 'depan',
            'kantong_p'      => $n('kantong_p'),
            'kantong_l'      => $n('kantong_l'),
            'kantong_t'      => $n('kantong_t'),

            'tutup'             => !empty($raw['tutup']),
            'tutup_tipe'        => $tipe,
            'engsel_product_id' => !empty($raw['engsel_product_id']) ? (int) $raw['engsel_product_id'] : null,
            'engsel_qty'        => (int) $n('engsel_qty'),

            'pengunci' => $kunci,
        ];
    }

    public function validate(array $input): array
    {
        $err = [];
        if ($input['p'] <= 0 || $input['l'] <= 0 || $input['t'] <= 0) {
            $err[] = 'Panjang, lebar, dan tinggi kotak wajib diisi.';
        }
        if ($input['kantong'] && ($input['kantong_p'] <= 0 || $input['kantong_l'] <= 0 || $input['kantong_t'] <= 0)) {
            $err[] = 'Ukuran kantong (P×L×T) wajib diisi kalau kotak memakai kantong.';
        }
        if ($input['tutup'] && $input['tutup_tipe'] === 'engsel' && $input['engsel_qty'] > 0 && !$input['engsel_product_id']) {
            $err[] = 'Pilih produk engsel yang dipakai.';
        }

        return $err;
    }

    public function build(array $input, array $params): array
    {
        $P = $input['p'];
        $L = $input['l'];
        $T = $input['t'];

        $panels = [];
        $add = function (string $bagian, string $role, float $p, float $l, float $qty) use (&$panels) {
            if ($qty > 0 && $p > 0 && $l > 0) {
                $panels[] = compact('bagian', 'role', 'p', 'l', 'qty');
            }
        };

        $add('Alas', 'alas', $P, $L, 1);
        $add('Depan & belakang', 'dinding', $P, $T, 2);
        $add('Samping', 'dinding', $L, $T, 2);
        // Dinding berdiri di atas alas: 4 rusuk tegak + keliling alas.
        $lem = 4 * $T + 2 * ($P + $L);

        $add('Sekat memanjang (P×T)', 'sekat', $P, $T, $input['sekat_pt']);
        $add('Sekat melintang (L×T)', 'sekat', $L, $T, $input['sekat_lt']);
        $add('Sekat mendatar (P×L)', 'sekat', $P, $L, $input['sekat_pl']);
        // Sekat tegak menempel di dua dinding + alas; sekat mendatar menempel keliling.
        $lem += $input['sekat_pt'] * (2 * $T + $P);
        $lem += $input['sekat_lt'] * (2 * $T + $L);
        $lem += $input['sekat_pl'] * (2 * ($P + $L));

        if ($input['kepala_tinggi'] > 0) {
            $add('Kepala', 'kepala', $P, $input['kepala_tinggi'], 1);
            $lem += $P;
        }

        if ($input['kantong']) {
            $kp = $input['kantong_p'];
            $kl = $input['kantong_l'];
            $kt = $input['kantong_t'];
            // Belakang kantong = dinding kotak, jadi cukup empat panel.
            $add('Kantong: depan', 'kantong', $kp, $kt, 1);
            $add('Kantong: samping', 'kantong', $kl, $kt, 2);
            $add('Kantong: bawah', 'kantong', $kp, $kl, 1);
            $lem += 4 * $kt + $kp + 2 * $kl;
        }

        $acc = [];
        $accCount = 0;

        if ($input['tutup']) {
            $add('Tutup', 'tutup', $P, $L, 1);

            if ($input['tutup_tipe'] === 'geser') {
                $rails = (float) $params['rail_jumlah'];
                $add('Rail tutup geser', 'tutup', $P, (float) $params['rail_lebar'], $rails);
                $lem += $rails * $P;
            } elseif ($input['engsel_qty'] > 0) {
                $acc[] = ['name' => 'Engsel', 'qty' => (float) $input['engsel_qty'], 'unit_price' => null, 'product_id' => $input['engsel_product_id']];
                $accCount += $input['engsel_qty'];
            }
        }

        if ($input['pengunci'] === 'beli') {
            $acc[] = ['name' => 'Grendel', 'qty' => 1.0, 'unit_price' => (float) $params['harga_grendel'], 'product_id' => null];
            $accCount += 1;
        } elseif ($input['pengunci'] === 'buat') {
            $acc[] = ['name' => 'Pengunci bikin sendiri', 'qty' => 1.0, 'unit_price' => (float) $params['harga_pengunci_buatan'], 'product_id' => null];
            $accCount += 1;
        }

        // Komponen dilem = semua panel kecuali tutup (lepas/berengsel). Rail tutup geser dilem.
        $komponen = 0.0;
        foreach ($panels as $pn) {
            if ($pn['bagian'] !== 'Tutup') {
                $komponen += $pn['qty'];
            }
        }

        return [
            'panels'          => $panels,
            'accessories'     => $acc,
            'lem_cm'          => (float) $lem,
            'komponen'        => $komponen,
            'accessory_count' => (float) $accCount,
            'outer'           => ['p' => $P, 'l' => $L, 't' => $T],
            'description'     => $this->describe($input),
        ];
    }

    public function readySkus(array $input): array
    {
        return [];
    }

    private function describe(array $in): string
    {
        $f = fn (float $v) => rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');

        $parts = [];
        $sekat = $in['sekat_pt'] + $in['sekat_lt'] + $in['sekat_pl'];
        if ($sekat > 0) {
            $parts[] = $sekat . ' sekat';
        }
        if ($in['kepala_tinggi'] > 0) {
            $parts[] = 'kepala ' . $f($in['kepala_tinggi']) . ' cm';
        }
        if ($in['kantong']) {
            $parts[] = 'kantong ' . $in['kantong_posisi'];
        }
        if ($in['tutup']) {
            $parts[] = $in['tutup_tipe'] === 'geser' ? 'tutup geser' : 'tutup engsel';
        } else {
            $parts[] = 'tanpa tutup';
        }
        if ($in['pengunci'] !== 'tidak') {
            $parts[] = 'pengunci';
        }

        return $f($in['p']) . ' x ' . $f($in['l']) . ' x ' . $f($in['t']) . ' cm'
            . ($parts ? ', ' . implode(', ', $parts) : '');
    }
}
