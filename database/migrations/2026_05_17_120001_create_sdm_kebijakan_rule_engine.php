<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sdm_kebijakan_kolom', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('label', 100);
            $table->enum('tipe', ['rupiah', 'persen', 'flag'])->default('rupiah');
            $table->integer('urutan')->default(100);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('sdm_kebijakan_rule', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 200);
            $table->text('deskripsi')->nullable();
            $table->integer('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->json('conditions');
            $table->json('effects');
            $table->timestamps();
            $table->index(['is_active', 'priority']);
        });

        $now = now();

        DB::table('sdm_kebijakan_kolom')->insert([
            [
                'key'        => 'tunjangan_harian',
                'label'      => 'Tunjangan Harian',
                'tipe'       => 'rupiah',
                'urutan'     => 10,
                'is_system'  => true,
                'is_active'  => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key'        => 'bonus_harian',
                'label'      => 'Bonus Harian',
                'tipe'       => 'rupiah',
                'urutan'     => 20,
                'is_system'  => true,
                'is_active'  => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('sdm_kebijakan_rule')->insert([
            [
                'nama'      => 'Tunjangan Harian untuk Hadir & Lembur',
                'deskripsi' => 'Status hadir atau lembur mendapat tunjangan harian sesuai nominal di master karyawan.',
                'priority'  => 10,
                'is_active' => true,
                'conditions' => json_encode([
                    ['field' => 'status', 'op' => 'in', 'value' => ['hadir', 'lembur']],
                ]),
                'effects' => json_encode([
                    ['kolom' => 'tunjangan_harian', 'kind' => 'tunjangan_karyawan', 'value' => null],
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nama'      => 'Bonus Kehadiran Tepat Waktu (scan ≤ 08:00)',
                'deskripsi' => 'Bonus diberikan jika status hadir dan scan masuk tidak melebihi jam 08:00.',
                'priority'  => 20,
                'is_active' => true,
                'conditions' => json_encode([
                    ['field' => 'status',            'op' => 'eq',  'value' => 'hadir'],
                    ['field' => 'scan_masuk_menit', 'op' => 'lte', 'value' => '08:00'],
                ]),
                'effects' => json_encode([
                    ['kolom' => 'bonus_harian', 'kind' => 'bonus_karyawan', 'value' => null],
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sdm_kebijakan_rule');
        Schema::dropIfExists('sdm_kebijakan_kolom');
    }
};
