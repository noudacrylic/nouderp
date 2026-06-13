<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Link produk sampingan ke bahan baku pembuatnya + ukuran (PxL) yang dibutuhkan
        // untuk membuat 1 unit sampingan. Opsional; wajib bila dipakai Kalkulator Custom.
        Schema::table('production_byproducts', function (Blueprint $table) {
            $table->foreignId('raw_material_product_id')->nullable()->after('product_id')
                  ->constrained('products')->nullOnDelete();
            $table->decimal('panjang', 10, 2)->nullable()->after('raw_material_product_id');
            $table->decimal('lebar', 10, 2)->nullable()->after('panjang');
        });
    }

    public function down(): void
    {
        Schema::table('production_byproducts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('raw_material_product_id');
            $table->dropColumn(['panjang', 'lebar']);
        });
    }
};
