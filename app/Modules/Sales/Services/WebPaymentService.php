<?php

namespace App\Modules\Sales\Services;

use App\Core\Inventory\BundleComponent;
use App\Core\Inventory\Product;
use App\Core\Inventory\ProductBundle;
use App\Core\Inventory\StockReservation;
use App\Models\PaymentSetting;
use App\Models\WebPayment;
use App\Modules\Sales\Models\SalesOrder;
use Exception;
use Illuminate\Support\Facades\DB;

/**
 * Pembayaran "Transfer Bank + Kode Unik" untuk toko online (pengganti Midtrans).
 *
 * Alur:
 *  1. createForOrder() — pasang kode unik ke SO agar total transfer UNIK, buat intent.
 *  2. markClaimed()    — pembeli tap "Saya sudah transfer" → mulai timer eskalasi.
 *  3. confirm()        — nominal cocok (email/moota) ATAU disetujui admin (telegram/manual)
 *                        → posting CustomerPayment advance → SO masuk Pemrosesan Pesanan.
 *  4. expire()         — lewat kedaluwarsa / dibatalkan → void SO + lepas reservasi stok.
 */
class WebPaymentService
{
    public function __construct(protected CustomerPaymentService $paymentService) {}

    /**
     * Pasang kode unik ke SO (confirmed/reserved) & buat intent pembayaran.
     * Idempoten: bila intent yang masih terbuka sudah ada, kembalikan apa adanya.
     */
    public function createForOrder(int $salesOrderId): WebPayment
    {
        return DB::transaction(function () use ($salesOrderId) {
            $so = SalesOrder::lockForUpdate()->findOrFail($salesOrderId);

            $existing = WebPayment::where('sales_order_id', $so->id)->open()->first();
            if ($existing) {
                return $existing;
            }

            $setting = PaymentSetting::singleton();

            // Base = grand total tanpa kode unik (aman bila dipanggil ulang).
            $base = round((float) $so->grand_total + (int) ($so->unique_code ?? 0));
            $code = $this->allocateUniqueCode($base, $setting);

            $so->unique_code = $code;
            $so->grand_total = $base - $code;
            $so->save();

            return WebPayment::create([
                'sales_order_id'  => $so->id,
                'customer_id'     => $so->customer_id,
                'public_token'    => (string) \Illuminate\Support\Str::uuid(),
                'unique_code'     => $code,
                'expected_amount' => $so->grand_total,
                'status'          => WebPayment::STATUS_AWAITING,
                'expires_at'      => now()->addHours(max(1, (int) $setting->expiry_hours)),
            ]);
        });
    }

    /**
     * Pilih kode unik dalam rentang setting sehingga nominal transfer (base − kode)
     * TIDAK bertabrakan dengan intent lain yang masih menunggu → pencocokan tak ambigu.
     */
    private function allocateUniqueCode(float $base, PaymentSetting $setting): int
    {
        $min = max(1, (int) ($setting->unique_code_min ?: 1));
        $max = min(999, (int) ($setting->unique_code_max ?: 999));
        if ($max < $min) {
            [$min, $max] = [1, 999];
        }

        $takenAmounts = WebPayment::open()
            ->pluck('expected_amount')
            ->map(fn ($a) => (float) $a)
            ->flip();

        $codes = range($min, $max);
        shuffle($codes);

        foreach ($codes as $code) {
            $amount = $base - $code;
            if ($amount > 0 && ! $takenAmounts->has((float) $amount)) {
                return $code;
            }
        }

        // Semua kombinasi bertabrakan (sangat jarang) → pakai batas bawah.
        return $min;
    }

    /** Pembeli menyatakan sudah transfer → mulai timer eskalasi Telegram. */
    public function markClaimed(WebPayment $wp): WebPayment
    {
        if ($wp->status === WebPayment::STATUS_AWAITING) {
            $wp->update([
                'status'           => WebPayment::STATUS_CLAIMED,
                'buyer_claimed_at' => now(),
            ]);
        }

        return $wp->refresh();
    }

