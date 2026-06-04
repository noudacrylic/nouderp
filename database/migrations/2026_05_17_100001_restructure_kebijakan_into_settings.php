<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('sdm_kebijakan');

        Schema::create('sdm_kebijakan_setting', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->json('value');
            $table->string('label', 200)->nullable();
            $table->text('deskripsi')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('sdm_kebijakan_setting')->insert([
            [
                'key'       => 'tunjangan_harian_per_status',
                'label'     => 'Tunjangan Harian per Status',
                'deskripsi' => 'Status absensi mana yang berhak menerima tunjangan harian. Status hadir & setengah_hari (akibat tukar) tetap dapat; telat/cuti/sakit tidak.',
                'value'     => json_encode([
                    'hadir'         => true,
                    'terlambat'     => false,
                    'pulang_awal'   => false,
                    'setengah_hari' => false,
                    'cuti'          => false,
                    'sakit'         => false,
                    'libur'         => false,
                    'tidak_hadir'   => false,
                    'lembur'        => true,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key'       => 'bonus_kehadiran',
                'label'     => 'Bonus Kehadiran Tepat Waktu',
                'deskripsi' => 'Bonus jika scan masuk ≤ threshold jam (default 08:00). Berlaku hanya untuk hari dengan gaji penuh.',
                'value'     => json_encode([
                    'enabled'        => true,
                    'threshold_time' => '08:00',
                    'amount'         => 11000,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key'       => 'tunjangan_bulanan_sp',
                'label'     => 'Tunjangan Bulanan per SP',
                'deskripsi' => 'Nominal tunjangan bulanan berdasarkan status SP karyawan.',
                'value'     => json_encode([
                    'SP0' => 200000,
                    'SP1' => 100000,
                    'SP2' => 0,
                    'SP3' => 0,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key'       => 'threshold_absensi',
                'label'     => 'Threshold Status Absensi',
                'deskripsi' => 'Jam batas untuk menentukan status hadir/terlambat/setengah hari.',
                'value'     => json_encode([
                    'jam_kerja_mulai'            => '08:00',
                    'jam_kerja_selesai'          => '16:00',
                    'batas_terlambat'            => '08:10',
                    'batas_setengah_hari_masuk'  => '10:30',
                    'batas_setengah_hari_pulang' => '14:00',
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sdm_kebijakan_setting');

        Schema::create('sdm_kebijakan', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 150);
            $table->text('deskripsi')->nullable();
            $table->enum('trigger_field', ['late_minutes','early_out_minutes','absent_days_month','overtime_hours_day','scan_in_before']);
            $table->enum('operator', ['>','>=','<','<=','=']);
            $table->string('trigger_value', 50);
            $table->enum('action', ['no_tunjangan_harian','no_bonus_absen','no_full_pay','half_pay','flag_sp_pertimbangan','add_bonus_absen']);
            $table->decimal('action_amount', 15, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->integer('priority')->default(100);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['is_active', 'priority']);
        });
    }
};
