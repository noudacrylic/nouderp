<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sdm_slip_gaji', function (Blueprint $table) {
            if (! Schema::hasColumn('sdm_slip_gaji', 'kasbon_payment')) {
                $table->decimal('kasbon_payment', 15, 2)->default(0)->after('pph21_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sdm_slip_gaji', function (Blueprint $table) {
            if (Schema::hasColumn('sdm_slip_gaji', 'kasbon_payment')) {
                $table->dropColumn('kasbon_payment');
            }
        });
    }
};
