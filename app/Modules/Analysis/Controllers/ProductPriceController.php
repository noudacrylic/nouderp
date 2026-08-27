<?php

namespace App\Modules\Analysis\Controllers;

use App\Core\Inventory\Product;
use App\Http\Controllers\Controller;
use App\Modules\Analysis\Models\PriceChannelFeeComponent;
use App\Modules\Analysis\Models\ProductChannelPrice;
use App\Modules\Analysis\Services\BasePriceRolloutService;
use App\Modules\Analysis\Services\ChannelPricingService;
use App\Modules\Analysis\Services\ProductionCostRateService;
use App\Modules\Analysis\Services\ProductionTimeAnalysisService;
use App\Modules\Analysis\Support\PricingMath;
use App\Modules\Marketplace\Jubelio\Models\JubelioStorePrice;
use App\Modules\Marketplace\Jubelio\Services\JubelioProductSyncService;
use App\Modules\Sales\Services\PromotionService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Analisa ▸ Harga Produk — empat sub-tab, tiap sub-tab punya deretan kanal.
 *
 *   Harga    berapa untungnya kalau dijual sekian di kanal ini (dan tombol untuk
 *            benar-benar memberlakukan harganya)
 *   Afiliasi berapa untungnya kalau ikut program afiliasi, dan harus naik ke berapa
 *            supaya untungnya kembali seperti semula
 *   Grosir   berapa untungnya kalau minimum beli sekian diberi harga sekian
 *   Promo    berapa untungnya kalau didiskon — sebagai satu transaksi utuh (keranjang)
 *            maupun per produk, lengkap dengan batas diskon sebelum rugi
 *
 * Dipisah per skenario dulu baru per kanal — bukan sebaliknya — karena yang dibandingkan
 * saat menetapkan harga adalah kanalnya: "kalau di Shopee sekian, di Tokopedia jadi berapa".
 *
 * Halaman ini TIDAK PERNAH menjurnal apa pun. Satu-satunya hal yang keluar dari sini adalah
 * harga: ke master produk (kanal Website) atau ke toko marketplace lewat Jubelio.
 */
class ProductPriceController extends Controller
{
    public function __construct(protected ChannelPricingService $pricing) {}

    public function harga(Request $request)
    {
        return $this->render($request, 'harga');
    }

    public function afiliasi(Request $request)
    {
        return $this->render($request, 'afiliasi');
    }

    public function grosir(Request $request)
    {
        return $this->render($request, 'grosir');
    }

    /**
     * Simulasi promo — keranjang belanja andaian: tambah produk, beri diskon, lihat untungnya.
     *
     * Berhitung di sisi peramban supaya angkanya berubah sambil diketik; rumusnya kembar
     * dengan PricingMath di server (lihat _promo-hitung.blade.php).
     *
     * Overhead packing SENGAJA tidak dikurangi walau keranjang berisi banyak barang. Itu
     * keputusan pemilik: ongkos membungkus dianggap sudah selesai dihitung di HPP. Efeknya
     * simulasi sedikit lebih pesimistis untuk pesanan banyak barang — arah yang aman.
     */
    public function promo(Request $request)
    {
        $channels = $this->pricing->channels();
        $key      = $channels->has($request->input('kanal')) ? $request->input('kanal') : $channels->keys()->first();
        $channel  = $this->withFeeAssumption($request, $channels->get($key));

        return view('erp.analisa.harga.promo', [
            'tab'      => 'promo',
            'channels' => $channels,
            'channel'  => $channel,
            'katalog'  => $this->pricing->rows($key, $this->hppFilters())
                ->filter(fn ($r) => $r['hpp'] !== null || $r['price'] !== null)
                ->map(fn ($r) => [
                    'id'    => $r['product']['id'],
                    'sku'   => $r['product']['sku'],
                    'nama'  => $r['product']['name'],
                    'hpp'   => $r['hpp'],
                    'harga' => $r['price'],
                ])->values(),
        ]);
    }

