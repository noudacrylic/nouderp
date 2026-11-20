<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jadwal manual per iklan anggota: {campaign_id: [jam tayang 0–23]}. Iklan yang punya jadwal
 * manual mengikuti jam itu persis (kecuali stok habis); yang lain tetap bergiliran otomatis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_iklan_kelompok', function (Blueprint $t) {
            $t->json('jadwal_manual')->nullable()->after('batas_belanja');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_iklan_kelompok', function (Blueprint $t) {
            $t->dropColumn('jadwal_manual');
        });
    }
};
