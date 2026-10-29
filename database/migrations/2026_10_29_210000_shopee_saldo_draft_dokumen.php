<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 1b Shopee — mutasi saldo ditarik lewat API, penarikan & isi ulang iklan jadi DRAFT:
 *  - bank_transfers.to_account_id boleh kosong: draft penarikan Shopee lahir tanpa tujuan
 *    (Shopee tidak menyebut banknya); admin memilih BCA/BRI/ShopeePay lalu posting.
 *  - shopee_wallet_transactions.dokumen_type/id: draft yang dibuat dari baris itu (atau dokumen
 *    lama yang sudah mencatatnya) — supaya satu baris tak pernah melahirkan dua draft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transfers', function (Blueprint $table) {
            $table->unsignedBigInteger('to_account_id')->nullable()->change();
        });

        Schema::table('shopee_wallet_transactions', function (Blueprint $table) {
            $table->string('dokumen_type', 30)->nullable()->after('cocok_catatan');
            $table->unsignedBigInteger('dokumen_id')->nullable()->after('dokumen_type');
            $table->string('dokumen_catatan', 255)->nullable()->after('dokumen_id');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_wallet_transactions', function (Blueprint $table) {
            $table->dropColumn(['dokumen_type', 'dokumen_id', 'dokumen_catatan']);
        });
    }
};
