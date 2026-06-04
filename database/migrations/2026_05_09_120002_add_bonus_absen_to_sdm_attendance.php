<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sdm_attendance', function (Blueprint $table) {
            $table->boolean('get_bonus_absen')->default(false)->after('get_tunjangan');
        });
    }

    public function down(): void
    {
        Schema::table('sdm_attendance', function (Blueprint $table) {
            $table->dropColumn('get_bonus_absen');
        });
    }
};
