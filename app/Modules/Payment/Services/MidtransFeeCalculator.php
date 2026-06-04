<?php

namespace App\Modules\Payment\Services;

use App\Models\MidtransSetting;

class MidtransFeeCalculator
{
    /** Default dipakai bila baris setting belum ada. */
    public const VA_BT_FEE_THRESHOLD = 500_000;
    public const CUSTOMER_FEE_VA_BT_BELOW_THRESHOLD = 2_000;
    public const MIDTRANS_FEE_VA = 4_000;
    public const MIDTRANS_FEE_QRIS_PERCENT = 0.7;

    protected ?MidtransSetting $setting = null;

    protected function setting(): MidtransSetting
    {
        return $this->setting ??= MidtransSetting::singleton();
    }

    public function customerFeeThreshold(): int
    {
        return (int) round($this->setting()->customer_fee_threshold ?? self::VA_BT_FEE_THRESHOLD);
    }

    public function customerFeeAmount(): int
    {
        return (int) round($this->setting()->customer_fee_amount ?? self::CUSTOMER_FEE_VA_BT_BELOW_THRESHOLD);
    }

    /**
     * Fee yang dibebankan ke customer (ditambahkan ke gross_amount).
     * Rule: VA/BT < threshold → +customer_fee_amount; lainnya 0.
     */
    public function customerCharge(int $baseAmount, string $channel): int
    {
        if (in_array($channel, ['va', 'bank_transfer'], true) && $baseAmount < $this->customerFeeThreshold()) {
            return $this->customerFeeAmount();
        }
        return 0;
    }

    /**
     * Potongan (MDR) yang diambil Midtrans dari saldo merchant.
     * Sumber: tarif tetap di Pengaturan (bukan webhook). VA/BT flat, QRIS persen dari gross.
     */
    public function mdrFee(int $grossAmount, string $channel): int
    {
        $s = $this->setting();
        return match ($channel) {
            'va', 'bank_transfer' => (int) round($s->va_fee ?? self::MIDTRANS_FEE_VA),
            'qris' => (int) round($grossAmount * ((float) ($s->qris_fee_percent ?? self::MIDTRANS_FEE_QRIS_PERCENT) / 100)),
            default => 0,
        };
    }
}
