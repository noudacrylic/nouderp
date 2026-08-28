<?php

namespace App\Modules\Marketplace\Jubelio\Services;

use App\Core\Inventory\Product;
use App\Modules\Marketplace\Jubelio\Models\JubelioStorePrice;
use App\Modules\Marketplace\Jubelio\Models\JubelioSyncLog;
use Illuminate\Support\Facades\Log;

/**
 * Fase 3 — pencocokan produk (SKU → item Jubelio) & push harga (ERP sumber kebenaran).
 * Promo TIDAK didorong (tetap diatur di Jubelio). Webhook price/product inbound
 * diabaikan untuk produk tersinkron.
 */
class JubelioProductSyncService
{
    public function __construct(protected JubelioClient $client) {}

    /**
     * Cocokkan produk tersinkron ke item Jubelio via SKU; cache item_id & item_group_id.
     * @return array{matched:int, unmatched:int, unmatched_skus:array<int,string>}
     */
    public function matchAll(bool $onlyMissing = true, int $limit = 1000): array
    {
        $matched = 0; $unmatched = 0; $unmatchedSkus = [];
        if (!$this->client->isReady()) {
            return ['matched' => 0, 'unmatched' => 0, 'unmatched_skus' => []];
        }

        Product::where('sync_to_jubelio', true)
            ->when($onlyMissing, fn ($q) => $q->whereNull('jubelio_item_id'))
            ->whereNotNull('sku')->where('sku', '!=', '')
            ->limit($limit)->get()
            ->each(function (Product $p) use (&$matched, &$unmatched, &$unmatchedSkus) {
                if ($this->matchProduct($p)) {
                    $matched++;
                } else {
                    $unmatched++;
                    $unmatchedSkus[] = $p->sku;
                }
            });

        return ['matched' => $matched, 'unmatched' => $unmatched, 'unmatched_skus' => $unmatchedSkus];
    }

    /** Cocokkan 1 produk; simpan item_id & item_group_id. Return true bila ketemu. */
    public function matchProduct(Product $product): bool
    {
        if (empty($product->sku)) {
            return false;
        }
        $resp = $this->client->getItemBySku($product->sku);
        if (!$resp['success']) {
            return false;
        }
        [$itemId, $groupId] = $this->extractIds($resp['data']);
        if (!$itemId) {
            return false;
        }
        $product->forceFill([
            'jubelio_item_id'       => $itemId,
            'jubelio_item_group_id' => $groupId ?: $product->jubelio_item_group_id,
        ])->save();
        return true;
    }

    /** Proses produk yang harganya berubah (ditandai observer). */
    public function pushPendingPrices(int $limit = 200): array
    {
        $stats = ['pushed' => 0, 'skipped' => 0, 'failed' => 0];
        if (!$this->client->isReady()) {
            return $stats;
        }

        Product::where('sync_to_jubelio', true)
            ->where('jubelio_price_pending', true)
            ->limit($limit)->get()
            ->each(function (Product $p) use (&$stats) {
                $r = $this->pushPrice($p);
                $stats[$r]++;
                if ($r !== 'failed') {
                    $p->forceFill(['jubelio_price_pending' => false])->save();
                }
            });

        return $stats;
    }

    /**
     * Dorong harga 1 produk ke Jubelio (harga dasar, store_id -1).
     * @return 'pushed'|'skipped'|'failed'
     */
    public function pushPrice(Product $product): string
    {
        // Pastikan item_id & item_group_id tersedia.
        if (!$product->jubelio_item_id || !$product->jubelio_item_group_id) {
            if (!$this->matchProduct($product)) {
                JubelioSyncLog::record(JubelioSyncLog::TYPE_PRICE, JubelioSyncLog::SKIP, $product->name, [
                    'reference'  => $product->sku,
                    'product_id' => $product->id,
                    'message'    => 'Produk belum ter-match ke item Jubelio (SKU tidak ditemukan).',
                ]);
                return 'skipped';
            }
            $product->refresh();
        }
        if (!$product->jubelio_item_id || !$product->jubelio_item_group_id) {
            return 'skipped';
        }

        $price = (float) $product->display_price;
        if ($price <= 0) {
            JubelioSyncLog::record(JubelioSyncLog::TYPE_PRICE, JubelioSyncLog::SKIP, $product->name, [
                'reference'  => $product->sku,
                'product_id' => $product->id,
                'message'    => 'Harga utama produk belum diatur (0).',
            ]);
            return 'skipped';
        }

        $resp = $this->client->updatePrices([[
            'item_group_id' => (int) $product->jubelio_item_group_id,
            'item_id'       => (int) $product->jubelio_item_id,
            'price'         => $price,
        ]]);

        if (!$resp['success']) {
            Log::warning('Jubelio harga: push gagal', ['product' => $product->id, 'error' => $resp['error']]);
            JubelioSyncLog::record(JubelioSyncLog::TYPE_PRICE, JubelioSyncLog::FAIL, $product->name, [
                'reference'  => $product->sku,
                'product_id' => $product->id,
                'message'    => $resp['error'] ?: 'Gagal mengirim harga ke Jubelio.',
                'meta'       => ['price' => $price],
            ]);
            return 'failed';
        }
        JubelioSyncLog::record(JubelioSyncLog::TYPE_PRICE, JubelioSyncLog::OK, $product->name, [
            'reference'  => $product->sku,
            'product_id' => $product->id,
            'message'    => 'Harga utama dikirim: ' . number_format($price, 0, ',', '.'),
            'meta'       => ['price' => $price],
        ]);
        return 'pushed';
    }

