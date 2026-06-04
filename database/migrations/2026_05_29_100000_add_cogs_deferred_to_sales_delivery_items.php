<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_delivery_items', function (Blueprint $table) {
            // true = SJ dikirim saat stok belum cukup (stok minus), COGS ditunda
            // dan dihitung dari layer FIFO aktual saat invoice di-post.
            $table->boolean('cogs_deferred')->default(false)->after('cogs_total');
        });
    }

    public function down(): void
    {
        Schema::table('sales_delivery_items', function (Blueprint $table) {
            $table->dropColumn('cogs_deferred');
        });
    }
};
