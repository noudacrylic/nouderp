<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Desain gambar oleh Admin Desain": pesanan yang gambarnya (cetak stiker, akrilik sign) dibuat
 * Admin Desain dari brief owner. Dicentang saat membuat SO (form SO / panel Pesanan CRM / halaman
 * SO); setelah pembeli membayar atau SO ditandai tempo, Manajer Admin menaruh kartu di meja Desain.
 *
 * Dipisah dari desain PRODUK (bentuk/ukuran barang custom) yang masih dipegang owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->boolean('desain_gambar')->default(false)->after('allow_backorder');
            $table->text('desain_brief')->nullable()->after('desain_gambar');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['desain_gambar', 'desain_brief']);
        });
    }
};
