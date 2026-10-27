<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QC Timbang menjadi langkah terakhir OP (23 Okt 2026).
 *
 * - production_order_steps.is_qc: langkah "QC Timbang" yang ditambahkan otomatis untuk OP
 *   yang hasilnya ber-profil QC. Timernya berjalan dari penimbangan pertama.
 * - production_settings.qc_department_id: divisi pelaksana QC. Kosong = ikut divisi langkah
 *   sebelumnya (sementara QC dikerjakan orang Assembling).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_order_steps', function (Blueprint $table) {
            $table->boolean('is_qc')->default(false)->after('department_id');
        });

        Schema::table('production_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('qc_department_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('production_order_steps', fn (Blueprint $table) => $table->dropColumn('is_qc'));
        Schema::table('production_settings', fn (Blueprint $table) => $table->dropColumn('qc_department_id'));
    }
};
