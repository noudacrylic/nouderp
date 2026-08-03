<?php

namespace App\Modules\Payment\Controllers;

use App\Http\Controllers\Concerns\InlinesPrintAssets;
use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use App\Models\MidtransSetting;
use App\Modules\Payment\Services\MidtransFeeCalculator;
use App\Modules\Payment\Services\MidtransService;
use App\Modules\Payment\Services\PaymentLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class MidtransPublicController extends Controller
{
    use InlinesPrintAssets;

    public function __construct(
        protected PaymentLinkService $links,
        protected MidtransService $midtrans,
        protected MidtransFeeCalculator $fees,
    ) {
    }

    /**
     * Halaman publik bayar / lihat dokumen — buka via /pay/{token}.
     * Mendukung dua jenis link: invoice (pelunasan) & sales order (DP/uang muka).
     */
    public function show(string $token): View
    {
        $trx = $this->links->findByToken($token);
        if (!$trx) {
            return view('pay.invalid', ['reason' => 'Link tidak ditemukan.']);
        }

        $common = [
            'trx' => $trx,
            'is_so' => $this->isSo($trx),
            'customer' => $trx->customer,
            'fee_threshold' => $this->fees->customerFeeThreshold(),
            'fee_amount' => $this->fees->customerFeeAmount(),
            'channel_fees' => $this->fees->effectiveChannelFees(),
            // Metode yang boleh DIPILIH pembeli. channel_fees tetap lengkap (dipakai
            // menghitung biaya), tapi yang ditawarkan hanya yang sudah aktif di Midtrans.
            'active_channels' => MidtransSetting::singleton()->activeChannels(),
            'client_key' => MidtransSetting::resolvedClientKey(),
            'is_production' => MidtransSetting::resolvedIsProduction(),
            'expired' => $trx->isExpired(),
            'instruction' => $this->instructionFrom($trx),
            'require_full' => false, // default; pesanan web memaksa bayar penuh (lihat SO branch)
        ];

        if ($this->isSo($trx)) {
            $so = $trx->salesOrder;
            if (!$so) {
                return view('pay.invalid', ['reason' => 'Pesanan tidak ditemukan.']);
            }
            $remaining = (int) round($so->grand_total - $so->paid_amount);
            $requireFull = $this->requiresFullPayment($trx); // pesanan dari website → wajib lunas

            return view('pay.show', array_merge($common, [
                'so' => $so,
                'grand_total' => (int) round($so->grand_total),
                'paid_amount' => (int) round($so->paid_amount),
                'remaining' => $remaining,
                'min_dp' => $requireFull ? $remaining : $this->minDpFor($trx, $so, $remaining),
                'require_full' => $requireFull,
                'pdf_url' => route('pay.so.pdf', $token),
            ]));
        }

        $invoice = $trx->invoice;
        if (!$invoice) {
            return view('pay.invalid', ['reason' => 'Invoice tidak ditemukan.']);
        }
        $remaining = (int) round($invoice->remaining_amount);

        return view('pay.show', array_merge($common, [
            'invoice' => $invoice,
            'base_amount' => max(0, $remaining),
            'remaining' => $remaining,
            'pdf_url' => route('pay.invoice.pdf', $token),
        ]));
    }

    /**
     * Charge via Core API & kembalikan instruksi bayar (in-page).
     * POST /pay/{token}/charge   body: {channel, bank, amount?}
     */
    public function charge(Request $request, string $token): JsonResponse
    {
        $trx = $this->links->findByToken($token);
        if (!$trx) {
            return response()->json(['error' => 'Link tidak valid'], 404);
        }
        if ($trx->isExpired() || $trx->isPaid()) {
            return response()->json(['error' => 'Link sudah tidak aktif'], 410);
        }

        $data = $request->validate([
            'channel' => 'required|in:qris,va,bank_transfer',
            'bank' => 'nullable|in:bca,bni,bri,permata,mandiri',
            'amount' => 'nullable',
        ]);

        if (in_array($data['channel'], ['va', 'bank_transfer'], true) && empty($data['bank'])) {
            return response()->json(['error' => 'Silakan pilih bank.'], 422);
        }

        $amount = null;
        if ($this->isSo($trx)) {
            $so = $trx->salesOrder;
            if (!$so) {
                return response()->json(['error' => 'Pesanan tidak ditemukan'], 404);
            }
            $remaining = (int) round($so->grand_total - $so->paid_amount);
            if ($remaining <= 0) {
                return response()->json(['error' => 'Pesanan ini sudah lunas.'], 422);
            }

            if ($this->requiresFullPayment($trx)) {
                $amount = $remaining; // pesanan website: wajib lunas
            } else {
                $minDp = $this->minDpFor($trx, $so, $remaining);
                $amount = (int) clean_number($data['amount'] ?? 0);
                if ($amount < $minDp) {
                    return response()->json(['error' => 'DP minimal Rp ' . number_format($minDp, 0, ',', '.') . '.'], 422);
                }
                if ($amount > $remaining) {
                    return response()->json(['error' => 'DP melebihi sisa tagihan (Rp ' . number_format($remaining, 0, ',', '.') . ').'], 422);
                }
            }
        }

        try {
            $trx = $this->midtrans->chargeForLink($trx, $data['channel'], $data['bank'] ?? null, $amount);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        $instr = $this->instructionFrom($trx);
        if (!$instr) {
            return response()->json(['error' => 'Gagal memuat instruksi pembayaran.'], 500);
        }

        return response()->json($instr);
    }

    /**
     * Buat halaman pembayaran Snap (hosted Midtrans) → kembalikan redirect_url.
     * POST /pay/{token}/snap
     */
    public function snap(Request $request, string $token): JsonResponse
    {
        $trx = $this->links->findByToken($token);
        if (!$trx) {
            return response()->json(['error' => 'Link tidak valid'], 404);
        }
        if ($trx->isExpired() || $trx->isPaid()) {
            return response()->json(['error' => 'Link sudah tidak aktif'], 410);
        }

        $data = $request->validate([
            'channel' => 'required|in:qris,va,ewallet,credit_card,alfamart,paylater',
            'amount' => 'nullable',
        ]);

        // Penjaga sisi server: daftar metode di halaman sudah disaring, tapi permintaan
        // bisa datang dari halaman yang sudah lama terbuka saat metodenya dimatikan.
        // Lebih baik ditolak dengan kalimat jelas daripada mentok di halaman Snap.
        if (! MidtransSetting::singleton()->channelActive($data['channel'])) {
            return response()->json([
                'error' => 'Metode pembayaran itu sedang tidak tersedia. Muat ulang halaman lalu pilih metode lain.',
            ], 422);
        }

        $amount = null;
        if ($this->isSo($trx)) {
            $so = $trx->salesOrder;
            if (!$so) {
                return response()->json(['error' => 'Pesanan tidak ditemukan'], 404);
            }
            $remaining = (int) round($so->grand_total - $so->paid_amount);
            if ($remaining <= 0) {
                return response()->json(['error' => 'Pesanan ini sudah lunas.'], 422);
            }

            if ($this->requiresFullPayment($trx)) {
                $amount = $remaining; // pesanan website: wajib lunas
            } else {
                $minDp = $this->minDpFor($trx, $so, $remaining);
                $amount = (int) clean_number($data['amount'] ?? 0);
                if ($amount < $minDp) {
                    return response()->json(['error' => 'DP minimal Rp ' . number_format($minDp, 0, ',', '.') . '.'], 422);
                }
                if ($amount > $remaining) {
                    return response()->json(['error' => 'DP melebihi sisa tagihan (Rp ' . number_format($remaining, 0, ',', '.') . ').'], 422);
                }
            }
        }

        try {
            $trx = $this->midtrans->createSnapForLink($trx, $data['channel'], $amount);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json(['redirect_url' => $trx->snap_redirect_url]);
    }

    /**
     * Polling status (in-page). GET /pay/{token}/status
     */
    public function status(string $token): JsonResponse
    {
        $trx = $this->links->findByToken($token);
        if (!$trx) {
            return response()->json(['error' => 'Link tidak valid'], 404);
        }

        if ($trx->status === 'pending' && !$trx->isExpired()) {
            try {
                $trx = $this->midtrans->refreshStatus($trx);
            } catch (Throwable) {
                // biarkan UI tetap polling
            }
        }

        return response()->json([
            'status' => $trx->status,
            'paid' => $trx->isPaid(),
            'redirect' => $trx->isPaid() ? url('/pay/' . $trx->link_token . '/done') : null,
        ]);
    }

    /**
     * Landing setelah Snap.pay() callback success/pending.
     */
    public function done(string $token): View
    {
        $trx = $this->links->findByToken($token);
        if (!$trx) {
            return view('pay.invalid', ['reason' => 'Link tidak ditemukan.']);
        }
        return view('pay.done', [
            'trx' => $trx,
            'is_so' => $this->isSo($trx),
            'invoice' => $trx->invoice,
            'so' => $trx->salesOrder,
        ]);
    }

    /**
     * Download PDF invoice / SO via token (publik, tanpa auth).
     */
    public function invoicePdf(string $token)
    {
        $trx = $this->links->findByToken($token);
        if (!$trx || !$trx->invoice) {
            abort(404);
        }
        $invoice = $trx->invoice->load(['customer', 'items.product']);
        $profile = BusinessProfile::instance()->load('bankAccounts');
        $html = view('erp.sales.invoices.print', [
            'invoice' => $invoice,
            'profile' => $profile,
            'pdfMode' => true,
        ])->render();

        return $this->renderPdf($html, 'FakturPenjualan_' . $invoice->invoice_number);
    }

    public function soPdf(string $token)
    {
        $trx = $this->links->findByToken($token);
        if (!$trx || !$trx->salesOrder) {
            abort(404);
        }
        $order = $trx->salesOrder->load(['customer', 'warehouse', 'items.product', 'advances']);
        $profile = BusinessProfile::instance()->load('bankAccounts');
        $html = view('erp.sales.orders.print', [
            'order' => $order,
            'profile' => $profile,
            'pdfMode' => true,
        ])->render();

        return $this->renderPdf($html, 'PesananPenjualan_' . $order->order_number);
    }

    /* ===================================================================
       Helpers
       =================================================================== */

    protected function isSo($trx): bool
    {
        return $trx->sales_order_id && !$trx->sales_invoice_id;
    }

    /**
     * Pesanan dari WEBSITE wajib dibayar LUNAS (tak boleh DP sebagian). Penanda: SO
     * tersebut punya WebPayment (dibuat saat checkout etalase). Link DP yang dibuat admin
     * TIDAK punya WebPayment → tetap boleh DP.
     */
    protected function requiresFullPayment($trx): bool
    {
        return $this->isSo($trx)
            && \App\Models\WebPayment::where('sales_order_id', $trx->sales_order_id)->exists();
    }

    /**
     * Batas minimal DP: pakai override admin (min_dp_amount) bila ada,
     * jika tidak default 50% grand total dikurangi yang sudah dibayar, dibatasi sisa.
     */
    protected function minDpFor($trx, $so, int $remaining): int
    {
        $min = $trx->min_dp_amount !== null
            ? (int) round($trx->min_dp_amount)
            : (int) max(0, (int) round($so->grand_total * 0.5) - (int) round($so->paid_amount));

        return (int) min($min, max(0, $remaining));
    }

    /**
     * Normalisasi instruksi bayar dari hasil charge (raw_response) → array siap tampil,
     * atau null jika belum ada charge aktif (belum charge / expired / sudah dibayar).
     */
    protected function instructionFrom($trx): ?array
    {
        if (!$trx || $trx->status !== 'pending' || $trx->isExpired()) {
            return null;
        }
        $raw = $trx->raw_response ? json_decode($trx->raw_response, true) : null;
        if (!is_array($raw) || empty($raw['payment_type'])) {
            return null;
        }

        $base = [
            'channel' => $trx->channel,
            'bank' => $trx->bank ? strtoupper($trx->bank) : null,
            'amount' => (int) round($trx->gross_amount),
            'order_id' => $trx->order_id,
        ];

        if (!empty($raw['va_numbers'][0]['va_number'])) {
            return array_merge($base, [
                'type' => 'va',
                'bank' => strtoupper($raw['va_numbers'][0]['bank'] ?? $trx->bank ?? ''),
                'va_number' => $raw['va_numbers'][0]['va_number'],
            ]);
        }
        if (!empty($raw['permata_va_number'])) {
            return array_merge($base, ['type' => 'va', 'bank' => 'PERMATA', 'va_number' => $raw['permata_va_number']]);
        }
        if (!empty($raw['bill_key']) && !empty($raw['biller_code'])) {
            return array_merge($base, ['type' => 'mandiri', 'biller_code' => $raw['biller_code'], 'bill_key' => $raw['bill_key']]);
        }

        $qr = $trx->qris_payload;
        if (!$qr) {
            foreach (($raw['actions'] ?? []) as $a) {
                if (($a['name'] ?? '') === 'generate-qr-code') {
                    $qr = $a['url'];
                    break;
                }
            }
        }
        if ($qr) {
            return array_merge($base, ['type' => 'qris', 'qr_url' => $qr]);
        }

        return null;
    }

    protected function renderPdf(string $html, string $baseName)
    {
        $html = $this->inlineLocalAssets($html);

        $bs = \Spatie\Browsershot\Browsershot::html($html)
            ->showBackground()
            ->format('A4')
            ->margins(0, 0, 0, 0)
            ->emulateMedia('print')
            ->timeout(120)
            ->waitUntilNetworkIdle();

        $nodePath = env('BROWSERSHOT_NODE_PATH', PHP_OS_FAMILY === 'Windows' ? 'C:\\Program Files\\nodejs\\node.exe' : null);
        $npmPath  = env('BROWSERSHOT_NPM_PATH',  PHP_OS_FAMILY === 'Windows' ? 'C:\\Program Files\\nodejs\\npm.cmd'  : null);
        if ($nodePath) $bs->setNodeBinary($nodePath);
        if ($npmPath)  $bs->setNpmBinary($npmPath);

        // Tanpa ini puppeteer cari Chrome di cache default HOME (kosong di server) → 500.
        // Path absolut Chrome di-set via env pool php-fpm (lihat SlipGajiController).
        if ($chromePath = env('BROWSERSHOT_CHROME_PATH')) {
            $bs->setChromePath($chromePath);
        }

        $pdf = $bs->pdf();
        $filename = preg_replace('/[^A-Za-z0-9_-]/', '_', $baseName) . '.pdf';

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
