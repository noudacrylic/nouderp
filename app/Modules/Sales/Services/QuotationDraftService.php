<?php

namespace App\Modules\Sales\Services;

use App\Models\SalesQuotation;
use App\Models\SalesQuotationItem;
use App\Services\NumberGeneratorService;
use Illuminate\Support\Facades\DB;

/**
 * Membuat penawaran DRAFT dari keranjang, memakai bentuk data yang sama persis
 * dengan SalesOrderService::createDraftFromData.
 *
 * Ada supaya satu keranjang di layar chat bisa berakhir jadi penawaran ATAU
 * pesanan tanpa disusun dua kali. Yang dipanggil dari chat cuma ini; halaman
 * Penawaran di modul Sales tetap jalur tersendiri dan tidak disentuh.
 *
 * ────────────────────── SATU JEBAKAN YANG DITUTUP DI SINI ──────────────────────
 * Diskon NOMINAL punya dua arti berbeda di dua tempat, dan keduanya sama-sama
 * hidup:
 *   • SalesOrderService memperlakukannya PER UNIT  (line_discount = nilai × qty)
 *   • QuotationController memperlakukannya PER BARIS (rowTotal −= nilai)
 *
 * Keranjang chat berbicara dalam bahasa SO. Menyalinnya apa adanya ke penawaran
 * membuat angkanya benar saat disimpan tapi BERUBAH begitu penawarannya dibuka
 * lagi di halaman Penawaran — kerusakan yang tak terlihat sampai ada pelanggan
 * menerima harga yang lain. Karena itu nominal dikonversi ke bahasa penawaran
 * sebelum ditulis; persen tidak perlu, artinya sama di kedua tempat.
 */
class QuotationDraftService
{
    /**
     * @param  array  $dto  sama dengan SalesOrderService::createDraftFromData:
     *   customer_id, warehouse_id, delivery_method, notes, shipping_address,
     *   global_discount_type/value, shipping_gross, shipping_discount_type/value,
     *   courier_name, shipping_courier_code, shipping_service_code,
     *   items[] { product_id, description, qty, unit_price, discount_type, discount_value }
     */
    public function createDraftFromData(array $dto): SalesQuotation
    {
        return DB::transaction(function () use ($dto) {
            // Dibaca SEKALI ke variabel: pada ternary, cabang benar tetap
            // menyentuh $dto['delivery_method'] walau kuncinya tak ada —
            // keranjang tanpa metode kirim adalah keadaan yang sah.
            $metode = (string) ($dto['delivery_method'] ?? 'kurir');
            $metode = in_array($metode, ['kurir', 'instant', 'ambil_toko'], true) ? $metode : 'kurir';

            $quotation = SalesQuotation::create([
                'quotation_number' => NumberGeneratorService::generate('SQ'),
                'customer_id'      => (int) $dto['customer_id'],
                'warehouse_id'     => $dto['warehouse_id'] ?? null,
                'delivery_method'  => $metode,
                'quotation_date'   => now()->toDateString(),
                'notes'            => $dto['notes'] ?? null,
                // Alamat ikut supaya penawaran yang dicetak menyebut tujuan yang
                // sama dengan yang dibahas di chat.
                'shipping_address' => $metode === 'ambil_toko' ? null : ($dto['shipping_address'] ?? null),
                'status'           => 'draft',
                'created_by'       => auth()->id(),
                // Perihal, kalimat pembuka, syarat pembayaran & masa berlaku
                // sengaja dibiarkan kosong: semuanya dirapikan di halaman
                // Penawaran, tempat pratinjau cetaknya ada. Memindahkannya ke
                // kolom chat selebar 380px membuat komposer jadi formulir panjang.
            ] + $this->total($dto, $metode));

            $this->simpanItem($quotation, (array) ($dto['items'] ?? []));

            return $quotation->refresh();
        });
    }

