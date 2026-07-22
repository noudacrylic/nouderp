<?php

namespace App\Console\Commands;

use App\Modules\Sales\Services\Payment\PaymentMatchingService;
use Illuminate\Console\Command;

/**
 * Konfirmasi otomatis (lapis 1): poll sumber mutasi (email/moota), cocokkan nominal
 * unik ke order → posting pembayaran. Dijadwalkan tiap ~1–2 menit.
 */
class PollPaymentMutations extends Command
{
    protected $signature = 'payments:poll-mutations';
    protected $description = 'Cek uang masuk (email/moota) & cocokkan ke pembayaran toko online';

    public function handle(PaymentMatchingService $matching): int
    {
        $n = $matching->run();
        $this->info("Pembayaran dikonfirmasi otomatis: {$n}");
        return self::SUCCESS;
    }
}
