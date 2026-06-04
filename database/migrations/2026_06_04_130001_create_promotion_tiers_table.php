<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tingkatan (tier) untuk promo type shipping & cart_total.
 * Tier yang dipakai = min_spend tertinggi yang masih <= subtotal.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('promotion_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->decimal('min_spend', 18, 2)->default(0);
            $table->enum('discount_type', ['nominal', 'percent'])->default('nominal');
            $table->decimal('discount_value', 18, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_tiers');
    }
};
