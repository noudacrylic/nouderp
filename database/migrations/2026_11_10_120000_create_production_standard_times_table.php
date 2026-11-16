<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Waktu standar kerja per SKU per divisi (menit/pcs) — dasar penilaian kinerja produksi.
 *
 * Satu baris = satu keputusan owner yang berlaku mulai tanggal tertentu. Baris lama tidak
 * ditimpa, supaya nilai bulan lalu tetap dihitung dengan standar yang berlaku saat itu
 * (standar ditinjau tiap kuartal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_standard_times', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('department_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('minutes_per_unit', 10, 3);
            // median = dari usulan riwayat; manual = diisi owner (SKU jarang / koreksi).
            $table->string('source', 10)->default('manual');
            $table->unsignedInteger('sample_count')->nullable();
            $table->decimal('suggested_minutes', 10, 3)->nullable();
            $table->date('effective_from');
            $table->string('notes')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamps();

            $table->unique(['department_id', 'product_id', 'effective_from'], 'pst_dept_produk_berlaku_unique');
            $table->index(['department_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_standard_times');
    }
};
