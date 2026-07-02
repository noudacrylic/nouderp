<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_settlement_lines', function (Blueprint $table) {
            // Biaya admin marketplace yang SUDAH dibukukan di faktur (invoice.marketplace_fee).
            // Dipakai agar gross = nilai jual penuh & posting settlement hanya membukukan
            // selisih (fee aktual - fee tercatat), tidak dobel.
            $table->decimal('fee_prebooked', 18, 2)->default(0)->after('gross_amount');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_settlement_lines', function (Blueprint $table) {
            $table->dropColumn('fee_prebooked');
        });
    }
};
