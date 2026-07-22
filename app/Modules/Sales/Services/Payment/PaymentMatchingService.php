<?php

namespace App\Modules\Sales\Services\Payment;

use App\Modules\Sales\Contracts\PaymentConfirmationProvider;
use App\Models\PaymentSetting;
use App\Models\WebPayment;
use App\Modules\Sales\Services\WebPaymentService;
use Illuminate\Support\Facades\Log;

/**
 * Mesin pencocokan uang masuk → intent pembayaran (web_payments).
 * Nominal transfer dibuat UNIK per order (kode unik) sehingga kecocokan tunggal.
 */
class PaymentMatchingService
{
    public function __construct(protected WebPaymentService $webPayments) {}

    /** Jalankan poll provider aktif → cocokkan semua kredit. Return jumlah yang dikonfirmasi. */
    public function run(): int
    {
        $setting = PaymentSetting::singleton();
        if (! $setting->is_active) {
            return 0;
        }

        $provider = $this->providerFor($setting);
        if (! $provider) {
            return 0;
        }

        $confirmed = 0;
        foreach ($provider->fetchCredits() as $credit) {
            $wp = $this->matchCredit((float) $credit['amount'], (string) $credit['reference'], $provider->name());
            if ($wp) {
                $confirmed++;
            }
        }

        return $confirmed;
    }

    /**
     * Cocokkan satu kredit ke intent terbuka dengan expected_amount == nominal.
     * Dipanggil oleh poller (email) maupun webhook (moota).
     */
    public function matchCredit(float $amount, string $reference, string $via = 'email'): ?WebPayment
    {
        $wp = WebPayment::open()
            ->whereRaw('ROUND(expected_amount, 2) = ROUND(?, 2)', [$amount])
            ->orderBy('created_at') // tertua dulu bila (langka) ada seri
            ->first();

        if (! $wp) {
            return null;
        }

        try {
            return $this->webPayments->confirm($wp->id, $via, $reference);
        } catch (\Throwable $e) {
            Log::error("PaymentMatching: gagal konfirmasi WebPayment #{$wp->id} — " . $e->getMessage());
            return null;
        }
    }

    /** Resolusi adapter sesuai pilihan provider di setting. */
    public function providerFor(PaymentSetting $setting): ?PaymentConfirmationProvider
    {
        return match ($setting->confirmation_provider) {
            'email' => new EmailMutationProvider($setting),
            'moota' => new MootaProvider($setting),
            default => null, // 'manual' → tanpa auto-poll (andalkan Telegram/manual)
        };
    }
}
