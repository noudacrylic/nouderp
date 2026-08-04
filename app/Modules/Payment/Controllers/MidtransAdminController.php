<?php

namespace App\Modules\Payment\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MidtransSetting;
use App\Models\MidtransTransaction;
use App\Models\SalesInvoice;
use App\Modules\Payment\Services\MidtransService;
use App\Modules\Payment\Services\PaymentLinkService;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class MidtransAdminController extends Controller
{
    public function __construct(
        protected PaymentLinkService $links,
        protected MidtransService $midtrans,
    ) {
    }

    /**
     * Generate / re-use payment link untuk invoice.
     * GET /erp/sales/payment/invoice/{invoice}/midtrans-link
     */
    public function generateLink(int $invoice): JsonResponse
    {
        $inv = SalesInvoice::with('customer')->findOrFail($invoice);

        if ($this->isDead($inv)) {
            return response()->json(['error' => 'Invoice ini sudah di-void — tautan pembayaran tidak bisa dibuat.'], 422);
        }
        if ($inv->remaining_amount <= 0) {
            return response()->json(['error' => 'Invoice ini sudah lunas.'], 422);
        }

        $trx = $this->links->getOrCreateForInvoice($inv, auth()->id());

        return response()->json([
            'order_id' => $trx->order_id,
            'token' => $trx->link_token,
            'url' => $this->links->publicUrl($trx),
            'expires_at' => $trx->expired_at?->format('Y-m-d H:i'),
            'wa_text' => $this->links->waText(
                $trx,
                $inv->customer?->name ?? 'Customer',
                $inv->invoice_number,
                (int) round($inv->remaining_amount),
            ),
        ]);
    }

    /**
     * Generate / re-use link DP (uang muka) untuk Sales Order.
     * GET /erp/sales/payment/sales-order/{so}/midtrans-link
     */
    public function generateSoLink(Request $request, int $so): JsonResponse
    {
        $order = SalesOrder::with('customer')->findOrFail($so);

        // Draft ikut diizinkan: kebijakan penjualan adalah stok TIDAK ditahan sampai ada
        // DP/pembayaran. SO dibiarkan draft (tanpa reservasi) sambil link dikirim ke
        // pembeli; begitu pembayaran masuk, MidtransService memposting SO otomatis.
        if (! in_array($order->status, ['draft', 'confirmed'], true)) {
            return response()->json(['error' => 'Link pembayaran hanya untuk Sales Order draft atau confirmed.'], 422);
        }
        if ($order->isFullyInvoiced()) {
            return response()->json(['error' => 'Sales Order sudah full invoiced.'], 422);
        }

        $remaining = (int) round($order->grand_total - $order->paid_amount);
        if ($remaining <= 0) {
            return response()->json(['error' => 'Sales Order sudah lunas.'], 422);
        }

        // Batas DP diambil dari kesepakatan yang tercatat di SO (bawaan 50% bila tidak ada).
        // Diubahnya di halaman SO — bukan di modal ini — supaya tidak ada dua tempat yang
        // bisa berbeda isinya untuk pesanan yang sama.
        $minDp = $order->minDpAmount();

        $trx = $this->links->getOrCreateForSalesOrder($order, auth()->id(), $minDp);

        return response()->json([
            'order_id' => $trx->order_id,
            'token' => $trx->link_token,
            'url' => $this->links->publicUrl($trx),
            'expires_at' => $trx->expired_at?->format('Y-m-d H:i'),
            'min_dp' => $minDp,
            'min_dp_percent' => $order->minDpPercent(),
            'min_dp_custom' => $order->hasCustomMinDp(),
            'so_url' => route('sales.orders.show', $order->id),
            'remaining' => $remaining,
            'wa_text' => $this->links->waTextSo(
                $trx,
                $order->customer?->name ?? 'Customer',
                $order->order_number,
                $remaining,
            ),
        ]);
    }

    /**
     * Tampilkan modal QRIS dinamis (di-render via Blade fragment, return JSON utk AJAX).
     * GET /erp/sales/payment/invoice/{invoice}/midtrans-qris
     */
    public function showQris(int $invoice): JsonResponse
    {
        $inv = SalesInvoice::findOrFail($invoice);

        if ($this->isDead($inv)) {
            return response()->json(['error' => 'Invoice ini sudah di-void — pembayaran QRIS tidak bisa dibuat.'], 422);
        }
        if ($inv->remaining_amount <= 0) {
            return response()->json(['error' => 'Invoice ini sudah lunas.'], 422);
        }

        try {
            $trx = $this->midtrans->createQrisInstore($inv, auth()->id());
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json([
            'trx_id' => $trx->id,
            'order_id' => $trx->order_id,
            'snap_token' => $trx->snap_token,
            'client_key' => MidtransSetting::resolvedClientKey(),
            'is_production' => MidtransSetting::resolvedIsProduction(),
            'amount' => (int) $trx->gross_amount,
            'expires_at' => $trx->expired_at?->toIso8601String(),
            'poll_url' => route('sales.midtrans.admin.status', $trx->id),
        ]);
    }

    /**
     * QRIS in-store langsung untuk SALES ORDER (DP/pelunasan di kasir, sebelum invoice).
     * GET /erp/sales/payment/sales-order/{so}/midtrans-qris
     */
    public function showSoQris(int $so): JsonResponse
    {
        $order = SalesOrder::findOrFail($so);

        // Draft ikut diizinkan — lihat catatan di generateSoLink(). Settlement QRIS
        // menempuh jalur webhook yang sama, jadi SO draft juga di-post otomatis.
        if (! in_array($order->status, ['draft', 'confirmed'], true)) {
            return response()->json(['error' => 'Pembayaran QRIS hanya untuk Sales Order draft atau confirmed.'], 422);
        }
        if ((float) $order->grand_total - (float) $order->paid_amount <= 0) {
            return response()->json(['error' => 'Sales Order ini sudah lunas.'], 422);
        }

        try {
            $trx = $this->midtrans->createQrisInstoreForSO($order, auth()->id());
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json([
            'trx_id' => $trx->id,
            'order_id' => $trx->order_id,
            'snap_token' => $trx->snap_token,
            'client_key' => MidtransSetting::resolvedClientKey(),
            'is_production' => MidtransSetting::resolvedIsProduction(),
            'amount' => (int) $trx->gross_amount,
            'expires_at' => $trx->expired_at?->toIso8601String(),
            'poll_url' => route('sales.midtrans.admin.status', $trx->id),
        ]);
    }

    /** Invoice yang sudah dibatalkan tidak boleh lagi menerbitkan tautan/QRIS baru. */
    private function isDead(SalesInvoice $invoice): bool
    {
        $status = $invoice->status instanceof \BackedEnum ? $invoice->status->value : $invoice->status;

        return in_array(strtolower((string) $status), ['void', 'cancelled', 'canceled'], true);
    }

    /**
     * AJAX polling untuk cek status (dipakai QRIS modal).
     * GET /erp/sales/payment/midtrans/{trx}/status
     */
    public function pollStatus(Request $request, int $trx): JsonResponse
    {
        $transaction = MidtransTransaction::findOrFail($trx);

        // Kalau status masih pending, refresh dari Midtrans (jaga-jaga kalau webhook delayed).
        if ($transaction->status === 'pending' && !$transaction->isExpired()) {
            try {
                $transaction = $this->midtrans->refreshStatus($transaction);
            } catch (Throwable) {
                // ignore — biarkan UI tetap polling
            }
        }

        return response()->json([
            'status' => $transaction->status,
            'settled_at' => $transaction->settled_at?->toIso8601String(),
            'customer_payment_id' => $transaction->customer_payment_id,
            'redirect_url' => $transaction->customer_payment_id
                ? url('/erp/sales/payment/' . $transaction->customer_payment_id)
                : null,
        ]);
    }
}
