<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Definisi sumbu variasi ala Shopee (mis. [{name:"Orientasi",options:["Potrait","Landscape"]},
        // {name:"Packing",options:["Kayu","Tanpa"]}]). NULL = produk tanpa varian (1 SKU).
        Schema::table('store_products', function (Blueprint $table) {
            $table->json('variant_axes')->nullable()->after('description');
        });

        // Nilai opsi tiap kombinasi varian (mis. ["Potrait","Tanpa"]). NULL = produk tanpa varian.
        Schema::table('store_product_variants', function (Blueprint $table) {
            $table->json('option_values')->nullable()->after('variant_label');
        });
    }

    public function down(): void
    {
        Schema::table('store_products', function (Blueprint $table) {
            $table->dropColumn('variant_axes');
        });
        Schema::table('store_product_variants', function (Blueprint $table) {
            $table->dropColumn('option_values');
        });
    }
};
