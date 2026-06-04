<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sdm_slip_gaji', function (Blueprint $table) {
            // Potongan fix (sistem, terkait jurnal)
            $table->decimal('bpjs_kesehatan_amount', 15, 2)->default(0)->after('cuti_unused_amount');
            $table->decimal('bpjs_tk_amount', 15, 2)->default(0)->after('bpjs_kesehatan_amount');
            $table->decimal('pph21_amount', 15, 2)->default(0)->after('bpjs_tk_amount');

            // Aggregate komponen manual (earnings ad-hoc)
            $table->decimal('total_komponen_earning', 15, 2)->default(0)->after('pph21_amount');
            $table->decimal('total_potongan', 15, 2)->default(0)->after('total_komponen_earning');

            // Tracking pembayaran
            $table->decimal('total_dibayar', 15, 2)->default(0)->after('total_gaji');
            $table->decimal('sisa_dibayar', 15, 2)->default(0)->after('total_dibayar');
            $table->enum('payment_status', ['unpaid', 'partial', 'paid'])->default('unpaid')->after('sisa_dibayar');
        });
    }

    public function down(): void
    {
        Schema::table('sdm_slip_gaji', function (Blueprint $table) {
            $table->dropColumn([
                'bpjs_kesehatan_amount', 'bpjs_tk_amount', 'pph21_amount',
                'total_komponen_earning', 'total_potongan',
                'total_dibayar', 'sisa_dibayar', 'payment_status',
            ]);
        });
    }
};
