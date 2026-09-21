<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ke mana uang retur dikembalikan, dan berapa.
 *
 * Sebelum ini tujuan dana tidak pernah ditanyakan: retur pelanggan marketplace SELALU
 * mengkredit Saldo Ditahan, pelanggan biasa SELALU jadi kredit pelanggan. Aturan itu benar
 * hanya selama dananya memang masih ditahan. Begitu pesanan selesai, saldo ditahan sudah
 * kosong — mengkreditnya lagi membuat akunnya MINUS, padahal uangnya sebetulnya dipotong dari
 * saldo penjualan, atau ditransfer dari bank, atau tidak dikembalikan sama sekali karena
 * pembelinya menukar barang.
 *
 * `refund_amount` adalah hasil NEGOSIASI, bukan turunan harga jual: pada retur setelah pesanan
 * selesai, biaya admin marketplace sudah hangus dan tidak dikembalikan platform, jadi yang
 * wajar dikembalikan adalah dana bersih yang benar-benar kita terima. Selisihnya membalik
 * beban admin — lihat SalesReturnService::hitungUang().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            // hold | wallet | bank | credit  (NULL = dokumen lama, sebelum tujuan dana ada)
            $table->string('refund_target', 20)->nullable()->after('return_type');
            $table->unsignedBigInteger('refund_account_id')->nullable()->after('refund_target');
            // Kredit boleh diarahkan ke pelanggan LAIN: pembeli marketplace yang menukar
            // barang adalah orang, sementara pelanggan di fakturnya adalah "Shopee".
            $table->unsignedBigInteger('refund_customer_id')->nullable()->after('refund_account_id');
            $table->decimal('refund_amount', 15, 2)->nullable()->after('refund_customer_id');
            $table->decimal('fee_reversed', 15, 2)->default(0)->after('refund_amount');
        });
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropColumn(['refund_target', 'refund_account_id', 'refund_customer_id', 'refund_amount', 'fee_reversed']);
        });
    }
};
