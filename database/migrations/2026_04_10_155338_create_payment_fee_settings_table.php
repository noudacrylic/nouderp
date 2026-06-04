<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_fee_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cash_account_id')->unique();
            $table->decimal('fee_flat', 15, 2)->default(0);
            $table->decimal('fee_percent', 5, 2)->default(0);
            $table->unsignedBigInteger('expense_account_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_fee_settings');
    }
};
