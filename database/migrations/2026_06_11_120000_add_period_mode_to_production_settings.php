<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('production_settings', function (Blueprint $table) {
            // Mode periode kalkulasi score:
            //   'week'   = 7 hari terakhir
            //   'months' = N bulan terakhir (pakai score_sales_period: 1/2/3)
            //   'range'  = rentang tanggal tetap (pakai score_period_start/end)
            $table->string('score_period_mode', 16)->default('months')->after('score_sales_period');
            $table->date('score_period_start')->nullable()->after('score_period_mode');
            $table->date('score_period_end')->nullable()->after('score_period_start');
        });
    }

    public function down(): void
    {
        Schema::table('production_settings', function (Blueprint $table) {
            $table->dropColumn(['score_period_mode', 'score_period_start', 'score_period_end']);
        });
    }
};
