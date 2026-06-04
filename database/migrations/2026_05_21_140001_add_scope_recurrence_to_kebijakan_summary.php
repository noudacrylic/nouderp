<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sdm_kebijakan_summary', function (Blueprint $table) {
            $table->enum('scope', ['all', 'per_karyawan'])
                ->default('all')
                ->after('arah')
                ->comment('all = nominal sama untuk semua karyawan; per_karyawan = input nominal per orang');
            $table->enum('recurrence', ['monthly', 'one_time'])
                ->default('monthly')
                ->after('scope')
                ->comment('monthly = tiap bulan; one_time = hanya di bulan/tahun tertentu');
        });

        Schema::create('sdm_kebijakan_summary_value', function (Blueprint $table) {
            $table->id();
            $table->foreignId('summary_id')->constrained('sdm_kebijakan_summary')->cascadeOnDelete();
            $table->foreignId('karyawan_id')->nullable()->constrained('sdm_karyawan')->cascadeOnDelete();
            $table->unsignedTinyInteger('bulan')->nullable();
            $table->unsignedSmallInteger('tahun')->nullable();
            $table->decimal('nominal', 15, 2)->default(0);
            $table->timestamps();

            $table->index(['summary_id', 'karyawan_id', 'bulan', 'tahun'], 'idx_summary_value_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sdm_kebijakan_summary_value');
        Schema::table('sdm_kebijakan_summary', function (Blueprint $table) {
            $table->dropColumn(['scope', 'recurrence']);
        });
    }
};
