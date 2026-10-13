<?php

namespace App\Modules\Analysis\Services;

use App\Models\MarketplaceConfig;
use App\Modules\Analysis\Models\PriceChannelFeeComponent;
use App\Modules\Analysis\Models\ProductChannelPrice;
use App\Modules\Analysis\Support\PricingMath;
use App\Modules\Marketplace\Jubelio\Models\JubelioChannelMap;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Harga jual per kanal — menjawab "kalau dijual di Shopee sekian, untungnya sekian".
 *
 * HPP-nya tidak dihitung ulang di sini: diambil apa adanya dari halaman HPP (Ready untuk
 * produk yang diukur dari OP, Bundle untuk paket rakitan). Yang ditambahkan halaman ini
 * cuma satu hal — potongan kanal — tapi justru itu yang selama ini bikin harga marketplace
 * ditebak-tebak.
 *
 * ── KENAPA HARGA WEBSITE TIDAK DISIMPAN DI SINI ───────────────────────────
 *
 * Harga website adalah harga jual asli produk: satu angka yang dipakai storefront, kasir
 * ERP, dan jadi harga dasar di Jubelio sekaligus. Kalau halaman analisa menyimpan salinannya
 * sendiri, cepat atau lambat dua angka itu berbeda dan tidak ada yang tahu mana yang benar.
 * Jadi kanal `website` membaca dan menulis langsung ke master harga produk.
 *
 * Kanal marketplace sebaliknya: harganya milik halaman ini sampai tombol kirim ditekan.
 * Produk yang belum pernah dikirim ditandai "ikut harga dasar" — karena memang itu yang
 * terjadi di tokonya, bukan karena harganya belum diisi.
 */
class ChannelPricingService
{
    public const DEFAULT_AFFILIATE_PERCENT = 5.0;

    public function __construct(
        protected ProductHppService $hpp,
        protected BundleHppService $bundles,
    ) {
    }

    /** Semua kanal beserta potongan & tokonya. @return Collection<string,array> */
    public function channels(): Collection
    {
        $components = PriceChannelFeeComponent::byChannel();
        $configs    = $this->marketplaceConfigs();
        $stores     = $this->storeIdsByCustomer();

        return collect(config('price_channels', []))->map(function ($channel, $key) use ($components, $configs, $stores) {
            $rows      = $components->get($key, collect());
            $customers = $this->resolveCustomers($channel, $configs);
            $kind      = $channel['kind'] ?? 'marketplace';
            $fee       = $this->effectiveFee($rows, $customers, $kind);

            return [
                'key'          => $key,
                'label'        => $channel['label'] ?? $key,
                'kind'         => $kind,
                'affiliate'    => (bool) ($channel['affiliate'] ?? false),
                'note'         => $channel['note'] ?? null,
                'components'   => $rows,
                'fee'          => $fee,
                'fee_fallback' => $rows->isEmpty() && ($fee['percent'] > 0 || $fee['fixed'] > 0),
                'accounting'   => $this->accountingComparison($rows, $customers, $fee),
                'customers'    => $customers->values()->all(),
                'store_ids'    => $customers->flatMap(fn ($c) => $stores->get($c['customer_id'], []))->unique()->values()->all(),
            ];
        });
    }

    public function channel(string $key): ?array
    {
        return $this->channels()->get($key);
    }

    /** Kanal yang muncul di sebuah sub-tab: afiliasi hanya ada di kanal yang menyediakannya. */
    public function channelsFor(string $tab): Collection
    {
        return $this->channels()->filter(fn ($c) => $tab !== 'afiliasi' || $c['affiliate']);
    }

