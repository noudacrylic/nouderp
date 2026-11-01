<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coret sampel waktu per LANGKAH (bukan per OP seperti production_time_sample_exclusions).
 *
 * Langkah yang pelaksananya diganti di tengah jalan (karyawan keluar / oper tugas) waktunya
 * tidak mewakili satu orang mengerjakan dari awal, jadi divisinya dibuang dari sampel OP
 * itu — divisi lain di OP yang sama tetap terpakai. Lihat PergantianPelaksanaService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_order_steps', function (Blueprint $table) {
            $table->string('sample_excluded_reason', 255)->nullable()->after('notes');
            $table->foreignId('sample_excluded_by')->nullable()->after('sample_excluded_reason')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('production_order_steps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sample_excluded_by');
            $table->dropColumn('sample_excluded_reason');
        });
    }
};
