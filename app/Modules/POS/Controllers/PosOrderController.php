<?php

namespace App\Modules\POS\Controllers;

use App\Core\Accounting\Account;
use App\Core\Inventory\BundleComponent;
use App\Core\Inventory\InventoryEngine;
use App\Core\Inventory\Product;
use App\Core\Inventory\ProductBundle;
use App\Core\Inventory\Warehouse;
use App\Enums\AccountTypeEnum;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Modules\POS\Services\PosSaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kasir POS — layar buat transaksi langsung (Invoice + Bayar, tanpa Sales Order).
 */
class PosOrderController extends Controller
{
    public function kasir(PosSaleService $svc)
    {
        $cashAccounts = Account::where('type', AccountTypeEnum::ASSET)
            ->whereIn('account_category', ['cash', 'cash_equivalent'])
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $defaultCustomer = $svc->resolveWalkInCustomer();

        $customers = Customer::where('is_marketplace', false)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('erp.pos.kasir.index', [
            'cashAccounts'    => $cashAccounts,
            'defaultCustomer' => $defaultCustomer,
            'customers'       => $customers,
        ]);
    }

    /** Live search produk (kiri layar). Harga = display_price (ProductPrice), stok = available (ledger − reservasi; bundle dari komponen). */
    public function search(Request $request, InventoryEngine $inventory): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        $whId = (int) (Warehouse::orderBy('id')->value('id') ?? 1);

        $rows = Product::query()
            ->where('is_active', true)
            ->where('is_sellable', true) // Kasir hanya tampilkan produk yang dijual.
            ->when($q !== '', fn ($query) => $query->where(fn ($w) =>
                $w->where('name', 'like', "%{$q}%")->orWhere('sku', 'like', "%{$q}%")))
            ->orderBy('name')
            ->limit(40)
            ->get();

        // Promo per produk (auto-apply, untuk harga coret). Batch sekali, hindari N+1.
        $promoItems = $rows->map(fn ($p) => [
            'product_id' => $p->id, 'qty' => 1, 'unit_price' => (float) $p->display_price,
        ])->all();
        $promoMap = app(\App\Modules\Sales\Services\PromotionService::class)
            ->resolveItemDiscounts($promoItems);

