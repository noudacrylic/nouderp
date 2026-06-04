<?php

namespace App\Services;

use App\Models\MarketplaceConfig;
use App\Models\Customer;

class MarketplaceDetectionService
{
    /**
     * Cek apakah customer adalah marketplace
     */
    public function isMarketplace(Customer $customer): bool
    {
        return MarketplaceConfig::where('customer_id', $customer->id)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Ambil konfigurasi marketplace untuk customer tertentu
     */
    public function getConfig(Customer $customer): ?MarketplaceConfig
    {
        return MarketplaceConfig::where('customer_id', $customer->id)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Hitung total biaya admin berdasarkan grand total invoice
     */
    public function calculateFee(MarketplaceConfig $config, float $amount): float
    {
        $feePercent = $amount * ($config->admin_fee_percent / 100);
        return round($feePercent + $config->admin_fee_fixed, 2);
    }
}