    /** Sisi kedua simulasi: satu baris per produk, "kalau didiskon sekian, untungnya tinggal berapa". */
    public function promoProduk(Request $request)
    {
        $channels = $this->pricing->channels();
        $key      = $channels->has($request->input('kanal')) ? $request->input('kanal') : $channels->keys()->first();
        $channel  = $this->withFeeAssumption($request, $channels->get($key));

        $diskon  = (float) str_replace(',', '.', (string) $request->input('diskon', 0));
        $percent = (float) $channel['fee']['percent'];
        $fixed   = (float) $channel['fee']['fixed'];

        $rows = $this->arrange($request, $this->pricing->rows($key, $this->hppFilters(), null, $channel['fee']));

        $rows->getCollection()->transform(function ($row) use ($diskon, $percent, $fixed) {
            $harga = $row['price'];
            $net   = $harga === null ? null : $harga * (1 - $diskon / 100);

            $row['harga_diskon'] = $net;
            $row['setelah']      = PricingMath::scenario($row['hpp'], $net, $percent, $fixed);
            // Batas amannya: didiskon lebih dari ini, transaksinya rugi.
            $row['diskon_maks']  = PricingMath::maxDiscountPercent($row['hpp'], $harga, $percent, $fixed);

            return $row;
        });

        return view('erp.analisa.harga.promo-produk', [
            // Kunci tab-nya sendiri supaya pill kanal tetap mendarat di tampilan per-produk,
            // bukan melompat balik ke keranjang.
            'tab'      => 'promo-produk',
            'channels' => $channels,
            'channel'  => $channel,
            'rows'     => $rows,
            'diskon'   => $diskon,
            'sort'     => $request->input('sort', 'markup'),
        ]);
    }

    /**
     * Diskon dari promo yang BENAR-BENAR aktif, untuk isi keranjang simulasi.
     *
     * Memakai PromotionService yang sama dengan penjualan sungguhan — supaya yang diuji di
     * sini promo yang beneran jalan, bukan angka karangan yang kebetulan mirip.
     */
    public function promoAktif(Request $request, PromotionService $promotions)
    {
        $data = $request->validate([
            'items'            => 'array',
            'items.*.product_id' => 'required|integer',
            'items.*.qty'        => 'required|numeric|min:0',
            'items.*.unit_price' => 'required|numeric|min:0',
            'shipping'         => 'nullable|numeric|min:0',
            'voucher'          => 'nullable|string|max:64',
        ]);

        $items    = $data['items'] ?? [];
        $subtotal = collect($items)->sum(fn ($i) => $i['qty'] * $i['unit_price']);

        $hasil = $promotions->resolve([
            'items'          => $items,
            'subtotal'       => $subtotal,
            'shipping_gross' => (float) ($data['shipping'] ?? 0),
            'voucher_code'   => $data['voucher'] ?? null,
        ]);

        return response()->json([
            // `item_discounts` dikunci product_id; diskonnya nilai TOTAL untuk baris itu.
            'items'    => collect($hasil['item_discounts'] ?? [])->map(fn ($d, $pid) => [
                'product_id' => (int) $pid,
                'nama'       => $d['promotion_name'] ?? null,
                'amount'     => (float) ($d['discount_amount'] ?? 0),
            ])->values(),
            'shipping' => (float) ($hasil['shipping']['discount_amount'] ?? 0),
            'cart'     => (float) ($hasil['cart_total']['discount_amount'] ?? 0),
            'names'    => array_values(array_filter([
                collect($hasil['item_discounts'] ?? [])->first()['promotion_name'] ?? null,
                $hasil['shipping']['promotion_name'] ?? null,
                $hasil['cart_total']['promotion_name'] ?? null,
            ])),
        ]);
    }

    protected function render(Request $request, string $tab)
    {
        $channels = $this->pricing->channelsFor($tab);

        if ($channels->isEmpty()) {
            return redirect(route('analisa.hpp.index'))
                ->with('error', 'Belum ada kanal penjualan yang dikonfigurasi.');
        }

        $key     = $channels->has($request->input('kanal')) ? $request->input('kanal') : $channels->keys()->first();
        $channel = $this->withFeeAssumption($request, $channels->get($key));

        $markup = $request->filled('markup') ? (float) str_replace(',', '.', $request->input('markup')) : null;
        $rows   = $this->arrange($request, $this->pricing->rows($key, $this->hppFilters(), $markup, $channel['fee']));

        return view("erp.analisa.harga.{$tab}", [
            'tab'      => $tab,
            'channels' => $channels,
            'channel'  => $channel,
            'rows'     => $rows,
            'markup'   => $markup,
            'sort'     => $request->input('sort', 'markup'),
            // Kanal marketplace yang harganya sudah menyimpang dari harga dasar — hanya
            // relevan (dan hanya dihitung) saat yang dibuka kanal Website.
            'beda'     => $channel['kind'] === 'internal' ? $this->bedaDariDasar($rows) : collect(),
            // Dibaca DI SINI, sesudah rows(), bukan di dalamnya — supaya rekaman harga
            // marketplace tidak ikut masuk simpanan Analisa dan tidak pernah tampil basi.
            'pasar'    => JubelioStorePrice::ringkasUntuk($channel['store_ids'] ?? []),
        ]);
    }

