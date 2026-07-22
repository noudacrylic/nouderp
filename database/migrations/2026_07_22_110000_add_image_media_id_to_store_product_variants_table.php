<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gambar per-varian (opsional): tiap kombinasi SKU boleh menunjuk SATU foto dari
 * galeri produk. Saat varian dipilih di etalase, galeri lompat ke foto itu.
 * Nullable → varian tanpa gambar tetap pakai galeri seperti biasa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_product_variants', function (Blueprint $table) {
            $table->foreignId('image_media_id')->nullable()->after('option_values')
                ->constrained('store_product_media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('store_product_variants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('image_media_id');
        });
    }
};
