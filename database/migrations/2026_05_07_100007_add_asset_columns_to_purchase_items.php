<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // purchase_order_items: tambah kolom aset
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->boolean('is_asset')->default(false)->after('product_id');
            $table->foreignId('asset_category_id')->nullable()->after('is_asset')
                ->constrained('asset_categories')->nullOnDelete();
            $table->string('asset_name', 200)->nullable()->after('asset_category_id');
        });

        // purchase_invoice_items: tambah kolom aset
        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->boolean('is_asset')->default(false)->after('product_id');
            $table->foreignId('asset_category_id')->nullable()->after('is_asset')
                ->constrained('asset_categories')->nullOnDelete();
            $table->string('asset_name', 200)->nullable()->after('asset_category_id');
        });

        // Buat product_id menjadi nullable di kedua tabel.
        // Ini perlu drop FK lama dan buat ulang dengan nullable.
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });
        DB::statement('ALTER TABLE purchase_order_items MODIFY product_id BIGINT UNSIGNED NULL');
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
        });

        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });
        DB::statement('ALTER TABLE purchase_invoice_items MODIFY product_id BIGINT UNSIGNED NULL');
        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropForeign(['asset_category_id']);
            $table->dropColumn(['is_asset', 'asset_category_id', 'asset_name']);
        });

        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->dropForeign(['asset_category_id']);
            $table->dropColumn(['is_asset', 'asset_category_id', 'asset_name']);
        });
    }
};
