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

    /** Cari wilayah tujuan → destination_id RajaOngkir. ?q= (min 3 huruf). */
    public function areas(Request $request)
    {
        $q = trim((string) $request->get('q'));
        if (mb_strlen($q) < 3) {
            return response()->json(['data' => []]);
        }

        $provider = $this->shipping->provider('rajaongkir');
        $res = $provider ? $provider->searchAreas($q) : ['areas' => []];

        return response()->json(['data' => $res['areas'] ?? []]);
    }

    /** Cek ongkir: body { destination_area_id, items:[{product_id, qty}] }. */
    public function rates(Request $request)
    {
        $data = $request->validate([
            'destination_area_id' => 'required|string|max:50',
            'items'               => 'required|array|min:1',
            'items.*.product_id'  => 'required|integer',
            'items.*.qty'         => 'required|numeric|min:1',
        ]);

        $res = $this->shipping->rates([
            'provider'            => 'rajaongkir',
            'destination_area_id' => $data['destination_area_id'],
            'items'               => $this->rateItems($data['items']),
        ]);

        return response()->json(['data' => $res['rates'], 'errors' => $res['errors']]);
    }

    /** Buat pesanan + instruksi pembayaran. */
    public function checkout(Request $request)
    {
        $setting = PaymentSetting::singleton();
        if (! $setting->isConfigured()) {
            return response()->json(['message' => 'Pembayaran belum diaktifkan.'], 503);
        }

        $data = $request->validate([
            'customer.name'                => 'required|string|max:200',
            'customer.phone'               => 'required|string|max:30',
            'customer.email'               => 'nullable|email|max:200',
            'customer.address'             => 'nullable|string|max:2000',
            'customer.postal_code'         => 'nullable|string|max:10',
            'customer.destination_label'   => 'nullable|string|max:255',
            'items'                        => 'required|array|min:1',
            'items.*.product_id'           => 'required|integer',
            'items.*.qty'                  => 'required|numeric|min:1',
            'delivery_method'              => 'required|in:kurir,ambil_toko',
            'shipping.courier_code'        => 'nullable|string|max:50',
            'shipping.service_name'        => 'nullable|string|max:150',
            'shipping.price'               => 'nullable|numeric|min:0',
        ]);

        if ($data['delivery_method'] === 'kurir' && ! isset($data['shipping']['price'])) {
            return response()->json(['message' => 'Ongkir belum dipilih.'], 422);
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

        return DB::transaction(function () use ($data, $lineItems, $setting) {
            $customer = $this->resolveCustomer($data['customer']);

            $dto = [
                'customer_id'     => $customer->id,
                'delivery_method' => $data['delivery_method'],
                'order_date'      => now()->toDateString(),
                'notes'           => 'Pesanan toko online (noudakrilik.com)',
                'items'           => $lineItems,
            ];
            if ($data['delivery_method'] === 'kurir') {
                $dto['shipping_gross'] = (float) $data['shipping']['price'];
                $dto['courier_name']   = $data['shipping']['service_name'] ?? 'Kurir';
            }

            $so = $this->orders->createDraftFromData($dto);
            $this->orders->confirm($so->id);              // reservasi stok
            $wp = $this->webPayments->createForOrder($so->id); // kode unik + intent

            return response()->json(['data' => $this->instructions($wp->fresh('salesOrder'), $setting)], 201);
        });
    }

    /** Status pesanan by token publik. */
    public function show(string $token)
    {
        $wp = WebPayment::with('salesOrder')->where('public_token', $token)->first();
        if (! $wp) {
            return response()->json(['message' => 'Pesanan tidak ditemukan.'], 404);
        }
        return response()->json(['data' => $this->instructions($wp, PaymentSetting::singleton())]);
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
        if (! empty($c['destination_label'])) {
            $customer->city = $c['destination_label'];
        }
        $customer->save();

        return $customer;
    }

    /** Instruksi/status pembayaran untuk etalase (tanpa data internal akun kas). */
    private function instructions(WebPayment $wp, PaymentSetting $setting): array
    {
        $so = $wp->salesOrder;

        return [
            'token'           => $wp->public_token,
            'order_number'    => $so?->order_number,
            'status'          => $wp->status,
            'status_label'    => $wp->statusLabel()['label'],
            'paid'            => $wp->isConfirmed(),
            'open'            => $wp->isOpen(),
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
