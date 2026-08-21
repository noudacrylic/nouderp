<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengaturan biaya tetap untuk perhitungan HPP (Analisa → Biaya & Tarif Divisi).
 *
 * Buku besar tidak punya dimensi cost-center (journal_lines hanya punya account_id),
 * jadi pembagian biaya ke divisi dilakukan di luar GL lewat tiga tabel ini.
 * Gaji TIDAK ditampung di sini — diambil langsung dari slip gaji per karyawan
 * (sdm_slip_gaji → sdm_karyawan.department_id), jadi selalu ikut data terbaru.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Basis pembebanan biaya tiap divisi ke produk.
        Schema::create('production_cost_department_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->unique()
                  ->constrained('production_departments')->cascadeOnDelete();
            // waktu    : dibagi lewat tarif per detik × waktu produksi produk (divisi bertimer)
            // per_unit : dibagi rata per unit terkirim (mis. Packing — tidak punya timer)
            // abaikan  : bukan biaya produksi (mis. Admin)
            $table->enum('basis', ['waktu', 'per_unit', 'abaikan'])->default('abaikan');
            $table->timestamps();
        });

        // Pemetaan akun beban GL → divisi / pool pabrik, dengan porsi yang dianggap
        // biaya produksi (akun campuran kantor+pabrik dipotong persentasenya).
        Schema::create('production_cost_account_maps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->unique()
                  ->constrained('accounts')->cascadeOnDelete();
            // null = pool pabrik umum, dialokasikan ke divisi sesuai porsi jam kerja tersedia
            $table->foreignId('department_id')->nullable()
                  ->constrained('production_departments')->nullOnDelete();
            $table->decimal('percentage', 5, 2)->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Biaya tetap yang belum punya akun/aset di sistem (sewa gedung, listrik,
        // penyusutan mesin sebelum modul Aset Tetap diisi). Nilai per BULAN.
        Schema::create('production_cost_pools', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('department_id')->nullable()
                  ->constrained('production_departments')->nullOnDelete();
            $table->decimal('amount_monthly', 18, 2)->default(0);
            $table->string('notes', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_cost_pools');
        Schema::dropIfExists('production_cost_account_maps');
        Schema::dropIfExists('production_cost_department_settings');
    }
};
