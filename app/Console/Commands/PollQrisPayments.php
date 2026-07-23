<?php

namespace App\Console\Commands;

use App\Models\PaymentSetting;
use App\Models\WebPayment;
use App\Modules\Sales\Services\Payment\QrislyProvider;
use App\Modules\Sales\Services\WebPaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Jaring pengaman bila webhook QRISLY tidak sampai (internet kantor putus,
 * webhook salah kirim, dll). Hanya MEMBACA status — tidak generate QR baru,
 * jadi tidak menambah biaya Rp100.
 */
class PollQrisPayments extends Command
{
    protected $signature = 'payments:poll-qris {--limit=30}';
    protected $description = 'Cek status pembayaran QRIS (QRISLY) yang masih menunggu';

    public function handle(WebPaymentService $payments): int
    {
        $setting = PaymentSetting::singleton();
        if (! $setting->qrisReady()) {
            $this->line('QRIS belum aktif — dilewati.');
            return self::SUCCESS;
        }

        $provider = new QrislyProvider($setting);

        $pending = WebPayment::open()
            ->where('method', WebPayment::METHOD_QRIS)
            ->whereNotNull('qris_history_id')
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($pending->isEmpty()) {
            $this->line('Tidak ada QRIS yang menunggu.');
            return self::SUCCESS;
        }

        $paid = 0;
        foreach ($pending as $wp) {
            try {
                $status = $provider->status((string) $wp->qris_history_id);
                if ($status['paid']) {
                    $payments->confirmFromQris((string) $wp->qris_history_id, $status['amount'], 'qris-poll');
                    $paid++;
                    $this->info("Lunas: {$wp->qris_history_id} (WP #{$wp->id})");
                }
            } catch (\Throwable $e) {
                Log::warning('payments:poll-qris gagal', ['web_payment' => $wp->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Diperiksa {$pending->count()} intent, {$paid} lunas.");

        return self::SUCCESS;
    }
}