    /**
     * Dorong harga KHUSUS TOKO (bukan harga dasar) — dipakai Analisa ▸ Harga Produk supaya
     * tiap marketplace boleh berharga beda sesuai potongannya masing-masing.
     *
     * Harga per toko menimpa harga dasar di Jubelio; harga dasar sendiri tidak disentuh,
     * jadi toko yang tidak dikirimi harga khusus tetap ikut harga web. Satu kanal bisa
     * punya lebih dari satu toko (TikTok & Tokopedia) — semuanya dikirimi harga yang sama
     * dalam satu panggilan, supaya tidak ada toko yang tertinggal separuh jalan.
     *
     * @param array<int,int> $storeIds store_id Jubelio tujuan
     * @return array{ok:bool, message:string}
     */
    public function pushStorePrice(Product $product, array $storeIds, float $price): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));

        if (empty($storeIds)) {
            return ['ok' => false, 'message' => 'Kanal ini belum punya toko Jubelio yang dipetakan.'];
        }
        if ($price <= 0) {
            return ['ok' => false, 'message' => 'Harga belum diisi.'];
        }

        if (!$product->jubelio_item_id || !$product->jubelio_item_group_id) {
            if (!$this->matchProduct($product)) {
                $message = 'Produk belum ter-match ke item Jubelio (SKU tidak ditemukan).';
                JubelioSyncLog::record(JubelioSyncLog::TYPE_PRICE, JubelioSyncLog::SKIP, $product->name, [
                    'reference'  => $product->sku,
                    'product_id' => $product->id,
                    'message'    => $message,
                ]);

                return ['ok' => false, 'message' => $message];
            }
            $product->refresh();
        }

        $resp = $this->client->updatePrices(array_map(fn ($storeId) => [
            'item_group_id' => (int) $product->jubelio_item_group_id,
            'item_id'       => (int) $product->jubelio_item_id,
            'price'         => $price,
            'store_id'      => (string) $storeId,
        ], $storeIds));

        $stores = implode(', ', $storeIds);

        if (!$resp['success']) {
            Log::warning('Jubelio harga toko: push gagal', ['product' => $product->id, 'stores' => $storeIds, 'error' => $resp['error']]);
            JubelioSyncLog::record(JubelioSyncLog::TYPE_PRICE, JubelioSyncLog::FAIL, $product->name, [
                'reference'  => $product->sku,
                'product_id' => $product->id,
                'message'    => $resp['error'] ?: 'Gagal mengirim harga toko ke Jubelio.',
                'meta'       => ['price' => $price, 'store_ids' => $storeIds],
            ]);

            return ['ok' => false, 'message' => $resp['error'] ?: 'Gagal mengirim harga ke Jubelio.'];
        }

        JubelioSyncLog::record(JubelioSyncLog::TYPE_PRICE, JubelioSyncLog::OK, $product->name, [
            'reference'  => $product->sku,
            'product_id' => $product->id,
            'message'    => 'Harga toko (' . $stores . ') dikirim: ' . number_format($price, 0, ',', '.'),
            'meta'       => ['price' => $price, 'store_ids' => $storeIds],
        ]);

        return ['ok' => true, 'message' => 'Harga ' . number_format($price, 0, ',', '.') . ' terkirim ke toko ' . $stores . '.'];
    }

    /**
     * Tarik harga yang SEDANG dipegang Jubelio untuk sederet toko, simpan sebagai rekaman.
     *
     * Satu panggilan API per produk — karena itu ini pekerjaan tombol/perintah, bukan
     * sesuatu yang boleh terjadi saat halaman dibuka. Halaman Harga memuat ratusan produk;
     * menariknya sambil merender berarti ratusan panggilan ke Jubelio setiap kali seseorang
     * menekan F5.
     *
     * Produk yang belum ter-match ke item Jubelio TIDAK dipaksa dicocokkan di sini: itu
     * pekerjaan matchAll(), dan mencampurnya membuat satu SKU salah ketik menghabiskan
     * kuota panggilan untuk seluruh katalog. Ia dicatat sebagai baris tanpa harga beserta
     * alasannya, supaya kolomnya kosong DENGAN keterangan.
     *
     * Dibatasi $limit per panggilan, dan yang PALING LAMA tidak diperbarui dikerjakan lebih
     * dulu. Tanpa batas, satu klik pada katalog ratusan produk berarti ratusan panggilan
     * berurutan dalam satu permintaan web — yang berakhir sebagai timeout, dengan sebagian
     * harga sudah tersimpan dan sebagian belum, tanpa ada yang tahu sampai mana. Dengan
     * urutan tertua-dulu, menekan tombolnya berkali-kali selalu maju, tidak pernah berputar
     * di produk yang itu-itu saja. Untuk menyapu seluruh katalog sekaligus, pakai perintah
     * `jubelio:tarik-harga` yang tidak terikat batas waktu permintaan web.
     *
     * @param  array<int,int> $storeIds
     * @return array{terisi:int, kosong:int, gagal:int, sisa:int}
     */
    public function pullStorePrices(array $storeIds, ?iterable $products = null, int $limit = 60): array
    {
        $stats    = ['terisi' => 0, 'kosong' => 0, 'gagal' => 0, 'sisa' => 0];
        $storeIds = array_values(array_filter(array_map('intval', $storeIds)));

        if (empty($storeIds) || !$this->client->isReady()) {
            return $stats;
        }

        if ($products === null) {
            $semua = Product::where('sync_to_jubelio', true)
                ->whereNotNull('sku')->where('sku', '!=', '')->get();

            // Rekaman paling basi (dan yang belum pernah ada) naik ke depan antrean.
            $tertua = JubelioStorePrice::whereIn('store_id', $storeIds)
                ->selectRaw('product_id, MIN(fetched_at) as tertua')
                ->groupBy('product_id')->pluck('tertua', 'product_id');

            $urut     = $semua->sortBy(fn ($p) => $tertua[$p->id] ?? '')->values();
            $products = $urut->take($limit);
            $stats['sisa'] = max(0, $urut->count() - $products->count());
        }

        foreach ($products as $product) {
            $stats[$this->tarikHargaSatuProduk($product, $storeIds)['status']]++;
        }

        JubelioSyncLog::record(JubelioSyncLog::TYPE_PRICE, JubelioSyncLog::OK, 'Tarik harga marketplace', [
            'message' => sprintf('Toko %s — %d terisi, %d kosong, %d gagal, %d belum giliran.',
                implode(', ', $storeIds), $stats['terisi'], $stats['kosong'], $stats['gagal'], $stats['sisa']),
            'meta'    => $stats + ['store_ids' => $storeIds],
        ]);

        return $stats;
    }

    /**
     * Tanya Jubelio harga satu produk lalu rekam apa adanya — inti dari pullStorePrices,
     * berdiri sendiri supaya verifikasi sesudah kirim tidak perlu menyeret antrean,
     * batas, dan catatan ringkasan yang hanya masuk akal untuk penarikan borongan.
     *
     * @param  array<int,int> $storeIds
     * @return array{status:'terisi'|'kosong'|'gagal', per_toko:array<int,?float>, note:?string}
     */
    private function tarikHargaSatuProduk(Product $product, array $storeIds): array
    {
        if (!$product->jubelio_item_id) {
            $note = 'Produk belum ter-match ke item Jubelio.';
            $this->rekamHarga($product, $storeIds, [], $note);

            return ['status' => 'kosong', 'per_toko' => [], 'note' => $note];
        }

        $hasil = $this->client->getStorePrices(
            (int) $product->jubelio_item_id,
            (int) ($product->jubelio_item_group_id ?: 0)
        );

        if (!$hasil['ok']) {
            $this->rekamHarga($product, $storeIds, [], $hasil['reason']);

            return ['status' => 'gagal', 'per_toko' => [], 'note' => $hasil['reason']];
        }

        // Toko yang tidak punya harga khusus memang dijual di harga dasar — itu jawaban
        // yang sah, bukan kegagalan, dan harus terbaca begitu di layar.
        $perToko = [];
        foreach ($storeIds as $storeId) {
            $perToko[$storeId] = $hasil['prices'][$storeId] ?? $hasil['base'];
        }

        $adaHarga = (bool) array_filter($perToko, fn ($v) => $v !== null);
        $note     = $adaHarga ? null : 'Jubelio tidak memberi harga untuk toko ini.';
        $this->rekamHarga($product, $storeIds, $perToko, $note);

        return ['status' => $adaHarga ? 'terisi' : 'kosong', 'per_toko' => $perToko, 'note' => $note];
    }

    /**
     * Tanya balik sesudah mengirim: harga yang SEKARANG dipegang Jubelio berapa?
     *
     * `pushStorePrice` yang menjawab "ok" cuma berarti API-nya menerima permintaan kita.
     * Satu panggilan tambahan di detik yang sama menjadikannya "harganya memang sudah
     * berganti" — dan hasilnya sekalian mengisi kolom "Di marketplace", jadi kolom itu
     * hidup tanpa perlu menyapu seluruh katalog.
     *
     * Yang dilaporkan sengaja bukan cuma cocok/tidak: Jubelio kadang belum sempat
     * memproses, dan itu beda maknanya dengan harga yang diubah orang lain dari luar ERP.
     * Membedakannya bukan tugas fungsi ini — tugasnya menyajikan angkanya apa adanya.
     *
     * @param  array<int,int> $storeIds
     * @return array{ok:bool, sesuai:bool, per_toko:array<int,?float>, harga:?float, message:?string}
     */
    public function verifyStorePrice(Product $product, array $storeIds, float $expected): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        $kosong   = ['ok' => false, 'sesuai' => false, 'per_toko' => [], 'harga' => null];

        if (empty($storeIds) || !$this->client->isReady()) {
            return $kosong + ['message' => 'Jubelio belum tersambung.'];
        }

        $hasil = $this->tarikHargaSatuProduk($product, $storeIds);

        if ($hasil['status'] !== 'terisi') {
            return $kosong + ['message' => $hasil['note'] ?: 'Jubelio tidak memberi harga untuk toko ini.'];
        }

        $terbaca = array_filter($hasil['per_toko'], fn ($v) => $v !== null);
        $beda    = array_filter($terbaca, fn ($v) => abs((float) $v - $expected) >= 1);
        $seragam = collect($terbaca)->map(fn ($v) => round((float) $v))->unique();

        return [
            'ok'       => true,
            // Toko yang tidak menjawab TIDAK ikut dihitung "cocok": separuh terverifikasi
            // dibaca sebagai terverifikasi persis di saat yang paling perlu diperiksa.
            'sesuai'   => empty($beda) && count($terbaca) === count($storeIds),
            'per_toko' => $hasil['per_toko'],
            // Satu angka hanya bila semua toko sepakat — sama seperti di tabel harga.
            'harga'    => $seragam->count() === 1 ? (float) reset($terbaca) : null,
            'message'  => null,
        ];
    }

    /** @param array<int,?float> $perToko */
    private function rekamHarga(Product $product, array $storeIds, array $perToko, ?string $note): void
    {
        foreach ($storeIds as $storeId) {
            JubelioStorePrice::updateOrCreate(
                ['product_id' => $product->id, 'store_id' => $storeId],
                [
                    'price'      => $perToko[$storeId] ?? null,
                    'note'       => $note,
                    'fetched_at' => now(),
                ]
            );
        }
    }

    /** Ekstrak [item_id, item_group_id] dari respons item Jubelio (beberapa bentuk). */
    private function extractIds($data): array
    {
        if (!is_array($data)) {
            return [null, null];
        }
        $node = $data;
        if (isset($data['items'][0]) && is_array($data['items'][0])) {
            $node = $data['items'][0];
        } elseif (isset($data['data'][0]) && is_array($data['data'][0])) {
            $node = $data['data'][0];
        }
        $itemId  = $node['item_id'] ?? $data['item_id'] ?? null;
        $groupId = $node['item_group_id'] ?? $data['item_group_id'] ?? null;
        return [$itemId ? (int) $itemId : null, $groupId ? (int) $groupId : null];
    }
}
