<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sdm_attendance_settings', function (Blueprint $table) {
            $table->id();
            $table->integer('late_threshold_minutes')->default(10)->comment('Toleransi terlambat (menit) sejak jam masuk');
            $table->integer('setengah_hari_late_minutes')->default(150)->comment('Telat > N menit dari jam masuk = setengah hari');
            $table->integer('pulang_awal_min_minutes')->default(120)->comment('Pulang < N menit sebelum jam pulang = setengah hari');
            $table->timestamps();
        });

        DB::table('sdm_attendance_settings')->insert([
            'late_threshold_minutes'     => 10,
            'setengah_hari_late_minutes' => 150,
            'pulang_awal_min_minutes'    => 120,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sdm_attendance_settings');
    }
};
