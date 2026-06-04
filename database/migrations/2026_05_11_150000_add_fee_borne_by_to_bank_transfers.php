<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transfers', function (Blueprint $table) {
            // 'source' = biaya dipotong dari kas/bank sumber (default, perilaku lama)
            // 'destination' = biaya dipotong dari kas/bank tujuan
            $table->string('fee_borne_by', 16)->default('source')->after('admin_fee');
        });
    }

    public function down(): void
    {
        Schema::table('bank_transfers', function (Blueprint $table) {
            $table->dropColumn('fee_borne_by');
        });
    }
};
