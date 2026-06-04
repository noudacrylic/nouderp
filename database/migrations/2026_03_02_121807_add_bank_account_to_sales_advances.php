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
            $table->unsignedBigInteger('bank_account_id')->after('customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_advances', function (Blueprint $table) {
            $table->dropColumn('bank_account_id');
        });
    }
};
