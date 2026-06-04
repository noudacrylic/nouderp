<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom harga Jubelio (Fase 3 — push harga; promo ditunda):
 * - jubelio_item_group_id: dibutuhkan payload Edit Product Prices (/inventory/price-list/).
 * - jubelio_price_pending: ditandai observer ProductPrice; cron push memprosesnya.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('jubelio_item_group_id')->nullable()->after('jubelio_item_id');
            $table->boolean('jubelio_price_pending')->default(false)->after('jubelio_synced_qty');
            $table->index('jubelio_price_pending');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['jubelio_price_pending']);
            $table->dropColumn(['jubelio_item_group_id', 'jubelio_price_pending']);
        });
    }
};
