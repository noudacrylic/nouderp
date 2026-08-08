<?php

namespace App\Modules\Sales\Services;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\StockReservation;
use App\Modules\Sales\Models\SalesOrder;

/**
 * Apakah stok masih cukup untuk memenuhi sebuah Sales Order?
 *
 * Tautan pembayaran boleh disebar saat SO masih draft, dan draft BELUM menahan stok.
 * Dua pembeli bisa memegang tautan atas barang yang sama: sisa 2, satu memesan 2 dan
 * satu memesan 1 — siapa pun yang membayar belakangan membuat stok minus. Pengecekan
 * ini dipakai tepat sebelum pembayaran diterima, bukan saat SO dibuat.
 *
 * Rumus ketersediaannya sengaja disamakan dengan angka "Stok" yang dilihat admin di
 * form SO (ProductController::stock): on-hand gudang SO − reservasi aktif SO LAIN +
 * preorder_stock. Reservasi milik SO ini sendiri tidak dikurangkan — kalau SO sudah
 * dikonfirmasi, barangnya memang sudah dipesan untuk dia.
 */
class SalesOrderStockCheck
{
    /** Jenis produk yang memang tidak punya stok fisik untuk dicek. */
    private const TANPA_STOK = ['service', 'non_stock', 'preorder'];

    /**
     * Kekurangan stok per produk. Kosong berarti aman.
     *
     * @return array<int, array{sku: string, name: string, needed: float, available: float, short: float}>
     */
    public function shortages(SalesOrder $so): array
    {
        $needs = $this->kebutuhanPerProduk($so);
        if (empty($needs)) {
            return [];
        }

        $produk = Product::whereIn('id', array_keys($needs))->get()->keyBy('id');
        $hasil = [];

        foreach ($needs as $productId => $needed) {
            $p = $produk->get($productId);
            if (! $p || in_array($p->sale_type, self::TANPA_STOK, true)) {
                continue;
            }

            $available = $this->tersediaUntuk($productId, $so);
            if ($needed > $available) {
                $hasil[] = [
                    'sku' => $p->sku,
                    'name' => $p->name,
                    'needed' => (float) $needed,
                    'available' => (float) max(0, $available),
                    'short' => (float) ($needed - max(0, $available)),
                ];
            }
        }

        return $hasil;
    }

    /**
     * Kekurangan barang untuk BANYAK SO sekaligus, dipakai gerbang PEMROSESAN (bukan pembayaran).
     *
     * Sengaja beda aturan dari shortages(), dan bedanya penting — dulu keduanya dianggap sama
     * dan itulah asal bug preorder yang berlarut-larut. Dua pertanyaannya memang berbeda:
     *
     *   shortages()             → "boleh DIBAYAR?"   Preorder selalu boleh: itu inti jualan
     *                             preorder. Kuota `preorder_stock` yang membatasi.
     *   shortagesForFulfillment → "boleh DIPACKING?" Hanya kalau barangnya benar-benar ada.
     *                             Kuota TIDAK dihitung — itu izin jual, bukan barang.
     *
     * Produk preorder karena itu ikut dicek di sini, TAPI hanya yang unitnya bisa saling
     * menggantikan (`made_to_order` dilepas). Yang dibuat mengikuti permintaan pembeli tetap
     * dikecualikan: angka stok SKU-nya tidak berarti apa-apa karena unit di bawahnya bisa
     * berbeda barang — kesiapannya dinilai dari order produksinya sendiri.
     *
     * Stok dialokasikan URUT PESANAN TERTUA: yang dikurangkan hanya reservasi milik SO yang
     * lebih dulu (id lebih kecil). Sebelumnya semua reservasi SO lain dikurangkan tanpa
     * memandang umur, sehingga saat satu unit diperebutkan dua pesanan, KEDUANYA melihat
     * "tersedia 0" dan sama-sama menunggu — padahal yang tertua sudah bisa jalan.
     *
     * SO ber-`allow_backorder` selalu kosong: sudah disepakati boleh jalan walau stok kurang.
     *
     * @param  iterable<SalesOrder> $orders SO dengan relasi items.product sudah di-load
     * @return array<int, array<int, array{sku:string,name:string,needed:float,available:float,short:float}>>
     *         keyed sales_order_id; SO tanpa kekurangan tidak muncul sama sekali.
     */
    public function shortagesForFulfillment(iterable $orders): array
    {
        $needsPerSo = [];
        foreach ($orders as $so) {
            if ($so->allow_backorder) {
                continue;
            }
            $needs = $this->kebutuhanPerProduk($so);
            if ($needs) {
                $needsPerSo[$so->id] = ['so' => $so, 'needs' => $needs];
            }
        }
        if (empty($needsPerSo)) {
            return [];
        }

        $productIds = collect($needsPerSo)->flatMap(fn ($e) => array_keys($e['needs']))->unique()->values()->all();
        $produk = Product::whereIn('id', $productIds)
            ->get(['id', 'sku', 'name', 'sale_type', 'preorder_stock', 'made_to_order'])->keyBy('id');

        // On-hand per (produk, gudang) & reservasi aktif per (produk, gudang, SO pemilik).
        $onHand = ProductStock::whereIn('product_id', $productIds)
            ->get(['product_id', 'warehouse_id', 'qty_on_hand'])
            ->groupBy('product_id');

        $reservasi = StockReservation::whereIn('product_id', $productIds)
            ->where('status', 'active')
            ->get(['product_id', 'warehouse_id', 'qty', 'sales_order_id'])
            ->groupBy('product_id');

        // Gudang jualan — dipakai saat SO tidak menyebut gudang, meniru cabang else di tersediaUntuk().
        $gudangJual = \App\Core\Inventory\Warehouse::where('is_sellable', true)->pluck('id')->all();

        $hasil = [];
        foreach ($needsPerSo as $soId => $entry) {
            $so = $entry['so'];
            $kurang = [];

            foreach ($entry['needs'] as $productId => $needed) {
                $p = $produk->get($productId);
                if (! $p || ! $this->dicekSaatPacking($p)) {
                    continue;
                }

                $stokRows = $onHand->get($productId, collect());
                $resRows  = $reservasi->get($productId, collect());

                if ($so->warehouse_id) {
                    $stokRows = $stokRows->where('warehouse_id', $so->warehouse_id);
                    $resRows  = $resRows->where('warehouse_id', $so->warehouse_id);
                } else {
                    $stokRows = $stokRows->whereIn('warehouse_id', $gudangJual);
                }

                // Alokasi tertua duluan: hanya reservasi milik pesanan yang LEBIH DULU yang
                // mengurangi jatah. Reservasi tanpa pemilik dikurangkan (umurnya tak diketahui,
                // pilih yang aman). Urutan pakai id — auto-increment, jadi selalu searah waktu
                // dan tidak butuh query tanggal tambahan.
                $resRows = $resRows->filter(fn ($r) => $r->sales_order_id === null
                    || (int) $r->sales_order_id < (int) $so->id);

                // Kuota preorder TIDAK ikut: itu izin menjual melampaui stok, bukan barang yang
                // bisa dimasukkan kardus.
                $available = (float) $stokRows->sum('qty_on_hand') - (float) $resRows->sum('qty');

                if ($needed > $available) {
                    $kurang[] = [
                        'sku' => $p->sku,
                        'name' => $p->name,
                        'needed' => (float) $needed,
                        'available' => (float) max(0, $available),
                        'short' => (float) ($needed - max(0, $available)),
                    ];
                }
            }

            if ($kurang) {
                $hasil[$soId] = $kurang;
            }
        }

        return $hasil;
    }

