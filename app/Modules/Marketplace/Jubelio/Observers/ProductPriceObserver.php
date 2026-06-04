<?php

namespace App\Modules\Marketplace\Jubelio\Observers;

use App\Core\Inventory\Product;
use App\Models\ProductPrice;
use App\Modules\Marketplace\Jubelio\Models\JubelioSetting;

/**
 * Menandai produk yang harganya berubah agar didorong ke Jubelio oleh cron
 * (jubelio:push-prices). Hanya untuk produk tersinkron; ERP = sumber kebenaran harga.
 */
class ProductPriceObserver
{
    private static ?bool $active = null;

    public function saved(ProductPrice $price): void
    {
        $this->flag($price->product_id);
    }

    public function deleted(ProductPrice $price): void
    {
        $this->flag($price->product_id);
    }

    private function flag($productId): void
    {
        if (!$productId || !$this->jubelioActive()) {
            return;
        }
        Product::where('id', $productId)
            ->where('sync_to_jubelio', true)
            ->update(['jubelio_price_pending' => true]);
    }

    private function jubelioActive(): bool
    {
        if (self::$active === null) {
            self::$active = JubelioSetting::singleton()->isConfigured();
        }
        return self::$active;
    }
}