    /**
     * Simpan harga satuan sebuah produk di sebuah kanal.
     *
     * Kanal Website menulis ke master harga produk — satu angka yang dipakai storefront,
     * ERP, dan jadi harga dasar Jubelio. Kanal marketplace menulis ke harga kanalnya sendiri
     * dan baru berlaku di tokonya setelah tombol kirim ditekan.
     */
    public function savePrice(Request $request, int $productId, BasePriceRolloutService $rollout)
    {
        $request->validate(['kanal' => 'required|string', 'price' => 'nullable|string']);

        $channel = $this->channelOrFail($request->input('kanal'));
        $product = Product::findOrFail($productId);
        $price   = $this->rupiah($request->input('price'));

        if ($channel['kind'] === 'internal') {
            if ($price === null || $price <= 0) {
                return back()->with('error', 'Harga website tidak boleh kosong — harga ini dipakai web dan ERP.');
            }

            $rollout->saveBasePrice($product, $price);

            return back()->with('success', "Harga {$product->name} disimpan: " . $this->format($price) . ' — berlaku di web, ERP, dan jadi harga dasar Jubelio.');
        }

        ProductChannelPrice::updateOrCreate(
            ['product_id' => $productId, 'channel' => $channel['key']],
            ['price' => $price],
        );

        return back()->with('success', $price === null
            ? "Harga khusus {$channel['label']} dihapus — produk ini kembali ikut harga dasar."
            : "Harga {$channel['label']} disimpan: " . $this->format($price) . '. Tekan Kirim supaya berlaku di tokonya.');
    }

    public function saveGrosir(Request $request, int $productId)
    {
        $request->validate([
            'kanal'             => 'required|string',
            'wholesale_price'   => 'nullable|string',
            'wholesale_min_qty' => 'nullable|integer|min:1',
        ]);

        $channel = $this->channelOrFail($request->input('kanal'));
        abort_unless(DB::table('products')->where('id', $productId)->exists(), 404);

        ProductChannelPrice::updateOrCreate(
            ['product_id' => $productId, 'channel' => $channel['key']],
            [
                'wholesale_price'   => $this->rupiah($request->input('wholesale_price')),
                'wholesale_min_qty' => $request->input('wholesale_min_qty') ?: null,
            ],
        );

        return back()->with('success', 'Harga grosir disimpan. Harga grosir tidak dikirim ke marketplace — atur sendiri di seller center.');
    }

    public function saveAfiliasi(Request $request, int $productId)
    {
        $request->validate([
            'kanal'             => 'required|string',
            'affiliate_percent' => 'nullable|numeric|min:0|max:100',
        ]);

        $channel = $this->channelOrFail($request->input('kanal'));
        abort_unless(DB::table('products')->where('id', $productId)->exists(), 404);

        ProductChannelPrice::updateOrCreate(
            ['product_id' => $productId, 'channel' => $channel['key']],
            ['affiliate_percent' => $request->input('affiliate_percent')],
        );

        return back()->with('success', 'Persentase afiliasi disimpan.');
    }

    /** Berlakukan harga kanal di tokonya lewat Jubelio (harga khusus toko, bukan harga dasar). */
    public function push(Request $request, int $productId, JubelioProductSyncService $sync)
    {
        $request->validate(['kanal' => 'required|string']);

        $channel = $this->channelOrFail($request->input('kanal'));
        $product = Product::findOrFail($productId);

        if ($channel['kind'] === 'internal') {
            return back()->with('error', 'Kanal Website tidak lewat Jubelio — pakai tombol Simpan Harga.');
        }

        $row = ProductChannelPrice::where('product_id', $productId)->where('channel', $channel['key'])->first();

        if (!$row || $row->price === null) {
            return back()->with('error', 'Isi lalu simpan harga khusus kanal ini dulu. Tanpa itu produk memang dijual di harga dasar.');
        }

        $result = $sync->pushStorePrice($product, $channel['store_ids'], (float) $row->price);

        if (!$result['ok']) {
            return back()->with('error', $result['message']);
        }

        $row->forceFill(['pushed_price' => $row->price, 'pushed_at' => now()])->save();

        return back()->with('success', "{$product->name} — {$result['message']}");
    }

