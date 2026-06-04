<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 0 — berat & dimensi produk untuk perhitungan ongkir.
 * Berat dipakai langsung; dimensi untuk volumetrik (diurus aggregator/kurir).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('weight_gram')->nullable()->after('base_unit');
            $table->decimal('length_cm', 8, 2)->nullable()->after('weight_gram');
            $table->decimal('width_cm', 8, 2)->nullable()->after('length_cm');
            $table->decimal('height_cm', 8, 2)->nullable()->after('width_cm');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['weight_gram', 'length_cm', 'width_cm', 'height_cm']);
        });
    }
};
