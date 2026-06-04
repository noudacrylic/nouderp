<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales_advances', function (Blueprint $table) {
            $table->boolean('use_credit')->default(false)->after('amount');
            $table->decimal('credit_amount', 18, 2)->default(0)->after('use_credit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_advances', function (Blueprint $table) {
            $table->dropColumn(['use_credit', 'credit_amount']);
        });
    }
};
