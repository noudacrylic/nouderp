<?php

namespace App\Modules\Sales\Services;

use App\Http\Controllers\Concerns\InlinesPrintAssets;
use App\Modules\Sales\Models\SalesOrder;
use Spatie\Browsershot\Browsershot;

/**
 * Render Sales Order ke PDF (bytes) memakai Browsershot/Chromium.
 *
 * Diekstrak dari SalesOrderController::downloadPdf agar pipeline PDF yang sama
 * bisa dipakai ulang di luar HTTP (mis. kirim PDF SO lewat Telegram / AI).
 * Merender view print yang persis sama dengan tombol Cetak di UI.
 */
class SalesOrderPdfService
{
    use InlinesPrintAssets;

    /**
     * Kembalikan PDF Sales Order sebagai string biner.
     * @throws \Throwable bila Chromium/Browsershot gagal.
     */
    public function render(SalesOrder $order): string
    {
        $order->loadMissing(['customer', 'warehouse', 'items.product', 'advances']);

        $this->ensurePaymentLink($order);

        $profile = \App\Models\BusinessProfile::instance()->load('bankAccounts');

        $html = view('erp.sales.orders.print', [
            'order'   => $order,
            'profile' => $profile,
            'pdfMode' => true,
        ])->render();

        // Inline aset lokal jadi base64 → hindari deadlock single-worker `php artisan serve`.
        $html = $this->inlineLocalAssets($html);

        $bs = Browsershot::html($html)
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
        if ($chromePath = env('BROWSERSHOT_CHROME_PATH')) {
            $bs->setChromePath($chromePath);
        }

        return $bs->pdf();
    }

    /** Nama file PDF yang rapi untuk SO ini. */
    public function filename(SalesOrder $order): string
    {
        return 'PesananPenjualan_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $order->order_number) . '.pdf';
    }

    /**
     * Pastikan payment link Midtrans tersedia (QR di Print SO) untuk SO draft maupun
     * confirmed yang masih ada sisa tagihan. Draft sengaja ikut: pesanan boleh dikirim
     * ke pembeli selagi draft (stok baru ditahan setelah DP masuk). Gagal = diabaikan.
     */
    private function ensurePaymentLink(SalesOrder $order): void
    {
        $remaining = (float) $order->grand_total - (float) ($order->paid_amount ?? 0);
        if (! in_array($order->status, ['draft', 'confirmed'], true) || $remaining <= 0) {
            return;
        }
        try {
            app(\App\Modules\Payment\Services\PaymentLinkService::class)
                ->getOrCreateForSalesOrder($order, auth()->id());
        } catch (\Throwable $e) {
            // Abaikan — kalau gagal, PDF tetap tampil tanpa QR.
        }
    }
}
