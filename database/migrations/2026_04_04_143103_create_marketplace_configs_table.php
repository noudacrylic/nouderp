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
        Schema::create('marketplace_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique(); // relasi ke customer (unique: 1 customer = 1 config)
            $table->decimal('admin_fee_percent', 5, 2)->default(0);
            $table->decimal('admin_fee_fixed', 18, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketplace_configs');
    }
};
