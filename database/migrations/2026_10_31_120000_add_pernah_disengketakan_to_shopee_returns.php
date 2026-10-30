<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda retur Shopee yang pernah disengketakan (banding). Status akhirnya (ACCEPTED/CLOSED…)
 * tidak lagi menyebut banding, jadi penanda ini yang membuat kartu hasil berjudul
 * "Banding sudah selesai". Sekali menyala tidak dimatikan lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_returns', function (Blueprint $table) {
            $table->boolean('pernah_disengketakan')->default(false)->after('seller_compensation_status');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_returns', function (Blueprint $table) {
            $table->dropColumn('pernah_disengketakan');
        });
    }
};
