<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sdm_karyawan_schedule', function (Blueprint $table) {
            $table->time('jam_masuk_lembur')->nullable()->after('jam_istirahat_end');
            $table->time('jam_pulang_lembur')->nullable()->after('jam_masuk_lembur');
            $table->smallInteger('late_in_minutes')->default(10)->after('jam_pulang_lembur');
            $table->smallInteger('early_out_minutes')->default(0)->after('late_in_minutes');
        });

        Schema::dropIfExists('sdm_attendance_settings');

        if (Schema::hasColumn('sdm_karyawan', 'lembur_slots')) {
            Schema::table('sdm_karyawan', function (Blueprint $table) {
                $table->dropColumn('lembur_slots');
            });
        }
    }

    public function down(): void
    {
        Schema::table('sdm_karyawan_schedule', function (Blueprint $table) {
            $table->dropColumn(['jam_masuk_lembur', 'jam_pulang_lembur', 'late_in_minutes', 'early_out_minutes']);
        });

        Schema::table('sdm_karyawan', function (Blueprint $table) {
            $table->json('lembur_slots')->nullable();
        });

        Schema::create('sdm_attendance_settings', function (Blueprint $table) {
            $table->id();
            $table->integer('late_threshold_minutes')->default(10);
            $table->integer('setengah_hari_late_minutes')->default(150);
            $table->integer('pulang_awal_min_minutes')->default(120);
            $table->timestamps();
        });
    }
};
