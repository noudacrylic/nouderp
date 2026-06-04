<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan Penjual: komunikasi internal CS ↔ packing di kartu Pemrosesan Pesanan
 * (mis. "instant minta dikirim sebelum jam 12"). Terpisah dari `notes` (Catatan
 * Pembeli) yang berasal dari pesanan customer.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('sales_orders', 'seller_notes')) {
                $table->text('seller_notes')->nullable()->after('notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', fn (Blueprint $table) => $table->dropColumn('seller_notes'));
    }
};
