<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token publik untuk halaman status pesanan di etalase (/pesanan/{token}).
 * Tidak membocorkan id/nomor internal; dipakai storefront cek status & klaim transfer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_payments', function (Blueprint $table) {
            $table->uuid('public_token')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('web_payments', function (Blueprint $table) {
            $table->dropColumn('public_token');
        });
    }
};
