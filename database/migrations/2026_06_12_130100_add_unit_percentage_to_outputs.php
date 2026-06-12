<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Persentase biaya PER UNIT (basis 1 siklus) yang disalin dari master
        // production_byproducts. Null untuk produk utama / data lama (legacy).
        // Dipakai finalisasi untuk menghitung ulang % berdasarkan qty aktual:
        //   percentage = unit_percentage × (qty_produced / planned_cycles)
        Schema::table('bom_outputs', function (Blueprint $table) {
            $table->decimal('unit_percentage', 8, 4)->nullable()->after('percentage');
        });

        Schema::table('production_order_outputs', function (Blueprint $table) {
            $table->decimal('unit_percentage', 8, 4)->nullable()->after('percentage');
        });
    }

    public function down(): void
    {
        Schema::table('bom_outputs', function (Blueprint $table) {
            $table->dropColumn('unit_percentage');
        });

        Schema::table('production_order_outputs', function (Blueprint $table) {
            $table->dropColumn('unit_percentage');
        });
    }
};
