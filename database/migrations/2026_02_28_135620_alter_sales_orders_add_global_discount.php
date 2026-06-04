<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->enum('global_discount_type', ['percent', 'amount'])
                ->nullable()->after('subtotal');
            $table->decimal('global_discount_value', 18, 2)->default(0)->after('global_discount_type');
            $table->decimal('global_discount_amount', 18, 2)->default(0)->after('global_discount_value');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['global_discount_type', 'global_discount_value', 'global_discount_amount']);
        });
    }
};
