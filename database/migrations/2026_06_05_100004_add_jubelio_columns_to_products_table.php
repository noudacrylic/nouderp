<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom integrasi Jubelio pada products:
 * - sync_to_jubelio: hanya produk bertanda ini yang disinkron stok/harga ke Jubelio
 *   (kecualikan bahan baku, komponen, produk sampingan).
 * - jubelio_item_id: cache hasil match SKU ↔ item Jubelio (untuk resolusi cepat item pesanan & push stok).
 * - jubelio_location_id: override location_id Jubelio per produk (opsional; default ambil dari setting).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('sync_to_jubelio')->default(false)->after('is_active');
            $table->unsignedBigInteger('jubelio_item_id')->nullable()->after('sync_to_jubelio');
            $table->integer('jubelio_location_id')->nullable()->after('jubelio_item_id');
            $table->index('jubelio_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['jubelio_item_id']);
            $table->dropColumn(['sync_to_jubelio', 'jubelio_item_id', 'jubelio_location_id']);
        });
    }
};