    /** Tulis item, sekaligus menerjemahkan arti diskon nominal. */
    private function simpanItem(SalesQuotation $quotation, array $items): void
    {
        foreach ($items as $item) {
            if (empty($item['product_id'])) {
                continue;
            }

            $qty   = (float) ($item['qty'] ?? 0);
            $harga = clean_number($item['unit_price'] ?? 0);

            if ($qty <= 0) {
                continue;
            }

            $jenis = ($item['discount_type'] ?? 'nominal') === 'percent' ? 'percent' : 'nominal';
            $nilai = clean_number($item['discount_value'] ?? 0);

            $bruto    = round($qty * $harga);
            $potongan = $jenis === 'percent'
                ? round($bruto * ($nilai / 100))
                : round($nilai * $qty);   // masuk sebagai PER UNIT

            SalesQuotationItem::create([
                'quotation_id'   => $quotation->id,
                'product_id'     => (int) $item['product_id'],
                'description'    => trim((string) ($item['description'] ?? '')) ?: null,
                'qty'            => $qty,
                'unit_price'     => $harga,
                'discount_type'  => $jenis,
                // Ditulis PER BARIS — lihat catatan kelas. Tanpa perkalian ini,
                // membuka penawarannya di halaman Sales akan menghitung ulang
                // diskonnya jadi sepersekian dan harganya berubah sendiri.
                'discount_value' => $jenis === 'percent' ? $nilai : round($nilai * $qty),
                'discount_amount' => $potongan,
                'subtotal'       => $bruto - $potongan,
            ]);
        }
    }

    /**
     * Total dihitung ULANG dari item, bukan diterima dari layar.
     *
     * Halaman Penawaran menerima subtotal & grand total dari formulirnya sendiri;
     * jalur ini sengaja tidak menirunya. Angka yang dikirim layar dan angka yang
     * dihitung server adalah dua sumber kebenaran, dan yang satu pasti menyimpang
     * begitu ada aturan diskon yang berubah.
     *
     * Rumusnya menyalin SalesOrderService supaya penawaran yang nanti dikonversi
     * jadi SO tidak berubah totalnya.
     */
    private function total(array $dto, string $metode): array
    {
        $bruto    = 0;
        $potongan = 0;

        foreach ((array) ($dto['items'] ?? []) as $item) {
            if (empty($item['product_id'])) {
                continue;
            }

            $qty   = (float) ($item['qty'] ?? 0);
            $harga = clean_number($item['unit_price'] ?? 0);

            if ($qty <= 0) {
                continue;
            }

            $barisBruto = round($qty * $harga);
            $jenis      = ($item['discount_type'] ?? 'nominal') === 'percent' ? 'percent' : 'nominal';
            $nilai      = clean_number($item['discount_value'] ?? 0);

            $bruto    += $barisBruto;
            $potongan += $jenis === 'percent'
                ? round($barisBruto * ($nilai / 100))
                : round($nilai * $qty);
        }

        $dpp = $bruto - $potongan;

        $jenisGlobal = ($dto['global_discount_type'] ?? 'nominal') === 'percent' ? 'percent' : 'nominal';
        $nilaiGlobal = clean_number($dto['global_discount_value'] ?? 0);
        $potGlobal   = $jenisGlobal === 'percent' ? round($dpp * ($nilaiGlobal / 100)) : round($nilaiGlobal);

        $dpp -= $potGlobal;

        $ongkirBruto = $metode === 'ambil_toko' ? 0 : clean_number($dto['shipping_gross'] ?? 0);
        $jenisOngkir = ($dto['shipping_discount_type'] ?? 'nominal') === 'percent' ? 'percent' : 'nominal';
        $nilaiOngkir = $metode === 'ambil_toko' ? 0 : clean_number($dto['shipping_discount_value'] ?? 0);
        $potOngkir   = $jenisOngkir === 'percent' ? round($ongkirBruto * ($nilaiOngkir / 100)) : round($nilaiOngkir);
        $ongkirNet   = max(0, $ongkirBruto - $potOngkir);

        return [
            'subtotal'                => $bruto,
            'subtotal_product'        => $bruto,
            'global_discount_type'    => $jenisGlobal,
            'global_discount_value'   => $nilaiGlobal,
            // Kolom kembar peninggalan formulir lama; keduanya dibaca di
            // tempat berbeda, jadi keduanya diisi nilai yang sama.
            'discount_global'         => $potGlobal,
            'shipping_gross'          => $ongkirBruto,
            'shipping_discount_type'  => $jenisOngkir,
            'shipping_discount_value' => $nilaiOngkir,
            'shipping_charge'         => $ongkirNet,
            'shipping_courier_code'   => $dto['shipping_courier_code'] ?? null,
            'shipping_service_code'   => $dto['shipping_service_code'] ?? null,
            'shipping_service_name'   => $dto['courier_name'] ?? null,
            'grand_total'             => round($dpp + $ongkirNet),
        ];
    }
}
