<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Core\Inventory\Product;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PaymentSetting;
use App\Models\WebPayment;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\PromotionService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\WebPaymentService;
use App\Modules\Shipping\Services\PointAddressResolver;
use App\Modules\Shipping\ShippingManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Checkout etalase (noudakrilik.com) — server-side, digerbangi storefront.api.
 * Alur: cari alamat (RajaOngkir) → cek ongkir → checkout (buat SO + reservasi + kode unik)
 * → instruksi bayar transfer. Status & klaim transfer via token publik.
 */
class CheckoutController extends Controller
{
    public function __construct(
        private ShippingManager $shipping,
        private SalesOrderService $orders,
        private WebPaymentService $webPayments,
        private PromotionService $promotions,
    ) {}

    /**
     * Provider ongkir etalase — satu saja, supaya pembeli tidak disodori tarif ganda
     * untuk kurir yang sama. Diambil dari provider aktif pertama (lihat ShippingManager),
     * jadi mengganti agregator cukup lewat Pengaturan, tanpa menyentuh kode ini lagi.
     */
    private function rateProviderKey(): ?string
    {
        return $this->shipping->defaultProviderKey();
    }

    /** Cari wilayah tujuan → area id + kode pos milik provider aktif. ?q= (min 3 huruf). */
    public function areas(Request $request)
    {
        $q = trim((string) $request->get('q'));
        if (mb_strlen($q) < 3) {
            return response()->json(['data' => []]);
        }

        $key      = $this->rateProviderKey();
        $provider = $key ? $this->shipping->provider($key) : null;
        $res      = $provider ? $provider->searchAreas($q) : ['areas' => []];

        return response()->json(['data' => $res['areas'] ?? [], 'provider' => $key]);
    }

