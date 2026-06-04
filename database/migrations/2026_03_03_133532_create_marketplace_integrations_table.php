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
        Schema::create('marketplace_integrations', function (Blueprint $table) {
            $table->id();

            $table->string('code'); // shopee
            $table->string('name'); // Shopee

            $table->decimal('admin_fee_percent', 8, 2)->nullable();
            $table->decimal('admin_fee_nominal', 15, 2)->nullable();

            $table->unsignedBigInteger('account_bank_id')->nullable();
            $table->unsignedBigInteger('account_revenue_id')->nullable();
            $table->unsignedBigInteger('account_admin_expense_id')->nullable();
            $table->unsignedBigInteger('account_recon_plus_id')->nullable();
            $table->unsignedBigInteger('account_recon_minus_id')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketplace_integrations');
    }
};
