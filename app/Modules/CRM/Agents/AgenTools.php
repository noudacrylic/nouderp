<?php

namespace App\Modules\CRM\Agents;

use App\Modules\CRM\Models\CrmAgent;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Services\PencarianProdukService;
use App\Modules\Marketplace\Shopee\Models\ShopeeOrder;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeClient;
use App\Modules\Tasks\MejaKerja\Skills\DesainSkill;
use App\Core\Inventory\Product;
use Carbon\Carbon;

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
    /** Provinsi Pulau Jawa — selain ini "luar Jawa" (wajib packing kayu, butir F2). */
    private const PROVINSI_JAWA = ['dki jakarta', 'jakarta', 'jawa barat', 'jawa tengah', 'jawa timur',
        'di yogyakarta', 'yogyakarta', 'daerah istimewa yogyakarta', 'banten'];

    public function __construct(
        private PencarianProdukService $pencarian,
        private ShopeeClient $shopee,
    ) {
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
            CrmAgent::CS_SHOPEE   => [$this->skemaCekStok(), $this->skemaInfoPesanan(), $this->skemaUsulkan()],
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
            'cek_stok'          => $this->cekStok($input),
            'info_pesanan'      => $this->infoPesanan($percakapan),
            'usulkan_balasan'   => $this->usulkan($input),
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

    /* ------------------------------------------------------------ CS Shopee */

    private function skemaCekStok(): array
    {
        return [
            'name'        => 'cek_stok',
            'description' => 'Cek stok ERP sebuah barang (nama atau SKU). Hasilnya juga menyebut apakah '
                . 'barang itu pre-order. WAJIB dipakai sebelum menyebut stok. Tidak memuat harga.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'kata_kunci' => ['type' => 'string', 'description' => 'Nama barang atau SKU, mis. "kotak saran 3 kotak" atau "BC-2x2".'],
                ],
                'required' => ['kata_kunci'],
            ],
        ];
    }

    /** Seperti cari_produk, tapi tanpa harga & tautan web: di Shopee keduanya tak boleh disebut. */
    private function cekStok(array $input): array
    {
        $kata = trim((string) ($input['kata_kunci'] ?? ''));
        if ($kata === '') {
            return ['content' => 'Kata kunci kosong. Sebutkan nama barang atau SKU-nya.'];
        }

        $hasil = $this->pencarian->untukAgen($kata);
        if (! $hasil) {
            return ['content' => 'Tidak ada barang yang cocok dengan "' . $kata . '". Jangan mengarang — tanya pembeli atau lempar ke admin.'];
        }

        $baris = array_map(fn ($p) => '- ' . $p['nama'] . ($p['sku'] ? ' | SKU ' . $p['sku'] : '') . ' | ' . ($p['custom']
            ? 'barang custom (dibuat setelah dipesan)'
            : ($p['preorder'] ? 'PRE-ORDER, stok ' : 'ready, stok ') . $this->angka($p['stok'])), $hasil);

        return ['content' => "Hasil cek stok (sebutkan apa adanya):\n" . implode("\n", $baris)];
    }

    private function skemaInfoPesanan(): array
    {
        return [
            'name'        => 'info_pesanan',
            'description' => 'Pesanan Shopee pembeli ini (30 hari terakhir): status, waktu bayar, barang, '
                . 'ready/pre-order, butuh desain atau tidak, dan provinsi tujuan (Jawa / luar Jawa). '
                . 'Pakai untuk pertanyaan kapan dikirim, packing kayu, dan apa pun tentang pesanannya.',
            'input_schema' => ['type' => 'object', 'properties' => (object) []],
        ];
    }

    private function infoPesanan(?CrmConversation $percakapan): array
    {
        if (! $percakapan || ! $percakapan->shopee() || ! ctype_digit((string) $percakapan->contact_key)) {
            return ['content' => 'Bukan percakapan Shopee — tidak ada data pesanan.'];
        }

        $pesanan = ShopeeOrder::with('items')
            ->where('shop_id', (int) $percakapan->business_number_id)
            ->where('buyer_user_id', (int) $percakapan->contact_key)
            ->where('dibuat_shopee_at', '>=', now()->subDays(30))
            ->orderByDesc('dibuat_shopee_at')->limit(5)->get();

        if ($pesanan->isEmpty()) {
            return ['content' => 'Pembeli ini belum punya pesanan dalam 30 hari terakhir (mungkin masih bertanya sebelum order).'];
        }

        $provinsi = $this->provinsi($percakapan, $pesanan->pluck('order_sn')->all());
        $pola = app(DesainSkill::class)->polaSku();
        $baris = [];

        foreach ($pesanan as $o) {
            $bayar = ($t = data_get($o->raw, 'pay_time')) ? Carbon::createFromTimestamp((int) $t, config('app.timezone'))->format('d/m H:i') : 'belum dibayar';
            $barang = $o->items->map(function ($i) use ($pola) {
                $p = $i->product_id ? Product::find($i->product_id) : null;
                $sku = (string) ($i->sku ?: $p?->sku);
                $sifat = [$p?->isPreorder() ? 'pre-order' : 'ready'];
                if ($sku !== '' && collect($pola)->contains(fn ($x) => \Illuminate\Support\Str::is(str_replace('%', '*', $x), $sku))) {
                    $sifat[] = 'butuh desain';
                }

                return "{$i->qty}× " . ($sku ?: $i->nama) . ' (' . trim($i->nama . ' ' . $i->nama_model) . '; ' . implode(', ', $sifat) . ')';
            })->implode('; ');

            $prov = $provinsi[$o->order_sn] ?? null;
            $baris[] = "- {$o->order_sn} | status Shopee {$o->status} | dibuat " . $o->dibuat_shopee_at?->format('d/m H:i')
                . " | dibayar {$bayar} | {$barang}"
                . ($prov ? ' | tujuan ' . $prov . (in_array(strtolower($prov), self::PROVINSI_JAWA, true) ? ' (Jawa)' : ' (LUAR JAWA)') : '');
        }

        return ['content' => "Pesanan pembeli ini (terbaru dulu):\n" . implode("\n", $baris)];
    }

    /**
     * Provinsi tujuan, diambil langsung dari Shopee saat dibutuhkan — alamat pembeli
     * sengaja TIDAK disimpan di shopee_orders. Gagal = kosong, agen tinggal tak tahu.
     *
     * @return array<string, string> order_sn => provinsi
     */
    private function provinsi(CrmConversation $percakapan, array $sn): array
    {
        $shop = ShopeeShop::where('env', $this->shopee->env())->where('shop_id', (int) $percakapan->business_number_id)->first();
        if (! $shop || ! $sn) {
            return [];
        }

        try {
            $r = $this->shopee->panggil($shop, 'GET', '/api/v2/order/get_order_detail', [
                'order_sn_list' => implode(',', $sn), 'response_optional_fields' => 'recipient_address',
            ]);
        } catch (\Throwable $e) {
            report($e);
            return [];
        }

        return collect($r['order_list'] ?? [])
            ->mapWithKeys(fn ($o) => [(string) $o['order_sn'] => trim((string) data_get($o, 'recipient_address.state'))])
            ->filter()->all();
    }

    private function skemaUsulkan(): array
    {
        return [
            'name'        => 'usulkan_balasan',
            'description' => 'WAJIB dipanggil tepat sekali di akhir: saran balasan untuk admin. Admin yang '
                . 'memutuskan mengirimnya. Panggil sesudah info yang perlu (stok/pesanan) sudah dicek.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'butir' => ['type' => 'string', 'description' => 'Kode butir daftar tanya-jawab yang dipakai, mis. "A1", "F2". "-" bila tidak ada yang cocok. "I" bila pesan tak perlu dibalas.'],
                    'teks' => ['type' => 'string', 'description' => 'Saran balasan untuk pembeli. Boleh kosong bila tak perlu dibalas, atau bila dilempar tanpa balasan sementara.'],
                    'lempar_ke' => ['type' => 'string', 'enum' => ['tidak', 'admin', 'owner'], 'description' => 'Siapa yang harus menangani: tidak (cukup saran ini), admin, atau owner.'],
                    'alasan' => ['type' => 'string', 'description' => 'Satu kalimat untuk admin: kenapa butir ini / kenapa dilempar.'],
                ],
                'required' => ['butir', 'teks', 'lempar_ke', 'alasan'],
            ],
        ];
    }

    private function usulkan(array $input): array
    {
        return ['content' => 'Saran tersimpan. Selesai — jangan menulis apa pun lagi.', '_selesai' => true];
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
        /*
         * Yang menandai "ini butuh manusia" adalah unread, BUKAN label.
         * Label percakapan diketik CS sendiri (Desain, Tanya Harga, Cetak);
         * agen yang menimpanya saat melempar akan menghapus kategori yang
         * baru saja dipasang orangnya — dan agen yang merusak catatan orang
         * lain adalah agen yang dimatikan.
         */
        if ($percakapan) {
            $percakapan->forceFill([
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