    /**
     * Titik peta pembeli → alamat + area kurir + kode pos.
     * Body { latitude, longitude }.
     *
     * Kurir instant memang menghitung tarif dari KOORDINAT, tapi Jubelio tetap
     * mewajibkan kode pos & area tujuan; pin di peta karena itu harus diterjemahkan
     * dulu di sini — etalase tidak boleh menebak kamus wilayah agregator sendiri.
     */
    public function resolvePoint(Request $request, PointAddressResolver $resolver)
    {
        $data = $request->validate([
            'latitude'  => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        $key      = $this->rateProviderKey();
        $provider = $key ? $this->shipping->provider($key) : null;

        return response()->json([
            'data' => $resolver->resolve((float) $data['latitude'], (float) $data['longitude'], $provider),
        ]);
    }

    /**
     * Titik toko (gudang penjualan default) — pusat awal peta checkout, dipakai saat
     * pembeli menolak izin lokasi. Diambil dari master gudang supaya pindah toko tidak
     * perlu menyentuh kode etalase.
     */
    public function originPoint()
    {
        $wh = \App\Core\Inventory\Warehouse::shippingOrigin();

        return response()->json(['data' => [
            'latitude'  => $wh && $wh->latitude !== null ? (float) $wh->latitude : null,
            'longitude' => $wh && $wh->longitude !== null ? (float) $wh->longitude : null,
            'label'     => $wh->name ?? null,
        ]]);
    }

    /** Cek ongkir: body { destination_area_id, destination_postal_code?, items:[...] }. */
    public function rates(Request $request)
    {
        $data = $request->validate([
            // Mode instant memakai pin di peta: area_id ikut bila reverse geocode
            // mengenalinya, tapi tidak wajib — yang menentukan tarif adalah koordinat.
            'destination_area_id' => 'required_unless:mode,instant|nullable|string|max:50',
            // Jubelio MEWAJIBKAN kode pos tujuan; etalase mengirimnya balik dari hasil
            // pencarian wilayah (endpoint areas sudah menyertakan postal_code).
            'destination_postal_code' => 'required_if:mode,instant|nullable|string|max:10',
            // Kurir instant (GoSend/Grab/Paxel) menghitung tarif dari TITIK LOKASI,
            // jadi pembeli menaruh pin di peta; kode pos tetap dikirim untuk validasi.
            'mode'                => 'nullable|in:instant,regular',
            'destination_latitude'  => 'required_if:mode,instant|nullable|numeric|between:-90,90',
            'destination_longitude' => 'required_if:mode,instant|nullable|numeric|between:-180,180',
            'items'               => 'required|array|min:1',
            'items.*.product_id'  => 'required|integer',
            'items.*.qty'         => 'required|numeric|min:1',
        ]);

        $key = $this->rateProviderKey();

        $res = $this->shipping->rates([
            'provider'            => $key,
            // Kirim area id ke SEMUA kunci provider — nilainya memang berasal dari
            // provider aktif itu sendiri, dan provider lain sedang tidak dipakai.
            'destination_area_id'     => $data['destination_area_id'] ?? null,
            'destination_jubelio_id'  => $data['destination_area_id'] ?? null,
            'destination_postal_code' => $data['destination_postal_code'] ?? null,
            'mode'                    => $data['mode'] ?? 'regular',
            'destination_latitude'    => $data['destination_latitude'] ?? null,
            'destination_longitude'   => $data['destination_longitude'] ?? null,
            'items'                   => $this->rateItems($data['items']),
        ]);

        // Sisipkan diskon ongkir (promo) per layanan → etalase tampilkan potongannya.
        $subtotal = $this->goodsSubtotal($data['items']);
        $rates = collect($res['rates'] ?? [])->map(function ($r) use ($subtotal) {
            $gross = (float) ($r['price'] ?? 0);
            $disc  = $this->promotions->resolveShippingDiscount($subtotal, $gross, null, []);
            $r['discount'] = $disc ? (float) min($disc['discount_amount'], $gross) : 0;
            return $r;
        })->all();

        return response()->json(['data' => $rates, 'errors' => $res['errors']]);
    }

    /** Subtotal barang (setelah diskon item) — dasar min belanja diskon ongkir. */
    private function goodsSubtotal(array $items): float
    {
        $ids = collect($items)->pluck('product_id')->map(fn ($v) => (int) $v)->unique();
        $products = Product::whereIn('id', $ids)->where('is_sellable', true)->get()->keyBy('id');

        $input = [];
        foreach ($items as $it) {
            $p = $products->get((int) $it['product_id']);
            if (! $p) continue;
            $input[] = ['product_id' => $p->id, 'qty' => (float) $it['qty'], 'unit_price' => (float) ($p->display_price ?? 0)];
        }
        $discounts = $input ? $this->promotions->resolveItemDiscounts($input) : [];

        $subtotal = 0.0;
        foreach ($input as $pi) {
            $disc = (float) ($discounts[$pi['product_id']]['discount_amount'] ?? 0);
            $subtotal += $pi['qty'] * $pi['unit_price'] - $disc;
        }
        return max(0, $subtotal);
    }

    /**
     * Kuotasi keranjang: subtotal setelah diskon item + diskon "total belanja" (promo
     * cart_total). Dipakai etalase agar ringkasan checkout sama persis dengan SO
     * yang nanti dibuat — termasuk saat metode Ambil di Toko (tanpa cek ongkir).
     * Body: { items:[{product_id, qty}] }.
     */
    public function quote(Request $request)
    {
        $data = $request->validate([
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.qty'        => 'required|numeric|min:1',
        ]);

        $ids      = collect($data['items'])->pluck('product_id')->map(fn ($v) => (int) $v)->unique();
        $products = Product::whereIn('id', $ids)->where('is_sellable', true)->get()->keyBy('id');

        $input = [];
        foreach ($data['items'] as $it) {
            $p = $products->get((int) $it['product_id']);
            if (! $p) continue;
            $input[] = ['product_id' => $p->id, 'qty' => (float) $it['qty'], 'unit_price' => (float) ($p->display_price ?? 0)];
        }
        $discounts = $input ? $this->promotions->resolveItemDiscounts($input) : [];

        // Rincian per baris: harga normal vs harga promo (per unit) — etalase memakai ini
        // agar keranjang selalu menampilkan harga terbaru, bukan harga saat ditambahkan.
        $lines = [];
        $originalSubtotal = 0.0;
        $subtotal = 0.0;
        $promoIds = [];
        foreach ($input as $pi) {
            $disc      = $discounts[$pi['product_id']] ?? null;
            $discAmt   = (float) ($disc['discount_amount'] ?? 0);   // total untuk qty baris ini
            $unitDisc  = $pi['qty'] > 0 ? $discAmt / $pi['qty'] : 0;
            $lineGross = $pi['qty'] * $pi['unit_price'];

            $originalSubtotal += $lineGross;
            $subtotal         += $lineGross - $discAmt;
            if ($disc) $promoIds[] = (int) $disc['promotion_id'];

            $lines[] = [
                'product_id'      => $pi['product_id'],
                'qty'             => $pi['qty'],
                'price_original'  => round($pi['unit_price']),
                'price'           => round($pi['unit_price'] - $unitDisc),
                'discount'        => round($discAmt),
                'promotion_name'  => $disc['promotion_name'] ?? null,
            ];
        }
        $subtotal = max(0, $subtotal);

        $cart      = $this->promotions->resolveCartTotalDiscount($subtotal);
        $cartAmt   = $cart ? (float) min($cart['discount_amount'], $subtotal) : 0.0;
        if ($cartAmt > 0) $promoIds[] = (int) $cart['promotion_id'];

        return response()->json(['data' => [
            'items'             => $lines,
            'original_subtotal' => round($originalSubtotal),
            'item_discount'     => round($originalSubtotal - $subtotal),
            'subtotal'          => round($subtotal),
            'cart_discount'     => round($cartAmt),
            'promotion_name'    => $cartAmt > 0 ? ($cart['promotion_name'] ?? null) : null,
            'total_discount'    => round($originalSubtotal - $subtotal + $cartAmt),
            // Batas waktu promo terdekat yang dipakai keranjang ini (untuk urgensi checkout).
            'promo_ends_at'     => $this->nearestPromoEnd($promoIds),
        ]]);
    }

    /** Tanggal berakhir terdekat dari promo yang sedang dipakai (null bila tanpa batas). */
    private function nearestPromoEnd(array $promotionIds): ?string
    {
        $ids = array_values(array_unique(array_filter($promotionIds)));
        if (empty($ids)) {
            return null;
        }

        $end = \App\Modules\Sales\Models\Promotion::whereIn('id', $ids)
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', now())
            ->min('ends_at');

        return $end ? \Illuminate\Support\Carbon::parse($end)->toIso8601String() : null;
    }

    /** Buat pesanan + instruksi pembayaran. */
    public function checkout(Request $request)
    {
        $setting = PaymentSetting::singleton();
        // Cukup salah satu siap: QRIS ATAU transfer bank + kode unik.
        if (! $setting->isConfigured() && ! $setting->qrisReady()) {
            return response()->json(['message' => 'Pembayaran belum diaktifkan.'], 503);
        }

        $data = $request->validate([
            'customer.name'                => 'required|string|max:200',
            'customer.phone'               => 'required|string|max:30',
            'customer.pin'                 => 'required|digits_between:4,6',
            'customer.email'               => 'nullable|email|max:200',
            'customer.address'             => 'nullable|string|max:2000',
            'customer.postal_code'         => 'nullable|string|max:10',
            'customer.destination_label'   => 'nullable|string|max:255',
            'items'                        => 'required|array|min:1',
            'items.*.product_id'           => 'required|integer',
            'items.*.qty'                  => 'required|numeric|min:1',
            'delivery_method'              => 'required|in:kurir,instant,ambil_toko',
            'customer.latitude'            => 'nullable|numeric|between:-90,90',
            'customer.longitude'           => 'nullable|numeric|between:-180,180',
            'payment_method'               => 'nullable|in:qris,transfer,midtrans',
            'shipping.courier_code'        => 'nullable|string|max:50',
            'shipping.service_name'        => 'nullable|string|max:150',
            'shipping.price'               => 'nullable|numeric|min:0',
        ]);

        $needsCourier = in_array($data['delivery_method'], ['kurir', 'instant'], true);

        if ($needsCourier && ! isset($data['shipping']['price'])) {
            return response()->json(['message' => 'Ongkir belum dipilih.'], 422);
        }
        // Tanpa titik lokasi, kurir instant tidak bisa dijemput — tolak di sini supaya
        // pesanan tidak terlanjur dibuat lalu mentok saat booking resi.
        if ($data['delivery_method'] === 'instant'
            && (empty($data['customer']['latitude']) || empty($data['customer']['longitude']))) {
            return response()->json(['message' => 'Titik lokasi belum dipilih untuk pengiriman instant.'], 422);
        }

        // Validasi produk (harus dijual) + susun baris + harga promo.
        $ids = collect($data['items'])->pluck('product_id')->map(fn ($v) => (int) $v)->unique();
        $products = Product::whereIn('id', $ids)->where('is_sellable', true)->get()->keyBy('id');

        $lineItems  = [];
        $promoInput = [];
        foreach ($data['items'] as $it) {
            $p = $products->get((int) $it['product_id']);
            if (! $p) {
                return response()->json(['message' => "Produk #{$it['product_id']} tidak tersedia."], 422);
            }
            $qty   = (float) $it['qty'];
            $price = (float) ($p->display_price ?? 0);
            $promoInput[] = ['product_id' => $p->id, 'qty' => $qty, 'unit_price' => $price];
            $lineItems[]  = ['product_id' => $p->id, 'qty' => $qty, 'unit_price' => $price, 'discount_type' => 'nominal', 'discount_value' => 0];
        }

        // Diskon promo item (harga coret) → jadikan diskon per-unit nominal.
        $discounts = $promoInput ? $this->promotions->resolveItemDiscounts($promoInput) : [];
        foreach ($lineItems as &$line) {
            $disc = $discounts[$line['product_id']] ?? null;
            if ($disc && $line['qty'] > 0) {
                $line['discount_value'] = round(((float) $disc['discount_amount']) / $line['qty']);
            }
        }
        unset($line);

        // Subtotal barang (setelah diskon item) → dasar diskon ongkir.
        $subtotal = 0.0;
        foreach ($lineItems as $l) {
            $subtotal += (float) $l['qty'] * ((float) $l['unit_price'] - (float) $l['discount_value']);
        }

        try {
            return DB::transaction(function () use ($data, $lineItems, $setting, $subtotal) {
            $customer = $this->resolveCustomer($data['customer']);

            // Promo "total belanja" (cart_total) → mengisi diskon global SO.
            $cartDisc   = $this->promotions->resolveCartTotalDiscount($subtotal);
            $cartAmount = $cartDisc ? (float) min($cartDisc['discount_amount'], $subtotal) : 0.0;

            // Nomor pesanan toko online — format YYMM-XXXXXXX (mis. 2607-8373645), acak &
            // 100% terpisah dari seri SO internal. Dipakai sebagai nomor SO sekaligus No.
            // Pesanan (customer_po_number), sehingga saat difakturkan di ERP invoice ikut
            // nomor ini → SO, faktur web, dan faktur ERP bernomor sama (audit mudah).
            $webNumber = \App\Services\NumberGeneratorService::webOrderNumber();

            $dto = [
                'customer_id'           => $customer->id,
                'order_number'          => $webNumber,
                'customer_po_number'    => $webNumber,
                'delivery_method'       => $data['delivery_method'],
                'order_date'            => now()->toDateString(),
                'notes'                 => 'Pesanan toko online (noudakrilik.com)',
                'global_discount_type'  => 'nominal',
                'global_discount_value' => $cartAmount,
                'items'                 => $lineItems,
            ];
            if ($needsCourier) {
                $gross = (float) $data['shipping']['price'];
                $shipDisc = $this->promotions->resolveShippingDiscount($subtotal, $gross, null, []);
                $dto['shipping_gross']          = $gross;
                $dto['shipping_discount_type']  = 'nominal';
                $dto['shipping_discount_value'] = $shipDisc ? (float) min($shipDisc['discount_amount'], $gross) : 0;
                $dto['courier_name']            = $data['shipping']['service_name'] ?? 'Kurir';
            }

            $so = $this->orders->createDraftFromData($dto);
            $this->orders->confirm($so->id);              // reservasi stok

            // Metode bayar: ikuti pilihan pembeli bila metodenya memang siap,
            // selain itu jatuh ke metode pertama yang tersedia.
            $wp = $this->webPayments->createForOrder($so->id, $this->resolveMethod($data['payment_method'] ?? null, $setting));

            $qrisNote = null;
            if ($wp->isQris()) {
                // QR dibuat sekarang supaya pembeli langsung bisa memindai. Bila gagal
                // (mis. saldo Komerce habis), pesanan JANGAN batal — alihkan ke transfer
                // bank + kode unik agar pembeli tetap bisa membayar.
                try {
                    $wp = $this->webPayments->ensureQris($wp);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Checkout: QRIS gagal', [
                        'sales_order_id' => $so->id, 'error' => $e->getMessage(),
                    ]);
                    // Tanpa transfer bank yang siap, tak ada jalur bayar → lempar ulang
                    // supaya transaksi di-rollback (jangan tinggalkan SO & reservasi yatim).
                    if (! $setting->isConfigured()) {
                        throw $e;
                    }
                    $wp = $this->webPayments->switchToTransfer($wp);
                    $qrisNote = 'Pembayaran QRIS sedang tidak tersedia — silakan gunakan transfer bank di bawah.';
                }
            }

            $payload = $this->instructions($wp->fresh('salesOrder'), $setting);
            if ($qrisNote) {
                $payload['notice'] = $qrisNote;
            }

            return response()->json(['data' => $payload], 201);
            });
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e; // mis. PIN salah → tetap 422 dengan pesan field
        } catch (\Throwable $e) {
            // Semua jalur bayar gagal (mis. saldo QRIS habis DAN transfer belum lengkap).
            // Transaksi sudah di-rollback: tak ada SO/reservasi yatim. Beri pesan yang
            // bisa ditindaklanjuti pembeli, bukan error mentah 500.
            \Illuminate\Support\Facades\Log::error('Checkout toko online gagal', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Pembayaran sedang tidak tersedia. Pesananmu belum dibuat — '
                           . 'silakan coba lagi sebentar lagi atau hubungi kami via WhatsApp.',
            ], 503);
        }
    }

    /** Status pesanan by token publik. */
    public function show(string $token)
    {
        $wp = WebPayment::with('salesOrder')->where('public_token', $token)->first();
        if (! $wp) {
            return response()->json(['message' => 'Pesanan tidak ditemukan.'], 404);
        }

        // Midtrans memposting pembayaran lewat webhook-nya sendiri → cerminkan ke intent
        // supaya halaman status pembeli ikut berubah jadi "sudah dibayar".
        if ($wp->isMidtrans()) {
            $wp = $this->webPayments->syncMidtrans($wp);
        }

        return response()->json(['data' => $this->instructions($wp, PaymentSetting::singleton())]);
    }

    /**
     * Midtrans dipakai di etalase HANYA bila sudah LIVE (mode Production aktif). Selama
     * masih sandbox / menunggu approval, kunci sudah terisi tapi belum boleh dipakai
     * pelanggan → checkout memakai Transfer Bank + kode unik dulu. Begitu Production
     * dinyalakan di Pengaturan → Midtrans, checkout otomatis SWITCH ke Midtrans.
     */
    private function midtransReady(): bool
    {
        $m = \App\Models\MidtransSetting::singleton();

        return ! empty($m->server_key) && ! empty($m->client_key) && (bool) $m->is_production;
    }

    /** Metode checkout: Midtrans bila sudah live, selain itu Transfer Bank + kode unik. */
    private function resolveMethod(?string $requested, PaymentSetting $setting): string
    {
        if ($this->midtransReady()) {
            return WebPayment::METHOD_MIDTRANS;
        }
        if ($setting->isConfigured()) {
            return WebPayment::METHOD_TRANSFER; // transfer bank + kode unik (default pra-Midtrans)
        }
        if ($setting->qrisReady()) {
            return WebPayment::METHOD_QRIS;
        }

        return WebPayment::METHOD_TRANSFER;
    }

    /** Metode pembayaran yang tersedia — etalase menampilkan pilihan sesuai ini. */
    public function paymentMethods()
    {
        $setting = PaymentSetting::singleton();

        $methods = [];
        if ($setting->qrisReady()) {
            $methods[] = [
                'key'         => 'qris',
                'label'       => 'QRIS',
                'description' => 'Scan pakai m-banking atau e-wallet apa pun (BCA, BRI, GoPay, OVO, DANA, ShopeePay…). Nominal otomatis.',
                'recommended' => true,
            ];
        }
        if ($this->midtransReady()) {
            $methods[] = [
                'key'         => 'midtrans',
                'label'       => 'Virtual Account / Kartu (Midtrans)',
                'description' => 'Bayar lewat Virtual Account BCA/BNI/BRI/Mandiri/Permata, QRIS, atau kartu kredit. Pembayaran terverifikasi otomatis.',
                'recommended' => empty($methods),
            ];
        }
        // Transfer bank manual + kode unik tidak lagi ditawarkan ke pembeli —
        // digantikan Midtrans (Virtual Account) yang biayanya setara & otomatis.

        return response()->json(['data' => $methods]);
    }

    /**
     * Perbarui QR yang sudah kedaluwarsa (tombol "Buat ulang QR" di halaman pesanan).
     * Tiap pembuatan QR berbiaya, jadi hanya dilakukan bila QR-nya memang sudah mati.
     */
    public function refreshQris(string $token)
    {
        $wp = WebPayment::with('salesOrder')->where('public_token', $token)->first();
        if (! $wp) {
            return response()->json(['message' => 'Pesanan tidak ditemukan.'], 404);
        }
        if (! $wp->isQris() || ! $wp->isOpen()) {
            return response()->json(['message' => 'Pesanan ini tidak memakai QRIS aktif.'], 422);
        }

        try {
            $wp = $this->webPayments->ensureQris($wp);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'QRIS sedang tidak tersedia. Hubungi kami via WhatsApp.'], 503);
        }

        return response()->json(['data' => $this->instructions($wp->fresh('salesOrder'), PaymentSetting::singleton())]);
    }

    /** Pembeli menyatakan sudah transfer → mulai timer eskalasi. */
    public function claim(string $token)
    {
        $wp = WebPayment::where('public_token', $token)->first();
        if (! $wp) {
            return response()->json(['message' => 'Pesanan tidak ditemukan.'], 404);
        }
        $this->webPayments->markClaimed($wp);
        return response()->json(['data' => $this->instructions($wp->fresh('salesOrder'), PaymentSetting::singleton())]);
    }

    // ───────────────────────── helpers ─────────────────────────

    /** Susun item untuk cek ongkir (berat gram × qty). */
    private function rateItems(array $items): array
    {
        $ids = collect($items)->pluck('product_id')->map(fn ($v) => (int) $v)->unique();
        $weights = Product::whereIn('id', $ids)->pluck('weight_gram', 'id');

        return collect($items)->map(fn ($it) => [
            'name'     => 'Paket',
            'weight'   => max(1, (int) ($weights[(int) $it['product_id']] ?? 0)),
            'quantity' => max(1, (int) $it['qty']),
        ])->all();
    }

    /** Cari customer by no. HP (non-marketplace) atau buat baru; perbarui alamat. */
    private function resolveCustomer(array $c): Customer
    {
        $phone = trim((string) ($c['phone'] ?? ''));

        $customer = Customer::where('phone', $phone)
            ->where(fn ($q) => $q->whereNull('is_marketplace')->orWhere('is_marketplace', false))
            ->first();

        if (! $customer) {
            $customer = new Customer([
                'code'          => 'WEB-' . now()->format('ymdHis') . rand(10, 99),
                'customer_type' => 'regular',
                'is_active'     => true,
            ]);
        }

        $customer->name             = $c['name'];
        $customer->phone            = $phone;
        $customer->recipient_phone  = $phone;
        $customer->email            = $c['email'] ?? $customer->email;
        $customer->address          = $c['address'] ?? $customer->address;
        $customer->shipping_address = $c['address'] ?? $customer->shipping_address;
        $customer->postal_code      = $c['postal_code'] ?? $customer->postal_code;
        // Titik lokasi dari peta checkout — kurir instant menjemput ke koordinat ini.
        if (!empty($c['latitude']) && !empty($c['longitude'])) {
            $customer->latitude  = $c['latitude'];
            $customer->longitude = $c['longitude'];
        }
        if (! empty($c['destination_label'])) {
            $customer->city = $c['destination_label'];
        }
        // PIN akun toko online: pertama kali → set; nomor HP yg sudah punya PIN →
        // wajib cocok (jadi akun aman, PIN tak bisa ditimpa orang lain).
        $pin = (string) ($c['pin'] ?? '');
        if ($customer->exists && ! empty($customer->web_order_pin)) {
            if (! \Illuminate\Support\Facades\Hash::check($pin, $customer->web_order_pin)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'customer.pin' => 'PIN tidak sesuai akun untuk nomor HP ini. Pakai PIN yang sama seperti pesanan sebelumnya.',
                ]);
            }
        } elseif ($pin !== '') {
            $customer->web_order_pin = \Illuminate\Support\Facades\Hash::make($pin);
        }
        $customer->save();

        return $customer;
    }

    /** Apakah nomor HP ini sudah punya akun (PIN)? → checkout tahu "buat" vs "masukkan" PIN. */
    public function pinStatus(Request $request)
    {
        $data = $request->validate(['phone' => 'required|string|max:30']);

        $registered = Customer::where('phone', trim($data['phone']))
            ->where(fn ($q) => $q->whereNull('is_marketplace')->orWhere('is_marketplace', false))
            ->whereNotNull('web_order_pin')
            ->exists();

        return response()->json(['data' => ['registered' => $registered]]);
    }

    /**
     * Lihat daftar pesanan lintas-device via Nomor HP + PIN.
     * Rate-limited (route) untuk memperlambat tebakan PIN.
     */
    public function lookup(Request $request)
    {
        $data = $request->validate([
            'phone' => 'required|string|max:30',
            'pin'   => 'required|string|max:10',
        ]);

        $customer = Customer::where('phone', trim($data['phone']))
            ->where(fn ($q) => $q->whereNull('is_marketplace')->orWhere('is_marketplace', false))
            ->whereNotNull('web_order_pin')
            ->first();

        if (! $customer || ! \Illuminate\Support\Facades\Hash::check($data['pin'], (string) $customer->web_order_pin)) {
            return response()->json(['message' => 'Nomor HP atau PIN salah.'], 401);
        }

        $orders = WebPayment::whereHas('salesOrder', fn ($q) => $q->where('customer_id', $customer->id))
            ->with('salesOrder')
            ->latest('id')->limit(50)->get()
            ->map(fn ($wp) => [
                'token'        => $wp->public_token,
                'order_number' => $wp->salesOrder?->order_number,
                'date'         => optional($wp->created_at)->toIso8601String(),
                'grand_total'  => (float) ($wp->salesOrder?->grand_total ?? $wp->expected_amount),
                'status_label' => $wp->statusLabel()['label'],
                'paid'         => $wp->isConfirmed(),
            ])->values();

        return response()->json(['data' => ['name' => $customer->name, 'orders' => $orders]]);
    }

    /** Instruksi/status pembayaran untuk etalase (tanpa data internal akun kas). */
    private function instructions(WebPayment $wp, PaymentSetting $setting): array
    {
        $so = $wp->salesOrder;
        $paid = $wp->isConfirmed();

        // Tahap fulfillment untuk timeline pelacakan konsumen:
        //   1 Dipesan · 2 Dibayar · 3 Diproses · 4 Dikirim · 5 Selesai
        $stage = 1;
        $courier = null;
        $tracking = null;
        if ($paid) {
            $stage = 2;
            $ds = $so ? $so->getDeliveryStatus() : 'not_delivered';
            $stage = match ($ds) {
                'partial'   => 4,
                'delivered' => 5,
                default     => 3, // not_delivered / disiapkan
            };
            // Resi dari surat jalan terbaru yang sudah punya nomor lacak.
            if ($so) {
                $d = $so->deliveries()
                    ->whereNotNull('tracking_number')
                    ->where('tracking_number', '!=', '')
                    ->latest('id')->first();
                if ($d) {
                    $courier  = $d->courier_name ?: $d->shipping_courier_code;
                    $tracking = $d->tracking_number;
                    if ($stage < 4) $stage = 4; // ada resi → minimal "Dikirim"
                }
            }
        } elseif (! $wp->isOpen()) {
            $stage = 0; // kedaluwarsa/batal
        }

        return [
            'token'           => $wp->public_token,
            'order_number'    => $so?->order_number,
            'method'          => $wp->method,
            // QRIS: string QR untuk digambar di sisi pembeli + masa berlakunya.
            'qris_string'     => $wp->isQris() ? $wp->qris_string : null,
            'qris_expires_at' => $wp->isQris() ? optional($wp->qris_expires_at)->toIso8601String() : null,
            // Midtrans: tautan halaman pembayaran (Snap) milik pesanan ini.
            'pay_url'         => $wp->isMidtrans() ? $this->webPayments->midtransPayUrl($wp) : null,
            // Faktur web (struk) — tersedia setelah pembayaran diterima.
            'faktur_url'      => $paid ? url('/faktur/' . $wp->public_token) : null,
            'status'          => $wp->status,
            'status_label'    => $wp->statusLabel()['label'],
            'paid'            => $paid,
            'open'            => $wp->isOpen(),
            'stage'           => $stage,
            'courier'         => $courier,
            'tracking_number' => $tracking,
            'grand_total'     => (float) ($so?->grand_total ?? $wp->expected_amount),
            'unique_code'     => (int) $wp->unique_code,
            'expected_amount' => (float) $wp->expected_amount,
            'expires_at'      => optional($wp->expires_at)->toIso8601String(),
            'delivery_method' => $so?->delivery_method,
            'bank_accounts'   => collect($setting->accounts())->map(fn ($a) => [
                'bank_name'      => $a['bank_name'],
                'account_number' => $a['account_number'],
                'account_holder' => $a['account_holder'],
                'auto'           => $a['confirmation'] === 'email',
            ])->values(),
        ];
    }
}
