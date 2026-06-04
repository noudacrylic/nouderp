<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_billings', function (Blueprint $table) {
            $table->enum('billing_type', ['invoice', 'sales_order'])->default('invoice')->after('billing_number');
        });
    }

    public function down(): void
    {
        Schema::table('customer_billings', function (Blueprint $table) {
            $table->dropColumn('billing_type');
        });
    }
};