    /**
     * Satu tombol dari kanal Website: samakan SEMUA marketplace dengan harga dasar ini,
     * sekaligus kirimkan ke tokonya masing-masing lewat Jubelio.
     *
     * Harga yang dipakai diambil dari kolom yang sedang diketik (dikirim ikut formnya),
     * bukan dari yang tersimpan — kalau tidak, orang mengetik harga baru, menekan tombol
     * ini, lalu seluruh marketplace disamakan dengan harga LAMA tanpa ada yang sadar.
     * Karena itu harga dasarnya ikut disimpan lebih dulu, persis seperti menekan ✓.
     */
    public function applyToMarketplaces(Request $request, int $productId, BasePriceRolloutService $rollout)
    {
        $request->validate(['price' => 'nullable|string']);

        $product = Product::findOrFail($productId);
        $price   = $this->rupiah($request->input('price')) ?? $rollout->currentBasePrice($product);

        if ($price === null || $price <= 0) {
            return back()->with('error', 'Isi harga dasarnya dulu — angka inilah yang akan disalin ke semua marketplace.');
        }

        $hasil = $rollout->applyToMarketplaces($product, $price);

        // Sebagian berhasil sebagian tidak bukan "sukses", tapi juga bukan kegagalan yang
        // membuat orang mengulang semuanya dari nol — pesannya menyebut kanal mana saja.
        return back()->with($hasil['ok'] ? 'success' : 'warning', $hasil['pesan']);
    }

    /**
     * Tarik harga yang SEDANG dipegang Jubelio untuk toko-toko kanal ini.
     *
     * `pushed_at` hanya mencatat bahwa kita pernah mengirim; ia buta terhadap apa yang
     * terjadi sesudahnya — harga bisa diubah orang lain langsung di Jubelio atau di seller
     * center. Kolom ini yang menutup lubang itu: kita membandingkan harga hasil hitungan
     * dengan harga yang benar-benar dipegang tokonya.
     *
     * Satu panggilan API per produk, jadi ini pekerjaan tombol — bukan sesuatu yang boleh
     * ikut terjadi setiap halaman dibuka.
     */
    public function pullMarketPrices(Request $request, JubelioProductSyncService $sync)
    {
        $request->validate(['kanal' => 'required|string']);
        $channel = $this->channelOrFail($request->input('kanal'));

        if ($channel['kind'] === 'internal') {
            return back()->with('error', 'Kanal Website tidak lewat Jubelio — harganya memang harga master.');
        }
        if (empty($channel['store_ids'])) {
            return back()->with('error', 'Kanal ini belum punya toko Jubelio yang dipetakan.');
        }

        $stats = $sync->pullStorePrices($channel['store_ids']);

        if (array_sum($stats) === 0) {
            return back()->with('error', 'Jubelio belum tersambung — cek Pengaturan ▸ Integrasi.');
        }

        $pesan = sprintf('Harga %s ditarik dari Jubelio: %d terisi, %d kosong, %d gagal.',
            $channel['label'], $stats['terisi'], $stats['kosong'], $stats['gagal']);

        // Sisanya bukan kegagalan — hanya belum giliran. Menyebutnya mencegah orang mengira
        // seluruh katalog sudah diperbarui padahal baru sebagian.
        if ($stats['sisa'] > 0) {
            $pesan .= sprintf(' %d produk belum giliran — tekan lagi untuk melanjutkan,'
                . ' atau jalankan `php artisan jubelio:tarik-harga %s` untuk sekali sapu.',
                $stats['sisa'], $channel['key']);
        }

        return back()->with('success', $pesan);
    }

    /** Master penyusun potongan sebuah kanal (baris baru atau ubah baris yang ada). */
    public function saveComponent(Request $request)
    {
        $data = $request->validate([
            'id'                 => 'nullable|integer|exists:price_channel_fee_components,id',
            'channel'            => 'required|string',
            'label'              => 'required|string|max:255',
            'percent'            => 'nullable|numeric|min:0|max:100',
            'fixed'              => 'nullable|string',
            'include_accounting' => 'nullable|boolean',
        ]);

        $channel = $this->channelOrFail($data['channel']);

        $values = [
            'channel'            => $channel['key'],
            'label'              => $data['label'],
            'percent'            => (float) ($data['percent'] ?? 0),
            'fixed'              => (float) ($this->rupiah($data['fixed'] ?? null) ?? 0),
            'include_accounting' => $request->boolean('include_accounting'),
        ];

        if (!empty($data['id'])) {
            PriceChannelFeeComponent::findOrFail($data['id'])->update($values);
        } else {
            PriceChannelFeeComponent::create($values + [
                'sort_order' => (int) PriceChannelFeeComponent::where('channel', $channel['key'])->max('sort_order') + 1,
            ]);
        }

        return back()->with('success', "Potongan {$channel['label']} diperbarui.");
    }

