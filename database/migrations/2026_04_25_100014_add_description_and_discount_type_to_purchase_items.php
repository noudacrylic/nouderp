<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->string('description')->nullable()->after('product_id');
            $table->enum('discount_type', ['percent', 'nominal'])->default('nominal')->after('discount');
            $table->decimal('discount_value', 18, 2)->default(0)->after('discount_type');
        });

        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->string('description')->nullable()->after('product_id');
            $table->enum('discount_type', ['percent', 'nominal'])->default('nominal')->after('discount');
            $table->decimal('discount_value', 18, 2)->default(0)->after('discount_type');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn(['description', 'discount_type', 'discount_value']);
        });
        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->dropColumn(['description', 'discount_type', 'discount_value']);
        });
    }
};
