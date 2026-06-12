<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('production_settings', function (Blueprint $table) {
            // Mode testing: bila aktif, Mulai/lanjut task TIDAK butuh scan sidik jari
            // (bypass cek check-in & jam kerja). Untuk uji coba alur produksi.
            $table->boolean('testing_mode')->default(false)->after('score_period_end');
        });
    }

    public function down(): void
    {
        Schema::table('production_settings', function (Blueprint $table) {
            $table->dropColumn('testing_mode');
        });
    }
};
