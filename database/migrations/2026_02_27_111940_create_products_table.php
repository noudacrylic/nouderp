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
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->string('sku')->unique();
            $table->string('name');

            $table->enum('sale_type', [
                'ready',
                'preorder',
                'custom',
                'service',
                'non_stock',
                'bundle'
            ]);

            $table->string('base_unit')->nullable();
            $table->integer('lead_time_days')->nullable();

            $table->unsignedBigInteger('income_account_id')->nullable();
            $table->unsignedBigInteger('expense_account_id')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->foreign('income_account_id')
                ->references('id')
                ->on('accounts')
                ->nullOnDelete();

            $table->foreign('expense_account_id')
                ->references('id')
                ->on('accounts')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
