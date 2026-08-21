<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kuota Produksi pindah basis: dari per DIVISI ke per SLOT.
 *
 * Asumsinya juga bergeser. Sebelumnya "berapa persen kapasitas terpakai"; sekarang langsung pada
 * jamnya — "kalau Reza kerja 8 jam 5 hari, HPP jadi berapa". Lebih konkret dan tidak menuntut
 * orang menerjemahkan persen ke jam di kepalanya.
 *
 * `production_quota_slots` juga menampung slot yang BELUM ADA (executor_id null): itulah cara
 * menjawab "kalau beli mesin keempat, HPP jadi berapa" tanpa harus mendaftarkan mesin fiktif ke
 * data produksi yang sesungguhnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Asumsi per divisi tidak dipakai lagi — penggantinya per slot.
        Schema::dropIfExists('production_quota_settings');

        Schema::create('production_quota_slots', function (Blueprint $table) {
            $table->id();
            // Null = slot pengandaian (mesin/orang yang belum ada).
            $table->foreignId('executor_id')->nullable()->constrained('production_department_executors')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('production_departments')->cascadeOnDelete();
            $table->string('label', 100)->nullable();
            $table->decimal('assumed_hours_per_day', 5, 2)->nullable();
            $table->decimal('assumed_working_days', 5, 2)->nullable();
            $table->boolean('use_assumption')->default(false);
            $table->timestamps();

            $table->unique('executor_id');
        });

        /**
         * Hari yang tidak boleh ikut merata-rata. Bukan karena angkanya jelek, tapi karena
         * datanya RUSAK dan tidak bisa direkonstruksi — memaksakannya masuk berarti menghukum
         * pabrik atas hari yang kita sudah tahu tidak terekam.
         */
        Schema::create('production_quota_excluded_dates', function (Blueprint $table) {
            $table->id();
            $table->date('tanggal')->unique();
            $table->string('reason', 255);
            $table->timestamps();
        });

        DB::table('production_quota_excluded_dates')->insert([
            [
                'tanggal' => '2026-06-28', 'created_at' => now(), 'updated_at' => now(),
                'reason'  => 'Andi masuk hari Minggu tapi timer menolak jalan (jadwal libur) — nol log produksi.',
            ],
            [
                'tanggal' => '2026-07-19', 'created_at' => now(), 'updated_at' => now(),
                'reason'  => 'Andi, Novan, Reza, Ridwan masuk sehari penuh hari Minggu; timer menolak jalan — nol log produksi.',
            ],
            [
                'tanggal' => '2026-08-09', 'created_at' => now(), 'updated_at' => now(),
                'reason'  => 'Andi tukar hari, bekerja penuh, tapi timer menolak jalan — nol log produksi.',
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('production_quota_excluded_dates');
        Schema::dropIfExists('production_quota_slots');

        Schema::create('production_quota_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->unique()->constrained('production_departments')->cascadeOnDelete();
            $table->unsignedSmallInteger('assumed_slots')->nullable();
            $table->decimal('assumed_hours_per_day', 5, 2)->nullable();
            $table->decimal('assumed_working_days', 5, 2)->nullable();
            $table->boolean('use_assumption')->default(false);
            $table->timestamps();
        });
    }
};
