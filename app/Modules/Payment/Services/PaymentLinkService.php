<?php

namespace App\Modules\Payment\Services;

use App\Models\MidtransSetting;
use App\Models\MidtransTransaction;
use App\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Str;

class PaymentLinkService
{
    /**
     * Cari atau buat MidtransTransaction berstatus link+pending utk invoice.
     *
     * Aman dipanggil berulang: jika sudah ada link aktif (status=pending, belum expired,
     * source=link) → return record yang sama (idempotent), supaya admin yg klik 2× tidak
     * generate banyak token sampah.
     */
    public function getOrCreateForInvoice(SalesInvoice $invoice, ?int $userId = null): MidtransTransaction
    {
        $existing = MidtransTransaction::where('sales_invoice_id', $invoice->id)
            ->where('source', 'link')
            ->where('status', 'pending')
            ->where('expired_at', '>', now())
            ->latest()
            ->first();

        if ($existing) {
            return $existing;
        }

        $expiryDays = (int) (MidtransSetting::singleton()->link_expiry_days ?: 7);
        $base = (int) round($invoice->remaining_amount);

        return MidtransTransaction::create([
            'order_id' => $this->makeOrderId('LINK'),
            'sales_invoice_id' => $invoice->id,
            'sales_order_id' => $invoice->sales_order_id,
            'customer_id' => $invoice->customer_id,
            'source' => 'link',
            'link_token' => $this->makeToken(),
            'channel' => 'snap_auto',
            'gross_amount' => $base,
            'base_amount' => $base,
            'customer_admin_fee' => 0,
            'status' => 'pending',
            'expired_at' => now()->addDays($expiryDays),
            'created_by' => $userId,
        ]);
    }

    /**
     * Cari/buat link DP untuk Sales Order. Nominal DP diisi customer di halaman publik,
     * jadi base_amount=0 saat dibuat (di-set saat createSnapForLink). $minDp = batas minimal
     * DP (null → default 50% di-hitung di controller).
     */
    public function getOrCreateForSalesOrder(SalesOrder $so, ?int $userId = null, ?int $minDp = null): MidtransTransaction
    {
        $existing = MidtransTransaction::where('sales_order_id', $so->id)
            ->whereNull('sales_invoice_id')
            ->where('source', 'link')
            ->where('status', 'pending')
            ->where('expired_at', '>', now())
            ->latest()
            ->first();

        if ($existing) {
            if ($minDp !== null && (int) round($existing->min_dp_amount) !== $minDp) {
                $existing->update(['min_dp_amount' => $minDp]);
            }
            return $existing;
        }

        $expiryDays = (int) (MidtransSetting::singleton()->link_expiry_days ?: 7);

        return MidtransTransaction::create([
            'order_id' => $this->makeOrderId('SODP'),
            'sales_order_id' => $so->id,
            'customer_id' => $so->customer_id,
            'source' => 'link',
            'link_token' => $this->makeToken(),
            'channel' => 'snap_auto',
            'gross_amount' => 0,
            'base_amount' => 0,
            'min_dp_amount' => $minDp,
            'customer_admin_fee' => 0,
            'status' => 'pending',
            'expired_at' => now()->addDays($expiryDays),
            'created_by' => $userId,
        ]);
    }

    public function findByToken(string $token): ?MidtransTransaction
    {
        return MidtransTransaction::where('link_token', $token)->first();
    }

    public function makeOrderId(string $prefix): string
    {
        return sprintf('NOUD-%s-%s', $prefix, strtoupper(Str::random(10)));
    }

    public function makeToken(): string
    {
        return Str::random(40);
    }

    public function publicUrl(MidtransTransaction $trx): string
    {
        return url('/pay/' . $trx->link_token);
    }

    public function waText(MidtransTransaction $trx, string $customerName, string $invoiceNumber, int $total): string
    {
        $url = $this->publicUrl($trx);
        $totalFmt = 'Rp ' . number_format($total, 0, ',', '.');
        return "Halo {$customerName}, berikut tautan pembayaran untuk invoice {$invoiceNumber} sebesar {$totalFmt}:\n{$url}\n\nLink berlaku 7 hari. Terima kasih — Noud Acrylic.";
    }

    public function waTextSo(MidtransTransaction $trx, string $customerName, string $orderNumber, int $remaining): string
    {
        $url = $this->publicUrl($trx);
        $remFmt = 'Rp ' . number_format($remaining, 0, ',', '.');
        return "Halo {$customerName}, berikut tautan untuk melihat pesanan {$orderNumber} dan membayar uang muka (DP). Sisa tagihan {$remFmt}:\n{$url}\n\nLink berlaku 7 hari. Terima kasih — Noud Acrylic.";
    }
}
