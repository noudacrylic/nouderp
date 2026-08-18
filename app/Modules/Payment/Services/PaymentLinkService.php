<?php

namespace App\Modules\Payment\Services;

use App\Models\MidtransSetting;
use App\Models\MidtransTransaction;
use App\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        // Pesanan yang tautannya dikirim ke pembeli otomatis punya halaman lacak.
        // Diterbitkan di sini, bukan saat halaman lacak dibuka, supaya tautannya
        // sudah bisa ikut disertakan di pesan yang dikirim ke pembeli.
        $so->ensurePublicToken();

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

    /**
     * Matikan semua transaksi Midtrans yang masih menggantung untuk sebuah invoice.
     * Dipakai saat invoice di-void / dihapus.
     */
    public function deactivateForInvoice(int $invoiceId): int
    {
        return $this->deactivatePending(
            MidtransTransaction::where('sales_invoice_id', $invoiceId)
        );
    }

    /**
     * Matikan transaksi menggantung milik sebuah Sales Order — termasuk tautan DP.
     * Transaksi yang sudah punya invoice sendiri TIDAK ikut dimatikan di sini; nasibnya
     * mengikuti invoice tersebut (SO tidak bisa di-void selama invoice-nya masih aktif).
     */
    public function deactivateForSalesOrder(int $soId): int
    {
        return $this->deactivatePending(
            MidtransTransaction::where('sales_order_id', $soId)->whereNull('sales_invoice_id')
        );
    }

    /**
     * Batalkan transaksi berstatus pending: di Midtrans (agar VA/QRIS yang sudah terbit
     * tak bisa dibayar lagi) sekaligus di ERP. Token tautan SENGAJA dipertahankan supaya
     * pembeli yang membuka link lama mendapat keterangan jelas, bukan halaman "tidak
     * ditemukan" — halaman publik menolak berdasarkan status dokumennya.
     *
     * Yang sudah settlement tidak disentuh: uangnya benar-benar masuk dan harus tetap
     * terlihat pada pembukuan meski dokumennya kemudian dibatalkan.
     */
    private function deactivatePending($query): int
    {
        $rows = (clone $query)->where('status', 'pending')->get();
        if ($rows->isEmpty()) {
            return 0;
        }

        $midtrans = app(MidtransService::class);
        $killed = 0;

        foreach ($rows as $trx) {
            try {
                $midtrans->expireAtGateway($trx);
            } catch (\Throwable $e) {
                Log::warning('Gagal expire transaksi Midtrans saat dokumen dibatalkan', [
                    'order_id' => $trx->order_id,
                    'error' => $e->getMessage(),
                ]);
            }

            $trx->update([
                'status' => 'cancel',
                'expired_at' => now(),
            ]);
            $killed++;
        }

        return $killed;
    }

    /**
     * Hidupkan kembali tautan yang pembayarannya sudah settle padahal dokumennya
     * masih bersisa — kasus paling sering: DP sudah dibayar, tinggal pelunasan.
     *
     * Satu baris MidtransTransaction = SATU pembayaran (nominal, order_id, dan
     * jurnalnya sendiri), jadi pelunasan harus jadi transaksi baru. Yang tidak boleh
     * ikut baru adalah TAUTANNYA: alamat itu sudah dikirim ke pembeli lewat WhatsApp
     * dan mereka akan membukanya lagi saat melunasi. Karena itu token dipindahkan ke
     * transaksi penerus, bukan diterbitkan ulang.
     *
     * Mengembalikan null bila memang tidak perlu dilanjutkan (belum dibayar, atau
     * dokumennya sudah lunas).
     */
    public function continueForRemaining(MidtransTransaction $trx): ?MidtransTransaction
    {
        if (! $trx->isPaid() || ! $trx->link_token) {
            return null;
        }

        $remaining = $this->remainingOf($trx);
        if ($remaining <= 0) {
            return null;
        }

        return DB::transaction(function () use ($trx, $remaining) {
            $current = MidtransTransaction::whereKey($trx->id)->lockForUpdate()->first();

            // Permintaan lain (klik ganda) sudah memindahkan tokennya lebih dulu —
            // pakai transaksi penerus yang sudah ada, jangan bikin kembar.
            if (! $current || ! $current->isPaid() || ! $current->link_token) {
                return MidtransTransaction::where('link_token', $trx->link_token)->first();
            }

            $token = $current->link_token;
            $current->update(['link_token' => null]);

            $expiryDays = (int) (MidtransSetting::singleton()->link_expiry_days ?: 7);
            $isSo = $current->sales_order_id && ! $current->sales_invoice_id;

            return MidtransTransaction::create([
                'order_id' => $this->makeOrderId($isSo ? 'SODP' : 'LINK'),
                'sales_invoice_id' => $current->sales_invoice_id,
                'sales_order_id' => $current->sales_order_id,
                'customer_id' => $current->customer_id,
                'source' => 'link',
                'link_token' => $token,
                'channel' => 'snap_auto',
                'gross_amount' => 0,
                'base_amount' => 0,
                // Sisa tagihan dibayar penuh: DP adalah tanda jadi yang berlaku sekali,
                // sesuai ketentuan yang tertulis di halaman bayar. Admin masih bisa
                // menurunkan batas ini lewat tombol tautan pembayaran bila perlu.
                'min_dp_amount' => $isSo ? $remaining : null,
                'customer_admin_fee' => 0,
                'status' => 'pending',
                'expired_at' => now()->addDays($expiryDays),
                'created_by' => $current->created_by,
            ]);
        });
    }

    /** Sisa tagihan dokumen yang digantung transaksi ini (rupiah, dibulatkan). */
    private function remainingOf(MidtransTransaction $trx): int
    {
        if ($trx->sales_invoice_id) {
            return (int) round((float) ($trx->invoice?->remaining_amount ?? 0));
        }

        $so = $trx->salesOrder;

        return $so ? (int) round((float) $so->grand_total - (float) $so->paid_amount) : 0;
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
