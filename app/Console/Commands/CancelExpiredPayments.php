<?php

namespace App\Console\Commands;

use App\Models\WebPayment;
use App\Modules\Sales\Services\WebPaymentService;
use Illuminate\Console\Command;

/**
 * Backstop (lapis 3): order toko online yang lewat kedaluwarsa (default 24 jam)
 * & belum lunas → void SO + lepas reservasi stok. Dijadwalkan tiap jam.
 */
class CancelExpiredPayments extends Command
{
    protected $signature = 'payments:cancel-expired';
    protected $description = 'Auto-batal order toko online yang belum dibayar melewati batas waktu';

    public function handle(WebPaymentService $service): int
    {
        $expired = WebPayment::open()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $wp) {
            $service->expire($wp->id, WebPayment::STATUS_EXPIRED, 'Auto-batal: melewati batas waktu pembayaran');
        }

        $this->info("Order kedaluwarsa dibatalkan: {$expired->count()}");
        return self::SUCCESS;
    }
}
