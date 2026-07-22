<?php

namespace App\Modules\Sales\Services\Payment;

use App\Modules\Sales\Contracts\PaymentConfirmationProvider;
use App\Models\PaymentSetting;

/**
 * Konfirmasi via Moota/Mutasibank (cek-mutasi rekening, ramah perorangan).
 * Moota bersifat WEBHOOK (push), bukan polling — jadi fetchCredits() kosong.
 * Saat webhook Moota masuk, controller-nya memanggil PaymentMatchingService::matchCredit()
 * langsung. Scaffold ini menjaga arsitektur adapter tetap seragam.
 */
class MootaProvider implements PaymentConfirmationProvider
{
    public function __construct(private PaymentSetting $setting) {}

    public function name(): string
    {
        return 'moota';
    }

    public function fetchCredits(): array
    {
        // Push-based: tidak ada polling. Implementasi webhook menyusul.
        return [];
    }
}
