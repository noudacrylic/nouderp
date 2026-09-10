<?php

namespace App\Modules\CRM\Agents;

use App\Modules\CRM\Models\CrmAgent;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Services\PencarianProdukService;

/**
 * Alat yang boleh dipanggil agen, beserta pelaksanaannya.
 *
 * Ronde 1 SELURUHNYA baca saja. Tidak ada alat yang mengubah data ERP — kecuali
 * `lempar_ke_manusia`, yang hanya menggeser label percakapan dan justru
 * pengaman, bukan risiko.
 *
 * Kontrak balikan meniru AiAccountantService yang sudah terbukti:
 *   ['content' => string]                 hasil biasa, dikembalikan ke model
 *   ['content' => string, '_lempar' => true]  agen menyerah ke manusia
 *
 * Hasil alat SELALU string, bukan JSON. Bukan kebetulan: model membaca kalimat
 * jauh lebih andal daripada struktur bersarang, dan galat yang dikembalikan
 * sebagai teks biasa ("Tidak ketemu…") membuatnya memperbaiki diri sendiri
 * alih-alih menggagalkan seluruh giliran.
 */
class AgenTools
{
    public function __construct(private PencarianProdukService $pencarian)
    {
    }

    /**
     * Skema alat per agen.
     *
     * URUTANNYA HARUS TETAP. Definisi alat ikut masuk awalan cache; daftar yang
     * urutannya berubah-ubah membatalkan cache tiap panggilan tanpa gejala apa
     * pun selain tagihan yang naik.
     *
     * @return array<int, array<string, mixed>>
     */
    public function skema(CrmAgent $agen): array
    {
        return match ($agen->kode) {
            CrmAgent::PENYAMBUTAN => [$this->skemaCariProduk(), $this->skemaLempar()],
            // Penjualan & cetak belum dikerjakan; keduanya minimal punya pintu
            // menyerah supaya tidak pernah terjebak mengarang jawaban.
            default => [$this->skemaLempar()],
        };
    }

    /**
     * @return array{content: string, _lempar?: bool}
     */
    public function jalankan(string $nama, array $input, ?CrmConversation $percakapan): array
    {
        return match ($nama) {
            'cari_produk'       => $this->cariProduk($input),
            'lempar_ke_manusia' => $this->lempar($input, $percakapan),
            default             => ['content' => 'Alat "' . $nama . '" tidak ada. Jangan panggil lagi.'],
        };
    }

    /* ------------------------------------------------------------ cari produk */

    private function skemaCariProduk(): array
    {
        return [
            'name'        => 'cari_produk',
            'description' => 'Cari barang di ERP untuk mengetahui stok, harga, dan tautan halamannya. '
                . 'WAJIB dipakai sebelum menyebut stok atau harga apa pun — jangan pernah '
                . 'menjawab dari ingatan. Kalau hasilnya lebih dari satu dan kamu tidak yakin '
                . 'yang dimaksud yang mana, tanyakan dulu ke pelanggan.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'kata_kunci' => [
                        'type'        => 'string',
                        'description' => 'Nama barang atau SKU, mis. "frame mahar 30x30" atau "box charger".',
                    ],
                ],
                'required' => ['kata_kunci'],
            ],
        ];
    }

    private function cariProduk(array $input): array
    {
        $kata = trim((string) ($input['kata_kunci'] ?? ''));

        if ($kata === '') {
            return ['content' => 'Kata kunci kosong. Sebutkan nama barang atau SKU-nya.'];
        }

        $hasil = $this->pencarian->untukAgen($kata);

        if (! $hasil) {
            return ['content' => 'Tidak ada barang yang cocok dengan "' . $kata . '". '
                . 'Jangan mengarang — tanyakan ke pelanggan nama barangnya, atau lempar ke tim.'];
        }

        $baris = [];

        foreach ($hasil as $p) {
            $bagian = [$p['nama']];

            if ($p['sku']) {
                $bagian[] = 'SKU ' . $p['sku'];
            }

            /*
             * Stok ditulis apa adanya, termasuk nol. Menyembunyikan stok nol
             * membuat agen menjawab seolah barangnya ada — kesalahan yang baru
             * ketahuan setelah pelanggan membayar.
             */
            $bagian[] = $p['custom']
                ? 'barang custom (dibuat setelah dipesan, bukan stok siap)'
                : ($p['preorder'] ? 'preorder, stok ' : 'stok ') . $this->angka($p['stok']);

            $harga = 'harga Rp' . number_format((float) $p['harga'], 0, ',', '.');

            if ($p['harga_coret']) {
                $harga .= ' (turun dari Rp' . number_format((float) $p['harga_coret'], 0, ',', '.')
                    . ($p['promo'] ? ', promo ' . $p['promo'] : '') . ')';
            }

            $bagian[] = $harga;

            if ($p['url']) {
                $bagian[] = 'tautan ' . $p['url'];
            }

            $baris[] = '- ' . implode(' | ', $bagian);
        }

        return ['content' => "Hasil pencarian (sebutkan apa adanya, jangan dibulatkan):\n"
            . implode("\n", $baris)];
    }

    private function angka(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
    }

    /* ------------------------------------------------------ lempar ke manusia */

    private function skemaLempar(): array
    {
        return [
            'name'        => 'lempar_ke_manusia',
            'description' => 'Serahkan percakapan ini ke tim. Pakai saat pelanggan menawar harga, '
                . 'saat pertanyaannya di luar pengetahuanmu, saat pelanggan kecewa, atau saat '
                . 'pembahasannya masuk ke keputusan yang mengikat. Ini jalan keluar yang sah — '
                . 'jauh lebih baik daripada mengarang jawaban. Sesudah memanggilnya, tulis satu '
                . 'kalimat pendek ke pelanggan bahwa kamu sambungkan ke tim.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'alasan' => [
                        'type'        => 'string',
                        'description' => 'Kenapa dilempar, satu kalimat. Dibaca tim, bukan pelanggan.',
                    ],
                ],
                'required' => ['alasan'],
            ],
        ];
    }

    private function lempar(array $input, ?CrmConversation $percakapan): array
    {
        $alasan = trim((string) ($input['alasan'] ?? '')) ?: 'tidak disebutkan';

        /*
         * Di mode uji percakapannya tidak ada, dan itu bukan galat: yang sedang
         * dinilai adalah KEPUTUSAN melempar, bukan efek sampingnya.
         */
        if ($percakapan) {
            $percakapan->forceFill([
                'queue_state'  => CrmConversation::QUEUE_KITA,
                'unread_count' => $percakapan->unread_count + 1,
            ])->save();
        }

        return [
            'content' => 'Sudah dilempar ke tim (alasan: ' . $alasan . '). '
                . 'Sekarang tulis satu kalimat pendek ke pelanggan bahwa kamu sambungkan ke tim. '
                . 'Jangan menjanjikan kapan tim membalas.',
            '_lempar' => true,
        ];
    }
}
