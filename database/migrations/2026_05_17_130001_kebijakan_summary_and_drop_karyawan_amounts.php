<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1) Tabel baru: baris summary
        Schema::create('sdm_kebijakan_summary', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('label', 120);
            $table->integer('urutan')->default(100);
            $table->enum('mode', ['manual', 'auto'])->default('manual')->comment('manual = nominal_manual; auto = compute dari rule');
            $table->decimal('nominal_manual', 15, 2)->default(0);
            $table->enum('arah', ['plus', 'minus'])->default('plus')->comment('plus = ditambahkan; minus = potongan (mis. kasbon)');
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();

        DB::table('sdm_kebijakan_summary')->insert([
            [
                'key' => 'tunjangan_bulanan', 'label' => 'Tunjangan Bulanan', 'urutan' => 10,
                'mode' => 'auto', 'nominal_manual' => 0, 'arah' => 'plus',
                'is_system' => false, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'key' => 'bonus_lain', 'label' => 'Bonus Lain', 'urutan' => 20,
                'mode' => 'manual', 'nominal_manual' => 0, 'arah' => 'plus',
                'is_system' => false, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'key' => 'thr_hak_cuti', 'label' => 'THR / Hak Cuti tidak terpakai', 'urutan' => 30,
                'mode' => 'manual', 'nominal_manual' => 0, 'arah' => 'plus',
                'is_system' => false, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'key' => 'kasbon', 'label' => 'Pembayaran Kasbon', 'urutan' => 90,
                'mode' => 'manual', 'nominal_manual' => 0, 'arah' => 'minus',
                'is_system' => false, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'key' => 'total_dibayarkan', 'label' => 'Total Gaji Yang Dibayarkan', 'urutan' => 999,
                'mode' => 'manual', 'nominal_manual' => 0, 'arah' => 'plus',
                'is_system' => true, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);

        // 2) Ambil dulu nominal default dari karyawan (untuk migrate rule kind tunjangan_karyawan/bonus_karyawan ke nominal)
        $defaultTunj  = (float) (DB::table('sdm_karyawan')->where('is_active', true)->max('tunjangan_harian') ?? 25000);
        $defaultBonus = (float) (DB::table('sdm_karyawan')->where('is_active', true)->max('bonus_absen_harian') ?? 11000);
        if ($defaultTunj  <= 0) $defaultTunj  = 25000;
        if ($defaultBonus <= 0) $defaultBonus = 11000;

        // 3) Migrate effect kind di existing rules → nominal
        foreach (DB::table('sdm_kebijakan_rule')->get() as $rule) {
            $effects = json_decode($rule->effects, true) ?? [];
            $changed = false;
            foreach ($effects as &$eff) {
                if (($eff['kind'] ?? null) === 'tunjangan_karyawan') {
                    $eff['kind']  = 'nominal';
                    $eff['value'] = $defaultTunj;
                    $changed = true;
                } elseif (($eff['kind'] ?? null) === 'bonus_karyawan') {
                    $eff['kind']  = 'nominal';
                    $eff['value'] = $defaultBonus;
                    $changed = true;
                }
            }
            unset($eff);
            if ($changed) {
                DB::table('sdm_kebijakan_rule')->where('id', $rule->id)->update([
                    'effects'    => json_encode($effects),
                    'updated_at' => $now,
                ]);
            }
        }

        // 4) Seed rule by-SP untuk tunjangan_bulanan summary (replicate behavior K_TUNJANGAN_SP setting)
        $oldSp = DB::table('sdm_kebijakan_setting')->where('key', 'tunjangan_bulanan_sp')->value('value');
        $oldSpArr = $oldSp ? (json_decode($oldSp, true) ?: []) : ['SP0' => 200000, 'SP1' => 100000, 'SP2' => 0, 'SP3' => 0];
        $priority = 100;
        foreach ($oldSpArr as $sp => $nominal) {
            if ((float) $nominal <= 0) continue;
            DB::table('sdm_kebijakan_rule')->insert([
                'nama'      => "Tunjangan Bulanan untuk {$sp}",
                'deskripsi' => "Auto-generated dari pengaturan SP lama. Nominal = " . (int) $nominal . " untuk karyawan dengan sanksi {$sp}.",
                'priority'  => $priority,
                'is_active' => true,
                'conditions'=> json_encode([
                    ['field' => 'sanksi', 'op' => 'eq', 'value' => $sp],
                ]),
                'effects'   => json_encode([
                    ['kolom' => 'tunjangan_bulanan', 'kind' => 'nominal', 'value' => (float) $nominal],
                ]),
                'created_at'=> $now,
                'updated_at'=> $now,
            ]);
            $priority++;
        }

        // 5) Hapus setting lama yang sudah jadi rule / di-handle engine
        DB::table('sdm_kebijakan_setting')->whereIn('key', [
            'tunjangan_harian_per_status',
            'bonus_kehadiran',
            'tunjangan_bulanan_sp',
            'threshold_absensi',
        ])->delete();

        // 6) Drop 3 kolom nominal di karyawan
        Schema::table('sdm_karyawan', function (Blueprint $table) {
            $table->dropColumn(['tunjangan_harian', 'tunjangan_bulanan', 'bonus_absen_harian']);
        });
    }

    public function down(): void
    {
        Schema::table('sdm_karyawan', function (Blueprint $table) {
            $table->decimal('tunjangan_harian', 15, 2)->default(0);
            $table->decimal('tunjangan_bulanan', 15, 2)->default(0);
            $table->decimal('bonus_absen_harian', 15, 2)->default(0);
        });

        Schema::dropIfExists('sdm_kebijakan_summary');

        // Setting lama TIDAK di-restore (one-way migration)
    }
};
