<?php

namespace App\Modules\CRM\Services;

use App\Http\Controllers\Concerns\InlinesPrintAssets;
use App\Models\BusinessProfile;
use App\Models\SalesInvoice;
use Illuminate\Http\UploadedFile;
use Spatie\Browsershot\Browsershot;

/**
 * Faktur penjualan sebagai GAMBAR (PNG), untuk dikirim lewat chat.
 *
 * Chat Shopee tak bisa mengirim PDF dan memblokir pesan yang memuat nomor
 * telepon / alamat web — jadi nota untuk chat Shopee dicetak tanpa baris kontak
 * toko. Penggambarnya halaman cetak faktur yang sama dengan PDF (mode cetak),
 * dipotong pas di kertasnya saja supaya tak ada sisa A4 kosong di bawah.
 */
class NotaGambarService
{
    use InlinesPrintAssets;

    public function render(SalesInvoice $invoice, bool $tanpaKontak, ?string $atasNama = null): UploadedFile
    {
        $invoice->loadMissing(['customer', 'items.product']);

        $html = view('erp.sales.invoices.print', [
            'invoice'     => $invoice,
            'profile'     => BusinessProfile::instance()->load('bankAccounts'),
            'pdfMode'     => true,
            'atasNama'    => $atasNama,
            'tanpaKontak' => $tanpaKontak,
        ])->render();

        $path = tempnam(sys_get_temp_dir(), 'nota') . '.png';

        $bs = Browsershot::html($this->inlineLocalAssets($html))
            ->showBackground()
            ->emulateMedia('print')
            ->windowSize(794, 1123)   // A4 pada 96 dpi
            ->deviceScaleFactor(2)    // tajam saat diperbesar di HP
            ->select('.paper')
            ->timeout(120)
            ->waitUntilNetworkIdle();

        $nodePath = env('BROWSERSHOT_NODE_PATH', PHP_OS_FAMILY === 'Windows' ? 'C:\\Program Files\\nodejs\\node.exe' : null);
        $npmPath  = env('BROWSERSHOT_NPM_PATH',  PHP_OS_FAMILY === 'Windows' ? 'C:\\Program Files\\nodejs\\npm.cmd'  : null);
        if ($nodePath) $bs->setNodeBinary($nodePath);
        if ($npmPath)  $bs->setNpmBinary($npmPath);
        if ($chromePath = env('BROWSERSHOT_CHROME_PATH')) $bs->setChromePath($chromePath);

        $bs->save($path);

        $nama = 'Nota_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $invoice->invoice_number) . '.png';

        return new UploadedFile($path, $nama, 'image/png', null, true);
    }
}
