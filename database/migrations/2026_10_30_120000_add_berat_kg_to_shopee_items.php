<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Berat listing Shopee (kg, tingkat item — Shopee tak memberi berat per varian di
 * get_item_base_info). Dipakai panel Custom CRM untuk membaca balik berat yang
 * dikirim langsung ke Shopee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_items', function (Blueprint $table) {
            $table->decimal('berat_kg', 10, 3)->nullable()->after('stok');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_items', function (Blueprint $table) {
            $table->dropColumn('berat_kg');
        });
    }
};
