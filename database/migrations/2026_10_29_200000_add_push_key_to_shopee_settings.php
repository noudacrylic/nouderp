<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live Push Shopee ditandatangani "Live Push Partner Key" — kunci TERSENDIRI yang dibuat di
 * Console › Push Mechanism (tombol Generate), bukan Partner Key app. Disimpan terenkripsi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_settings', function (Blueprint $table) {
            $table->text('test_push_key')->nullable()->after('test_partner_key');
            $table->text('live_push_key')->nullable()->after('live_partner_key');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_settings', function (Blueprint $table) {
            $table->dropColumn(['test_push_key', 'live_push_key']);
        });
    }
};
