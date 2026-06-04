<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teks instruksi cara pembayaran yang tampil di Print SO (menggantikan kotak rekening).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->text('payment_instructions')->nullable()->after('quotation_payment_terms');
        });
    }

    public function down(): void
    {
        Schema::table('business_profiles', fn (Blueprint $t) => $t->dropColumn('payment_instructions'));
    }
};