    public function destroyComponent(int $id)
    {
        PriceChannelFeeComponent::findOrFail($id)->delete();

        return back()->with('success', 'Penyusun potongan dihapus.');
    }

    /** Potongan andaian yang sedang terpasang, per kanal, milik satu orang. */
    protected const SESI_ANDAIAN = 'analisa.potongan_andaian';

    /**
     * Potongan andaian sebuah kanal — "kalau potongan Shopee naik jadi 16%, masih untung?".
     *
     * Dulu andaian ini hanya menempel di query string, dan itu bocor di tempat yang tidak
     * kelihatan: form cari/urut/tipe tidak ikut membawa `fee_pct`. Sekali seseorang mengetik
     * nama produk, andaiannya lenyap tanpa pemberitahuan dan tabelnya diam-diam kembali ke
     * potongan asli — persis saat orangnya sedang membandingkan. Menambalnya dengan menyalin
     * hidden input ke tiap form berarti harus INGAT setiap form yang ada dan yang dibuat
     * nanti; satu yang terlupa mengembalikan bug ini utuh-utuh.
     *
     * Karena itu andaiannya disimpan di SESI, per kanal — Shopee dan Tokopedia tidak saling
     * meminjam. URL tetap menang bila diisi (supaya tautan yang dibagikan membawa angka yang
     * sama) dan sekaligus memperbarui sesi.
     *
     * SESI, bukan tabel: ini pengandaian SATU orang yang sedang menimbang. Menyimpannya ke
     * basis data membuat rekan yang membuka halaman yang sama melihat angka rekaan sebagai
     * kenyataan, tanpa pernah memintanya.
     */
    protected function withFeeAssumption(Request $request, array $channel): array
    {
        $channel['fee_actual']  = $channel['fee'];
        $channel['fee_assumed'] = false;

        $kunci     = $channel['key'];
        $tersimpan = (array) $request->session()->get(self::SESI_ANDAIAN, []);

        // Formnya dikirim dengan kedua kolom kosong = "pakai potongan sebenarnya". Tanpa
        // penanda `fee_form`, permintaan itu tidak bisa dibedakan dari kunjungan biasa dan
        // andaiannya akan menolak dihapus.
        $dikosongkan = $request->has('fee_form')
            && !$request->filled('fee_pct') && !$request->filled('fee_rp');

        if ($request->boolean('fee_reset') || $dikosongkan) {
            unset($tersimpan[$kunci]);
            $request->session()->put(self::SESI_ANDAIAN, $tersimpan);

            return $channel;
        }

        if ($request->filled('fee_pct') || $request->filled('fee_rp')) {
            $percent = $request->filled('fee_pct')
                ? (float) str_replace(',', '.', (string) $request->input('fee_pct'))
                : (float) $channel['fee']['percent'];
            $fixed = $request->filled('fee_rp')
                ? (float) clean_number((string) $request->input('fee_rp'))
                : (float) $channel['fee']['fixed'];

            $diketik = ['percent' => max(0, $percent), 'fixed' => max(0, $fixed)];

            // Mengetik angka yang sama persis dengan potongan asli = membatalkan andaian.
            // Kalau tetap disimpan, ia akan tidur diam-diam lalu bangun jadi "andaian" pada
            // hari potongan aslinya diubah — kejutan yang tidak pernah diminta siapa pun.
            if ($this->samaDenganAsli($diketik, $channel['fee_actual'])) {
                unset($tersimpan[$kunci]);
            } else {
                $tersimpan[$kunci] = $diketik;
            }

            $request->session()->put(self::SESI_ANDAIAN, $tersimpan);
        }

        if (!isset($tersimpan[$kunci])) {
            return $channel;
        }

        $channel['fee'] = [
            'percent' => (float) $tersimpan[$kunci]['percent'],
            'fixed'   => (float) $tersimpan[$kunci]['fixed'],
        ];
        $channel['fee_assumed'] = !$this->samaDenganAsli($channel['fee'], $channel['fee_actual']);

        return $channel;
    }

