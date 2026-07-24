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
            // Pulihkan kunci: bila operator ini punya transaksi QRIS berjalan yang belum
            // dibayar (mis. setelah popup Snap me-redirect / halaman ter-refresh), kasir
            // dikunci lagi ke faktur itu agar stok tak jadi faktur "hantu".
            'pendingSale'     => $this->pendingQrisSale(),
            // QRIS hanya bisa dipakai bila Midtrans sudah terkonfigurasi. Kalau belum,
            // tombol QRIS disembunyikan supaya kasir tidak membuat invoice nyangkut (posted,
            // tak pernah settle → BELUM LUNAS selamanya).
            'qrisEnabled'     => $this->qrisAvailable(),
        ]);
    }

    /**
     * Transaksi QRIS Kasir milik operator saat ini yang BELUM dibayar (untuk memulihkan
     * kunci kasir setelah redirect/refresh). null bila tidak ada.
     */
    private function pendingQrisSale(): ?array
    {
        $trx = \App\Models\MidtransTransaction::where('created_by', auth()->id())
            ->where('channel', 'qris')
            ->where('source', 'instore')
            ->where('status', 'pending')
            ->where('expired_at', '>', now())
            ->whereNotNull('sales_invoice_id')
            ->latest('id')->first();

        if (! $trx) {
            return null;
        }

        $inv = \App\Models\SalesInvoice::with('items.product')->find($trx->sales_invoice_id);
        if (! $inv || $inv->sales_order_id || round((float) $inv->paid_amount) > 0 || round((float) $inv->remaining_amount) <= 0) {
            return null;
        }

        $items = $inv->items->map(fn ($it) => [
            'name'       => $it->description ?: ($it->product->name ?? 'Item'),
            'sku'        => $it->product->sku ?? null,
            'qty'        => (float) $it->qty,
            'unit_price' => (float) $it->unit_price,
            'line_total' => (float) $it->subtotal,
        ])->values()->all();

        return [
            'invoice_id'  => $inv->id,
            'invoice_no'  => $inv->invoice_number,
            'grand_total' => (float) $inv->grand_total,
            'subtotal'    => (float) $inv->subtotal,
            'items'       => $items,
            'qris_url'    => route('sales.midtrans.admin.qris', $inv->id),
            'print_url'   => route('sales.invoices.print', $inv->id),
        ];
    }

    /**
     * QRIS di Kasir hanya aktif bila di-ON-kan eksplisit (Pengaturan Midtrans) DAN kredensial
     * Midtrans terisi. Default OFF: mencegah operator salah klik QRIS lalu membuat invoice
     * ter-post tanpa pembayaran selama Midtrans belum live.
     */
    private function qrisAvailable(): bool
    {
        $s = \App\Models\MidtransSetting::singleton();
        return (bool) $s->pos_qris_enabled && filled($s->server_key) && filled($s->client_key);
    }

    /** Live search produk (kiri layar). Harga = display_price (ProductPrice), stok = available (ledger − reservasi; bundle dari komponen). */
    public function search(Request $request, InventoryEngine $inventory): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        $whId = (int) (Warehouse::orderBy('id')->value('id') ?? 1);

        $rows = Product::query()
            ->where('is_active', true)
            ->where('is_sellable', true) // Kasir hanya tampilkan produk yang dijual.
            // Guard: bundle tanpa komponen tidak boleh dijual (fulfillment/HPP gagal tanpa komponen).
            ->where(function ($w) {
                $w->where('sale_type', '!=', 'bundle')
                  ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('bundle_components')
                        ->whereColumn('bundle_components.bundle_product_id', 'products.id'))
                  ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('product_bundles')
                        ->whereColumn('product_bundles.bundle_product_id', 'products.id'));
            })
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

        // Guard QRIS sebelum invoice dibuat: kalau Midtrans belum terkonfigurasi, settlement
        // tak pernah datang → invoice akan nyangkut BELUM LUNAS. Tolak di sini supaya tidak ada
        // invoice sampah yang terlanjur di-post.
        if ($data['payment_method'] === 'qris' && !$this->qrisAvailable()) {
            return response()->json([
                'message' => 'Pembayaran QRIS belum aktif (Midtrans belum terkonfigurasi). Gunakan Bayar Cash.',
            ], 422);
        }

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

    /**
     * Batalkan transaksi QRIS Kasir yang BELUM dibayar: void faktur (reverse stok +
     * void SEMUA Surat Jalan + void jurnal) dan tutup transaksi Midtrans pending.
     * Melindungi stok dari faktur "hantu" saat operator keluar dari QRIS tanpa membayar.
     */
    public function voidPending(Request $request, \App\Modules\Sales\Services\SalesInvoiceService $invoices): JsonResponse
    {
        $data = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:sales_invoices,id'],
        ]);

        $invoice = \App\Models\SalesInvoice::findOrFail($data['invoice_id']);

        // Jaring pengaman: hanya transaksi POS (tanpa SO) yang belum ada pembayaran.
        if ($invoice->sales_order_id) {
            return response()->json(['message' => 'Faktur terhubung Sales Order — tidak bisa dibatalkan dari Kasir.'], 422);
        }
        if (round((float) $invoice->paid_amount) > 0) {
            return response()->json(['message' => 'Faktur sudah menerima pembayaran — tidak bisa dibatalkan.'], 422);
        }

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($invoice, $invoices) {
                // Tutup transaksi Midtrans pending agar pembayaran telat tidak nyangkut ke faktur ter-void.
                \App\Models\MidtransTransaction::where('sales_invoice_id', $invoice->id)
                    ->where('status', 'pending')
                    ->update(['status' => 'cancel']);

                $invoices->voidPosted($invoice); // reverse stok + void semua SJ + void jurnal
            });
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Gagal membatalkan: ' . $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }
}