    /**
     * Produk ini stoknya berarti saat memutuskan boleh-tidaknya dipacking?
     *
     * Jasa & non-stok tidak punya barang. Produk preorder yang DIBUAT KHUSUS per pesanan juga
     * tidak: SKU-nya cuma wadah, "CS1 qty 3" bukan berarti tiga barang yang sama, jadi
     * angkanya tidak boleh dipakai melepas pesanan siapa pun. Kesiapannya dinilai dari order
     * produksi miliknya sendiri.
     */
    private function dicekSaatPacking(Product $p): bool
    {
        if ($p->sale_type === 'preorder') {
            return $p->sharesStockAcrossOrders();
        }

        return ! in_array($p->sale_type, self::TANPA_STOK, true);
    }

    /** SO ini boleh dibayar dari sisi stok? Kesepakatan keep stock membebaskan cek. */
    public function boleh(SalesOrder $so): bool
    {
        if ($so->allow_backorder) {
            return true;
        }

        return empty($this->shortages($so));
    }

    /**
     * Kebutuhan stok per produk dalam satuan dasar. Bundle dipecah ke komponennya —
     * cerminan persis dari yang direservasi SalesOrderService::confirm().
     *
     * @return array<int, float>
     */
    private function kebutuhanPerProduk(SalesOrder $so): array
    {
        $needs = [];

        // Pakai relasi yang sudah di-load bila ada — jalur batch memuatnya sekali di depan,
        // dan query ulang di sini akan mengembalikan N+1 yang justru mau dihindari.
        $items = $so->relationLoaded('items') ? $so->items : $so->items()->with('product')->get();

        foreach ($items as $item) {
            $product = $item->product;
            if (! $product) {
                continue;
            }

            $baseQty = (float) $item->qty * (float) ($item->conversion_to_base ?? 1);
            $isBundle = ($product->sale_type === 'bundle') || ($product->type === 'bundle');

            if (! $isBundle) {
                $needs[$product->id] = ($needs[$product->id] ?? 0) + $baseQty;
                continue;
            }

            $components = $product->bundleItems;
            if ($components->isEmpty()) {
                $components = $product->bundleComponents;
            }

            foreach ($components as $component) {
                $qty = $component->qty_required ?? $component->qty ?? 1;
                $pid = $component->component_product_id;
                $needs[$pid] = ($needs[$pid] ?? 0) + ($baseQty * (float) $qty);
            }
        }

        return $needs;
    }

    /** Stok yang benar-benar bisa dipakai SO ini. */
    private function tersediaUntuk(int $productId, SalesOrder $so): float
    {
        $stok = ProductStock::where('product_id', $productId);
        $reservasi = StockReservation::where('product_id', $productId)->where('status', 'active');

        if ($so->warehouse_id) {
            $stok->where('warehouse_id', $so->warehouse_id);
            $reservasi->where('warehouse_id', $so->warehouse_id);
        } else {
            $stok->whereHas('warehouse', fn ($q) => $q->where('is_sellable', true));
        }

        // Reservasi milik SO ini sendiri bukan saingan — itu jatah dia.
        $reservasi->where(fn ($q) => $q->whereNull('sales_order_id')->orWhere('sales_order_id', '!=', $so->id));

        $onHand = (float) $stok->sum('qty_on_hand');
        $dipesanOrangLain = (float) $reservasi->sum('qty');
        $preorder = (float) (Product::where('id', $productId)->value('preorder_stock') ?? 0);

        return $onHand - $dipesanOrangLain + $preorder;
    }
}