    /**
     * Konfirmasi pembayaran (idempoten, anti-dobel via lock + guard status).
     * Posting CustomerPayment advance thd SO → paid_amount naik, SalesAdvanceObserver
     * memicu produksi preorder & SO pindah ke antrian Pemrosesan Pesanan.
     *
     * @param string   $via           email|moota|telegram|manual
     * @param int|null $cashAccountId akun kas ERP tujuan (mis. BRI vs BCA); null = resolusi otomatis.
     */
    public function confirm(int $webPaymentId, string $via, ?string $reference = null, ?int $userId = null, ?int $cashAccountId = null): WebPayment
    {
        return DB::transaction(function () use ($webPaymentId, $via, $reference, $userId, $cashAccountId) {
            $wp = WebPayment::lockForUpdate()->findOrFail($webPaymentId);

            // Anti-dobel: sudah lunas / batal → kembalikan apa adanya.
            if (! $wp->isOpen()) {
                return $wp;
            }

            $setting = PaymentSetting::singleton();
            // Akun kas: eksplisit (tombol Telegram/manual) → rekening email (auto BRI) → default.
            $cashId = $cashAccountId
                ?? (in_array($via, ['email', 'moota'], true) ? $setting->emailAccountCashId() : null)
                ?? $setting->defaultCashId();
            if (empty($cashId)) {
                throw new Exception('Akun kas pembayaran belum diatur di Settings → Integrasi → Pembayaran.');
            }

            $so = SalesOrder::findOrFail($wp->sales_order_id);

            $payment = $this->paymentService->create([
                'customer_id'     => $so->customer_id,
                'date'            => now()->toDateString(),
                'cash_account_id' => $cashId,
                'amount'          => $wp->expected_amount,
                'payment_type'    => 'advance',
                'sales_order_id'  => $so->id,
                'notes'           => "Transfer bank toko online (kode unik {$wp->unique_code}) — konfirmasi {$via}"
                                     . ($reference ? " · ref {$reference}" : ''),
            ]);

            $this->paymentService->post($payment->id, null, [], [$so->id], false);

            $wp->update([
                'status'              => WebPayment::STATUS_CONFIRMED,
                'matched_at'          => $wp->matched_at ?? now(),
                'confirmed_at'        => now(),
                'confirmed_via'       => $via,
                'confirmed_by'        => $userId,
                'matched_reference'   => $reference,
                'customer_payment_id' => $payment->id,
            ]);

            return $wp->refresh();
        });
    }

    /**
     * Batalkan intent yang belum lunas → void SO + lepas reservasi stok.
     * Aman: order belum-bayar tak punya Invoice/SJ/advance (preorder terpicu saat DP).
     *
     * @param string $status expired|cancelled
     */
    public function expire(int $webPaymentId, string $status = WebPayment::STATUS_EXPIRED, ?string $reason = null): WebPayment
    {
        return DB::transaction(function () use ($webPaymentId, $status, $reason) {
            $wp = WebPayment::lockForUpdate()->findOrFail($webPaymentId);

            if (! $wp->isOpen()) {
                return $wp;
            }

            $so = SalesOrder::lockForUpdate()->find($wp->sales_order_id);
            if ($so && $so->status === 'confirmed') {
                StockReservation::where('sales_order_id', $so->id)
                    ->update(['status' => 'cancelled']);
                $this->flagJubelioStockPending($so->id);

                $so->status = 'void';
                $so->save();
            }

            $wp->update([
                'status' => $status === WebPayment::STATUS_CANCELLED
                    ? WebPayment::STATUS_CANCELLED
                    : WebPayment::STATUS_EXPIRED,
                'notes'  => trim((string) ($wp->notes ?? '') . "\n" . ($reason ?: 'Auto-batal: belum dibayar')),
            ]);

            return $wp->refresh();
        });
    }

    /**
     * Tandai produk yang reservasinya dilepas agar didorong ulang ke Jubelio
     * (mass-update melewati StockReservationObserver). Mirror SalesOrderController.
     */
    private function flagJubelioStockPending(int $salesOrderId): void
    {
        $productIds = StockReservation::where('sales_order_id', $salesOrderId)
            ->pluck('product_id')->unique()->all();
        if (empty($productIds)) {
            return;
        }

        Product::whereIn('id', $productIds)
            ->where('sync_to_jubelio', true)
            ->update(['jubelio_sync_pending' => true]);

        $bundleIds = BundleComponent::whereIn('component_product_id', $productIds)
            ->pluck('bundle_product_id');
        if ($bundleIds->isEmpty()) {
            $bundleIds = ProductBundle::whereIn('component_product_id', $productIds)
                ->pluck('bundle_product_id');
        }
        if ($bundleIds->isNotEmpty()) {
            Product::whereIn('id', $bundleIds)
                ->where('sync_to_jubelio', true)
                ->update(['jubelio_sync_pending' => true]);
        }
    }
}
