<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sdm_karyawan', function (Blueprint $table) {
            $table->id();
            $table->string('staf_code', 30)->unique();
            $table->string('name', 150);
            $table->foreignId('department_id')->constrained('production_departments')->restrictOnDelete();
            $table->string('jabatan', 100)->nullable();

            $table->decimal('gaji_pokok', 15, 2)->default(0);
            $table->decimal('tunjangan_harian', 15, 2)->default(0);
            $table->decimal('tunjangan_bulanan', 15, 2)->default(0)->comment('0 = pakai default sanksi');
            $table->tinyInteger('hari_kerja_per_bulan')->default(25);

            $table->date('mulai_kerja')->nullable();
            $table->enum('sanksi', ['SP0', 'SP1', 'SP2', 'SP3'])->default('SP0');
            $table->tinyInteger('hak_cuti')->default(12);
            $table->string('user_id_fingerprint', 50)->nullable()->index();

            $table->boolean('is_active')->default(true);
            $table->date('resign_date')->nullable();

            $table->string('hp', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->text('alamat')->nullable();
            $table->string('nik', 30)->nullable();
            $table->string('npwp', 30)->nullable();
            $table->string('bpjs', 30)->nullable();

            $table->string('bank_name', 50)->nullable();
            $table->string('bank_account_number', 50)->nullable();
            $table->string('bank_account_holder', 150)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['department_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sdm_karyawan');
    }
};
