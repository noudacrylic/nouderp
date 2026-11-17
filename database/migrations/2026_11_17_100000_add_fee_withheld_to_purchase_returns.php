<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biaya yang TIDAK dikembalikan pemasok saat retur faktur (mis. biaya layanan Shopee
 * yang hanya dikembalikan sebagian). Persediaan tetap keluar sebesar HPP; piutang ke
 * pemasok berkurang sebesar biaya ini dan selisihnya dibebankan ke `fee_account_id`.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->decimal('fee_withheld', 18, 2)->default(0)->after('refund_amount');
            $table->unsignedBigInteger('fee_account_id')->nullable()->after('fee_withheld');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->dropColumn(['fee_withheld', 'fee_account_id']);
        });
    }
};
