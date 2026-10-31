<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ID pembeli Shopee di salinan pesanan — penghubung pesanan ke chat Shopee-nya
 * (shopee_chats.to_id), supaya tombol chat di Meja Kerja membuka percakapan CRM yang
 * benar walau pembeli belum pernah mengirim kartu pesanan. Hanya angka ID, bukan
 * nama/alamat pembeli.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('buyer_user_id')->nullable()->after('order_sn');
            $table->index(['shop_id', 'buyer_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('shopee_orders', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'buyer_user_id']);
            $table->dropColumn('buyer_user_id');
        });
    }
};