        return response()->json($rows->map(function ($p) use ($inventory, $whId, $promoMap) {
            $stock = $this->availableStockFor($p, $whId, $inventory);
            $price = (float) $p->display_price;

            $promo = null;
            if (isset($promoMap[$p->id])) {
                $m = $promoMap[$p->id];
                $promo = [
                    'discount_type'  => $m['discount_type'],
                    'discount_value' => (float) $m['discount_value'], // per-unit utk nominal
                    'final_price'    => max(0, round($price - (float) $m['discount_amount'], 2)),
                    'name'           => $m['promotion_name'],
                ];
            }

            return [
                'id'      => $p->id,
                'sku'     => $p->sku,
                'name'    => $p->name,
                'price'   => $price,
                'stock'   => $stock,            // null = tidak di-track (jasa/non-stok)
                'tracked' => $stock !== null,
                'promo'   => $promo,
            ];
        }));
    }

    /**
     * Available stock = ledger balance − reservasi aktif (InventoryEngine::availableStock).
     * Bundle: min( floor(available_komponen / qty_per_bundle) ) — jadi bundle pun punya stok.
     * Jasa/non-stok: null (tidak punya konsep stok).
     */
    private function availableStockFor(Product $p, int $whId, InventoryEngine $inventory): ?float
    {
        if (in_array($p->sale_type, ['service', 'non_stock'], true)) {
            return null;
        }

        if ($p->sale_type === 'bundle') {
            $components = BundleComponent::where('bundle_product_id', $p->id)->get();
            $field = 'qty';
            if ($components->isEmpty()) {
                $components = ProductBundle::where('bundle_product_id', $p->id)->get();
                $field = 'qty_required';
            }
            if ($components->isEmpty()) return 0.0;

            $min = INF;
            foreach ($components as $c) {
                $req = (float) ($c->{$field} ?? 1);
                if ($req <= 0) { $min = 0; break; }
                $avail = $inventory->availableStock($c->component_product_id, $whId);
                $min = min($min, floor($avail / $req));
            }
            return $min === INF ? 0.0 : max(0.0, (float) $min);
        }

        // ready / preorder
        return (float) $inventory->availableStock($p->id, $whId);
    }

    /** Checkout: bikin invoice (no-SO) + post, lalu bayar (cash → catat kas; qris → kembalikan invoice id). */
    public function checkout(Request $request, PosSaleService $svc): JsonResponse
    {
        $data = $request->validate([
            'customer_id'           => ['nullable', 'integer', 'exists:customers,id'],
            'payment_method'        => ['required', 'in:cash,qris'],
            'cash_account_id'       => ['nullable', 'integer', 'exists:accounts,id'],
            'amount_tendered'       => ['nullable'],
            'global_discount_type'  => ['nullable', 'in:nominal,percent'],
            'global_discount_value' => ['nullable'],
            'ppn_percent'           => ['nullable'],
            'notes'                 => ['nullable', 'string', 'max:2000'],
            'items'                 => ['required', 'array', 'min:1'],
            'items.*.product_id'    => ['required', 'integer', 'exists:products,id'],
            'items.*.qty'           => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_price'    => ['required'],
            'items.*.discount_type' => ['nullable', 'in:nominal,percent'],
            'items.*.discount_value' => ['nullable'],
            'items.*.description'   => ['nullable', 'string', 'max:255'],
        ]);

        $customerId = $data['customer_id'] ?? $svc->resolveWalkInCustomer()->id;

        $items = array_map(fn ($it) => [
            'product_id'     => (int) $it['product_id'],
            'qty'            => (float) $it['qty'],
            'unit_price'     => (float) clean_number($it['unit_price']),
            'discount_type'  => $it['discount_type'] ?? 'nominal',
            'discount_value' => (float) clean_number($it['discount_value'] ?? 0),
            'description'    => $it['description'] ?? '',
        ], $data['items']);

        try {
            $invoice = $svc->createSale([
                'customer_id'           => (int) $customerId,
                'global_discount_type'  => $data['global_discount_type'] ?? 'nominal',
                'global_discount_value' => (float) clean_number($data['global_discount_value'] ?? 0),
                'ppn_percent'           => (float) clean_number($data['ppn_percent'] ?? 0),
                'notes'                 => $data['notes'] ?? null,
                'items'                 => $items,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $grand = (float) $invoice->grand_total;

        if ($data['payment_method'] === 'cash') {
            if (empty($data['cash_account_id'])) {
                return response()->json(['message' => 'Pilih akun kas dulu untuk pembayaran tunai.'], 422);
            }
            $tendered = (float) clean_number($data['amount_tendered'] ?? 0);
            if ($tendered + 0.01 < $grand) {
                return response()->json(['message' => 'Uang diterima kurang dari total. Invoice ' . $invoice->invoice_number . ' sudah terbuat — selesaikan via Faktur.'], 422);
            }

            try {
                $svc->recordCashPayment($invoice, (int) $data['cash_account_id']);
            } catch (\Throwable $e) {
                return response()->json(['message' => 'Invoice terbuat tapi gagal catat pembayaran: ' . $e->getMessage()], 422);
            }

            return response()->json([
                'ok'          => true,
                'method'      => 'cash',
                'invoice_id'  => $invoice->id,
                'invoice_no'  => $invoice->invoice_number,
                'grand_total' => $grand,
                'change'      => round($tendered - $grand, 2),
                'print_url'   => route('sales.invoices.print', $invoice->id),
            ]);
        }

        // QRIS: invoice sudah posted (AR). Panel QRIS di frontend pakai endpoint Midtrans existing.
        return response()->json([
            'ok'          => true,
            'method'      => 'qris',
            'invoice_id'  => $invoice->id,
            'invoice_no'  => $invoice->invoice_number,
            'grand_total' => $grand,
            'qris_url'    => route('sales.midtrans.admin.qris', $invoice->id),
            'status_base' => url('/erp/sales/payment/midtrans'),
            'print_url'   => route('sales.invoices.print', $invoice->id),
        ]);
    }
}