    /**
     * Satu baris per produk yang dijual, lengkap dengan skenario satuan, grosir, dan afiliasi.
     *
     * @param array      $filters      filter HPP (diteruskan apa adanya ke perhitungan HPP)
     * @param float|null $targetMarkup kalau diisi, tiap baris dapat usulan harga
     * @param array|null $fee          potongan andaian ['percent','fixed'] — untuk menjawab
     *                                 "kalau potongan Shopee naik, harga saya masih untung?"
     */
    /**
     * Sengaja TIDAK ikut disimpan sendiri, walau halamannya dulu yang paling lambat
     * (2,4 detik / 2.872 query). Yang mahal di situ adalah HPP-nya, dan HPP sudah
     * disimpan — begitu HPP hangat, penyusunan baris kanal tinggal ±20 ms.
     *
     * Menyimpannya lagi di sini justru merugikan: potongan kanal dan markup di halaman
     * ini boleh diketik untuk pengandaian, jadi kuncinya akan beranak mengikuti setiap
     * angka yang dicoba orang — puluhan salinan tabel yang sama demi menghemat 20 ms.
     */
    public function rows(string $channelKey, array $filters = [], ?float $targetMarkup = null, ?array $fee = null): Collection
    {
        $channel = $this->channel($channelKey);
        if (!$channel) {
            return collect();
        }
        if ($fee) {
            $channel['fee'] = $fee;
        }

        $products = DB::table('products')
            ->where('is_sellable', true)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'sale_type']);

        if ($products->isEmpty()) {
            return collect();
        }

        $hppMap  = $this->hppMap($filters);
        $saved   = ProductChannelPrice::where('channel', $channelKey)->get()->keyBy('product_id');
        $master  = $this->masterPrices();
        $percentDasar = (float) $channel['fee']['percent'];
        $fixed        = (float) $channel['fee']['fixed'];
        // Persen tambahan khusus produk preorder (andaian "Biaya Pre-Order", mis. Shopee 3%).
        $preorder     = (float) ($channel['fee']['preorder'] ?? 0);
        $isWeb        = $channel['kind'] === 'internal';

        return $products->map(function ($p) use ($saved, $master, $hppMap, $percentDasar, $preorder, $fixed, $isWeb, $channel, $targetMarkup) {
            $pid     = (int) $p->id;
            $percent = $percentDasar + ($p->sale_type === 'preorder' ? $preorder : 0.0);
            $row  = $saved->get($pid);
            $hpp  = $hppMap->get($pid);
            $base = $master->get($pid);

            // Kanal marketplace tanpa harga sendiri memang dijual di harga dasar — bukan
            // "belum diisi", tapi "ikut harga web". Dua hal yang berbeda.
            $price  = $isWeb ? $base : ($row?->price ?? $base);
            $source = $isWeb ? 'master' : ($row?->price !== null ? 'kanal' : 'dasar');

            $qty       = $row?->wholesale_min_qty ?: null;
            $affiliate = $row?->affiliate_percent ?? ($channel['affiliate'] ? self::DEFAULT_AFFILIATE_PERCENT : 0.0);

            $satuan = PricingMath::scenario($hpp, $price, $percent, $fixed);
            $grosir = PricingMath::scenario($hpp, $row?->wholesale_price, $percent, $fixed, $qty ?: 1);
            $afili  = PricingMath::scenario($hpp, $price, $percent + $affiliate, $fixed);

            return [
                'product' => ['id' => $pid, 'sku' => $p->sku, 'name' => $p->name, 'sale_type' => $p->sale_type],
                'hpp'     => $hpp,
                // Persen potongan yang berlaku untuk BARIS ini — hitung ulang di peramban memakainya.
                'fee_percent' => $percent,

                'price'        => $price,
                'price_source' => $source,
                'pushed_at'    => $row?->pushed_at,
                'is_pushed'    => (bool) $row?->isPushed(),

                'satuan' => $satuan,
                'grosir' => $grosir + ['min_qty' => $qty],

                'affiliate_percent' => (float) $affiliate,
                'affiliate'         => $afili,

                // Harga yang mengembalikan markup ke keadaan sebelum kena afiliasi.
                'affiliate_suggested' => PricingMath::roundUp(PricingMath::priceForMarkup(
                    $hpp, $satuan['markup_percent'] ?? 0, $percent + $affiliate, $fixed
                )),
                // Harga grosir yang untungnya (persen) sama dengan penjualan satuan.
                'grosir_suggested' => $qty ? PricingMath::roundUp(PricingMath::priceForMarkup(
                    $hpp, $satuan['markup_percent'] ?? 0, $percent, $fixed, $qty
                )) : null,
                'suggested' => $targetMarkup === null ? null : PricingMath::roundUp(
                    PricingMath::priceForMarkup($hpp, $targetMarkup, $percent, $fixed)
                ),
            ];
        })->values();
    }

    /**
     * HPP gabungan: produk yang diukur dari OP + paket yang dirakit dari komponennya.
     *
     * `replace()`, BUKAN `merge()`. Kedua koleksi dikunci product_id — kunci integer — dan
     * `merge()` menomori ulang kunci integer persis seperti array_merge, sehingga HPP produk
     * A bisa mendarat di produk B tanpa error apa pun. `replace()` mempertahankan kunci.
     */
    protected function hppMap(array $filters): Collection
    {
        return $this->hpp->all($filters)
            ->replace($this->bundles->all($filters))
            ->map(fn ($row) => $row['hpp_per_unit'] > 0 ? (float) $row['hpp_per_unit'] : null);
    }

    /**
     * Harga jual asli tiap produk — sumber yang sama dengan yang dibaca storefront & ERP
     * (product_prices, baris pertama), dengan base_price sebagai cadangan untuk produk yang
     * belum pernah punya baris harga.
     */
    protected function masterPrices(): Collection
    {
        $prices = DB::table('product_prices')
            ->where('channel', 'default')
            ->orderBy('id')
            ->get(['product_id', 'price'])
            ->groupBy('product_id')
            ->map(fn ($rows) => (float) $rows->first()->price);

        return DB::table('products')->pluck('base_price', 'id')
            ->map(fn ($base, $id) => $prices->get($id) ?: ((float) $base ?: null));
    }

    /** @return Collection<int,array> dikunci customer_id */
    protected function marketplaceConfigs(): Collection
    {
        return MarketplaceConfig::with('customer')->get()
            ->filter(fn ($c) => $c->customer)
            ->mapWithKeys(fn ($c) => [(int) $c->customer_id => [
                'customer_id' => (int) $c->customer_id,
                'name'        => $c->customer->name,
                'percent'     => (float) $c->admin_fee_percent,
                'fixed'       => (float) $c->admin_fee_fixed,
            ]]);
    }

    /** @return Collection<int,array<int,int>> store_id Jubelio per customer */
    protected function storeIdsByCustomer(): Collection
    {
        return JubelioChannelMap::where('is_active', true)->get()
            ->filter(fn ($m) => ctype_digit((string) $m->store))
            ->groupBy('customer_id')
            ->map(fn ($rows) => $rows->map(fn ($m) => (int) $m->store)->unique()->values()->all());
    }

    protected function resolveCustomers(array $channel, Collection $configs): Collection
    {
        $names = collect($channel['customers'] ?? [])->map(fn ($n) => mb_strtolower(trim($n)));

        return $configs->filter(fn ($c) => $names->contains(mb_strtolower(trim($c['name']))));
    }

    /**
     * Potongan yang benar-benar dipakai kanal ini.
     *
     * Selama ada penyusunnya, itulah yang berlaku. Yang belum punya penyusun sama sekali —
     * kanal baru, atau basis data yang menerima migrasinya saat daftar kanal belum terbaca —
     * TIDAK boleh dihitung sebagai potongan 0%: marketplace tetap memungut potongannya entah
     * halaman ini tahu atau tidak, dan harga yang disusun di atas potongan 0% adalah harga
     * yang rugi. Jadi jatuh balik ke potongan versi akuntansi, angka yang dipakai jurnal.
     *
     * Kanal gabungan (TikTok/Tokopedia): ambil yang tertinggi — kalau meleset, melesetnya ke
     * arah aman.
     *
     * @param Collection<int,PriceChannelFeeComponent> $components
     * @param Collection<int,array>                    $customers
     */
    protected function effectiveFee(Collection $components, Collection $customers, string $kind): array
    {
        if ($components->isNotEmpty() || $kind !== 'marketplace') {
            return PriceChannelFeeComponent::totals($components);
        }

        return [
            'percent' => (float) $customers->max('percent'),
            'fixed'   => (float) $customers->max('fixed'),
        ];
    }

    /** Jendela & ambang potongan real. */
    public const REAL_HARI        = 90;
    public const REAL_MIN_PESANAN = 10;
    public const REAL_MIN_PREORDER = 3;

    /**
     * Potongan REAL sebuah kanal — dihitung dari rincian potongan pesanan yang sudah SELESAI
     * (marketplace_order_fees final) dalam REAL_HARI terakhir. Null bila sampelnya terlalu sedikit.
     *
     * Dasar persennya NILAI BARANG di SO (Σ line_total), bukan kolom `kotor`: sebagian SO
     * marketplace sudah diturunkan ke nilai bersih saat DP, sehingga `kotor` kadang sudah
     * terpotong dan persennya jadi terlalu tinggi.
     *
     * Tiap penyusun di `admin_rincian` digolongkan sendiri: yang nominalnya hampir selalu sama
     * di semua pesanan (mis. Biaya proses pesanan Rp1.250, premi Rp350) = biaya TETAP per
     * pesanan; sisanya = PERSEN dari nilai barang. Affiliate & pajak tidak ikut — affiliate
     * punya tab sendiri, PPh final 0,5% juga dibayar untuk penjualan website.
     *
     * Biaya pre-order real = persen pesanan berisi produk preorder − persen pesanan tanpa
     * preorder (tidak pernah negatif). Persen dasarnya dari pesanan tanpa preorder supaya
     * biaya pre-order tidak ikut menggelembungkan potongan produk ready.
     *
     * @return array{percent:float,fixed:float,preorder:?float,orders:int,orders_preorder:int,days:int,rincian:array}|null
     */
    public function realFee(array $channel): ?array
    {
        $customerIds = collect($channel['customers'] ?? [])->pluck('customer_id')->filter()->all();
        if (!$customerIds) {
            return null;
        }

        $fees = DB::table('marketplace_order_fees as f')
            ->join('sales_orders as s', 's.id', '=', 'f.sales_order_id')
            ->where('f.final', true)
            ->whereIn('s.customer_id', $customerIds)
            ->whereNotIn('s.status', ['void', 'cancelled'])
            ->where('s.order_date', '>=', now()->subDays(self::REAL_HARI)->toDateString())
            ->get(['f.sales_order_id', 'f.admin', 'f.admin_rincian']);

        if ($fees->count() < self::REAL_MIN_PESANAN) {
            return null;
        }

        $soIds = $fees->pluck('sales_order_id')->all();
        $nilai = DB::table('sales_order_items')->whereIn('sales_order_id', $soIds)
            ->groupBy('sales_order_id')->selectRaw('sales_order_id, SUM(line_total) as v')
            ->pluck('v', 'sales_order_id');
        $adaPreorder = DB::table('sales_order_items as i')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->whereIn('i.sales_order_id', $soIds)->where('p.sale_type', 'preorder')
            ->distinct()->pluck('i.sales_order_id')->flip();

        // Pesanan → [nilai barang, preorder?, rincian]. Pesanan tanpa nilai barang dibuang.
        $pesanan = $fees->map(function ($f) use ($nilai, $adaPreorder) {
            $rincian = json_decode((string) $f->admin_rincian, true) ?: [];
            if (!$rincian && (float) $f->admin > 0) {
                $rincian = ['Biaya admin' => (float) $f->admin];
            }

            return [
                'nilai'    => (float) ($nilai[$f->sales_order_id] ?? 0),
                'preorder' => $adaPreorder->has($f->sales_order_id),
                'rincian'  => array_map('floatval', $rincian),
            ];
        })->filter(fn ($p) => $p['nilai'] > 0)->values();

        if ($pesanan->count() < self::REAL_MIN_PESANAN) {
            return null;
        }

        // Penyusun TETAP: satu nominal muncul di ≥80% pesanan yang memuatnya, DAN nominal itu
        // jauh lebih seragam daripada nilai pesanannya. Syarat kedua mencegah salah golong saat
        // nilai pesanannya sendiri seragam (semua Rp100.000 → komisi 10% pun selalu Rp10.000);
        // yang tak bisa dibedakan begitu dianggap persen.
        $bagianModus = fn (Collection $c) => $c->isEmpty() ? 0 : $c->countBy()->max() / $c->count();
        $tetap = [];
        foreach ($pesanan->flatMap(fn ($p) => array_keys($p['rincian']))->unique() as $label) {
            $memuat     = $pesanan->filter(fn ($p) => ($p['rincian'][$label] ?? 0) > 0);
            $nilaiLabel = $memuat->map(fn ($p) => round($p['rincian'][$label]));
            $nilaiOrder = $memuat->map(fn ($p) => round($p['nilai']));

            if ($bagianModus($nilaiLabel) >= 0.8 && $bagianModus($nilaiLabel) - $bagianModus($nilaiOrder) >= 0.3) {
                $tetap[$label] = true;
            }
        }

        $persenDari = function (Collection $kumpulan) use ($tetap): ?float {
            $barang = $kumpulan->sum('nilai');
            if ($barang <= 0) {
                return null;
            }
            $potong = $kumpulan->sum(fn ($p) => collect($p['rincian'])
                ->reject(fn ($v, $label) => isset($tetap[$label]))->sum());

            return $potong / $barang * 100;
        };

        $ready    = $pesanan->where('preorder', false);
        $po       = $pesanan->where('preorder', true);
        $persen   = $persenDari($ready->count() >= self::REAL_MIN_PESANAN ? $ready : $pesanan);
        $persenPo = $po->count() >= self::REAL_MIN_PREORDER ? $persenDari($po) : null;

        $rincian = [];
        foreach ($pesanan->flatMap(fn ($p) => array_keys($p['rincian']))->unique() as $label) {
            $total = $pesanan->sum(fn ($p) => $p['rincian'][$label] ?? 0);
            $rincian[] = isset($tetap[$label])
                ? ['label' => $label, 'jenis' => 'tetap', 'nilai' => $total / $pesanan->count()]
                : ['label' => $label, 'jenis' => 'persen', 'nilai' => $total / $pesanan->sum('nilai') * 100];
        }

        return [
            'percent'         => round((float) $persen, 2),
            'fixed'           => round($pesanan->sum(fn ($p) => collect($p['rincian'])
                                    ->filter(fn ($v, $label) => isset($tetap[$label]))->sum()) / $pesanan->count()),
            'preorder'        => $persenPo === null ? null : round(max(0, $persenPo - $persen), 2),
            'orders'          => $pesanan->count(),
            'orders_preorder' => $po->count(),
            'days'            => self::REAL_HARI,
            'rincian'         => $rincian,
        ];
    }

    /**
     * Potongan versi analisa vs versi akuntansi. Keduanya boleh berbeda — akuntansi hanya
     * memuat yang benar-benar dipungut marketplace — tapi kalau bagian yang dicentang "ikut
     * akuntansi" ternyata tidak sama, salah satunya sudah basi dan itu harus terlihat.
     */
    protected function accountingComparison(Collection $components, Collection $customers, ?array $effective = null): array
    {
        // Kanal yang belum punya penyusun memakai angka akuntansi apa adanya, jadi tidak ada
        // yang perlu dibandingkan — membandingkannya malah melaporkan "sudah basi" untuk
        // selisih yang tidak pernah ada.
        $mine = $components->isEmpty() && $effective !== null
            ? $effective
            : PriceChannelFeeComponent::totals($components, accountingOnly: true);

        return [
            'analysis'  => $mine,
            'customers' => $customers->map(fn ($c) => $c + [
                'matches' => abs($c['percent'] - $mine['percent']) < 0.005
                          && abs($c['fixed'] - $mine['fixed']) < 0.5,
            ])->values()->all(),
        ];
    }
}
