<?php

namespace App\Modules\Payment\Services;

use App\Models\MidtransSetting;

/**
 * Kalkulator biaya Midtrans — model subsidi per metode.
 *
 * Tiap metode punya tarif MDR (persen + flat) dari Midtrans dan SUBSIDI (persen +
 * flat) yang ditanggung toko. Yang dibebankan ke pembeli = MDR − subsidi:
 *
 *   biaya_pembeli = max(0, mdr% − subsidi%) × nominal
 *                 + [ nominal < batas_flat ? max(0, mdr_flat − subsidi_flat) : 0 ]
 *
 * Di jurnal, Beban Gateway = MDR − biaya_pembeli = subsidi (dana yang toko tanggung).
 *
 * Sumber: kolom JSON `channel_fees` di Pengaturan → Midtrans. Bila sebuah metode
 * belum diatur di sana, dipakai FALLBACK ke kolom lama (va_fee, qris_fee_percent,
 * customer_fee_amount) agar akuntansi VA/QRIS yang sudah berjalan tidak berubah.
 */
class MidtransFeeCalculator
{
    /** Default dipakai bila baris setting belum ada. */
    public const VA_BT_FEE_THRESHOLD = 500_000;
    public const CUSTOMER_FEE_VA_BT_BELOW_THRESHOLD = 2_000;
    public const MIDTRANS_FEE_VA = 4_000;
    public const MIDTRANS_FEE_QRIS_PERCENT = 0.7;

    /** Grup metode bayar (key channel_fees) → label tampil. */
    public static function channelLabels(): array
    {
        return [
            'qris'        => 'QRIS',
            'va'          => 'Virtual Account',
            'ewallet'     => 'E-Wallet (GoPay/ShopeePay)',
            'credit_card' => 'Kartu Kredit',
            'alfamart'    => 'Alfamart',
            'paylater'    => 'Kredivo / Akulaku',
        ];
    }

    /**
     * Prefill wajar (tarif MDR standar Midtrans + subsidi = MDR → pembeli 0, kecuali
     * VA yang mempertahankan biaya admin Rp2.000 di bawah Rp500rb seperti sebelumnya).
     * Toko tinggal MENURUNKAN subsidi untuk mulai membebankan biaya ke pembeli.
     */
    public static function channelDefaults(): array
    {
        // QRIS SENGAJA gratis (subsidi = MDR) untuk mengarahkan pembeli ke QRIS —
        // biaya paling murah untuk toko. Metode lain default membebankan biaya ke
        // pembeli (subsidi 0 = pembeli tanggung MDR); toko boleh menambah subsidi
        // di Pengaturan → Midtrans untuk menurunkan biaya pembeli.
        return [
            'qris'        => ['mdr_percent' => 0.7, 'mdr_flat' => 0,    'subsidy_percent' => 0.7, 'subsidy_flat' => 0,    'flat_threshold' => 0],
            'va'          => ['mdr_percent' => 0,   'mdr_flat' => 4000, 'subsidy_percent' => 0,   'subsidy_flat' => 2000, 'flat_threshold' => 500000],
            'ewallet'     => ['mdr_percent' => 2,   'mdr_flat' => 0,    'subsidy_percent' => 0,   'subsidy_flat' => 0,    'flat_threshold' => 0],
            'credit_card' => ['mdr_percent' => 2.9, 'mdr_flat' => 2000, 'subsidy_percent' => 0,   'subsidy_flat' => 0,    'flat_threshold' => 0],
            'alfamart'    => ['mdr_percent' => 0,   'mdr_flat' => 5000, 'subsidy_percent' => 0,   'subsidy_flat' => 0,    'flat_threshold' => 0],
            'paylater'    => ['mdr_percent' => 2.6, 'mdr_flat' => 0,    'subsidy_percent' => 0,   'subsidy_flat' => 0,    'flat_threshold' => 0],
        ];
    }

    /** Config efektif 1 channel: tersimpan bila ada, selain itu default. */
    protected function channelConfig(string $channel): ?array
    {
        $stored = $this->setting()->channelFee($channel);
        if ($stored !== null) {
            return $stored;
        }

        return self::channelDefaults()[$channel] ?? null;
    }

    /** Seluruh config efektif (tersimpan menimpa default) — untuk ditampilkan di halaman bayar. */
    public function effectiveChannelFees(): array
    {
        $stored = $this->setting()->channel_fees ?? [];
        $out = [];
        foreach (self::channelDefaults() as $key => $def) {
            $out[$key] = array_merge($def, is_array($stored[$key] ?? null) ? $stored[$key] : []);
        }

        return $out;
    }

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
     * Biaya admin yang dibebankan ke pembeli (ditambahkan ke base → gross).
     * = max(0, mdr% − subsidi%) × base + [base < batas ? max(0, mdr_flat − subsidi_flat) : 0]
     */
    public function customerCharge(int $baseAmount, string $channel): int
    {
        $cf = $this->channelConfig($channel);
        if ($cf !== null) {
            $pct = max(0.0, (float) ($cf['mdr_percent'] ?? 0) - (float) ($cf['subsidy_percent'] ?? 0));
            $flatFull = max(0, (int) round($cf['mdr_flat'] ?? 0) - (int) round($cf['subsidy_flat'] ?? 0));
            $threshold = (int) round($cf['flat_threshold'] ?? 0);
            $flat = ($threshold <= 0 || $baseAmount < $threshold) ? $flatFull : 0;

            return (int) round($baseAmount * ($pct / 100)) + $flat;
        }

        // Fallback perilaku lama: VA/BT di bawah threshold → biaya admin flat; lainnya 0.
        if ($baseAmount >= $this->customerFeeThreshold()) {
            return 0;
        }
        if (in_array($channel, ['va', 'bank_transfer'], true)) {
            return $this->customerFeeAmount();
        }

        return 0;
    }

    /**
     * Potongan (MDR) yang diambil Midtrans dari saldo merchant — untuk pembukuan
     * Beban Gateway. Sumber: tarif tetap di Pengaturan (bukan webhook).
     */
    public function mdrFee(int $grossAmount, string $channel): int
    {
        $cf = $this->channelConfig($channel);
        if ($cf !== null) {
            return (int) round((float) ($cf['mdr_flat'] ?? 0) + $grossAmount * ((float) ($cf['mdr_percent'] ?? 0) / 100));
        }

        // Fallback perilaku lama.
        $s = $this->setting();

        return match ($channel) {
            'va', 'bank_transfer' => (int) round($s->va_fee ?? self::MIDTRANS_FEE_VA),
            'qris' => (int) round($grossAmount * ((float) ($s->qris_fee_percent ?? self::MIDTRANS_FEE_QRIS_PERCENT) / 100)),
            default => 0,
        };
    }
}