    /** Dua potongan dianggap sama bila selisihnya di bawah yang bisa dilihat mata di layar. */
    protected function samaDenganAsli(array $a, array $b): bool
    {
        return abs($a['percent'] - $b['percent']) <= 0.001
            && abs($a['fixed'] - $b['fixed']) <= 0.5;
    }

    /**
     * Kanal marketplace yang harganya berbeda dari harga dasar, per produk.
     *
     * Tanpa ini tombol "Terapkan ke marketplace" ditekan buta: tidak ada yang tahu produk
     * mana yang memang sudah seragam dan mana yang sedang dijual dengan harga khusus yang
     * akan tertimpa. Dihitung hanya untuk baris yang sedang tampil — satu query, bukan
     * satu per produk.
     *
     * @return Collection<int,array<int,string>> dikunci product_id
     */
    protected function bedaDariDasar(LengthAwarePaginator $rows): Collection
    {
        $labels = $this->pricing->channels()->reject(fn ($c) => $c['kind'] === 'internal')->pluck('label', 'key');
        $dasar  = collect($rows->items())->mapWithKeys(fn ($r) => [$r['product']['id'] => $r['price']]);

        if ($labels->isEmpty() || $dasar->isEmpty()) {
            return collect();
        }

        return ProductChannelPrice::whereIn('channel', $labels->keys())
            ->whereIn('product_id', $dasar->keys())
            ->whereNotNull('price')
            ->get()
            ->filter(fn ($r) => $dasar[$r->product_id] === null
                || round((float) $r->price) !== round((float) $dasar[$r->product_id]))
            ->groupBy('product_id')
            ->map(fn ($baris) => $baris
                ->map(fn ($r) => $labels[$r->channel] . ' ' . $this->format((float) $r->price))
                ->values()->all());
    }

    /** Cari + urutkan + paginasi — sama untuk keempat sub-tab. */
    protected function arrange(Request $request, Collection $rows): LengthAwarePaginator
    {
        if ($search = trim((string) $request->input('search', ''))) {
            $rows = $rows->filter(fn ($r) => stripos((string) $r['product']['name'], $search) !== false
                || stripos((string) $r['product']['sku'], $search) !== false);
        }

        if ($type = $request->input('tipe')) {
            $rows = $rows->filter(fn ($r) => $type === 'bundle'
                ? $r['product']['sale_type'] === 'bundle'
                : $r['product']['sale_type'] !== 'bundle');
        }

        $rows = match ($request->input('sort', 'markup')) {
            'hpp'   => $rows->sortByDesc('hpp'),
            'harga' => $rows->sortByDesc('price'),
            'nama'  => $rows->sortBy(fn ($r) => $r['product']['name']),
            // Yang paling tipis untungnya lebih dulu: itu yang perlu diputuskan hari ini.
            default => $rows->sortBy(fn ($r) => $r['satuan']['markup_percent'] ?? PHP_INT_MAX),
        };

        $perPage = per_page_size();
        $page    = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }

    protected function channelOrFail(?string $key): array
    {
        $channel = $key ? $this->pricing->channel($key) : null;
        abort_if(!$channel, 404, 'Kanal tidak dikenal.');

        return $channel;
    }

    /**
     * HPP dibaca dengan filter bawaan halaman HPP — filter samplenya diatur di sana, bukan
     * di sini. Yang diteruskan hanya mode asumsi harga bahan, supaya pertanyaan "kalau
     * akrilik naik, produk mana yang markup-nya jatuh" bisa dijawab di halaman ini juga.
     */
    protected function hppFilters(): array
    {
        return [
            'date_from'      => null,
            'date_to'        => null,
            'types'          => ProductionTimeAnalysisService::DEFAULT_TYPES,
            'include_merged' => false,
            'months'         => ProductionCostRateService::DEFAULT_PERIOD_MONTHS,
            'assumption'     => request()->boolean('asumsi'),
        ];
    }

    /** Input rupiah diketik dengan titik ribuan — `(float)` akan membaca 199.900 sebagai 199. */
    protected function rupiah($raw): ?float
    {
        $raw = trim((string) $raw);

        return $raw === '' ? null : (float) clean_number($raw);
    }

    protected function format(float $value): string
    {
        return 'Rp' . number_format($value, 0, ',', '.');
    }
}
