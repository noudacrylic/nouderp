<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('production_department_executors', function (Blueprint $table) {
            $table->foreignId('karyawan_id')->nullable()->after('department_id')
                ->constrained('sdm_karyawan')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('production_department_executors', function (Blueprint $table) {
            $table->dropForeign(['karyawan_id']);
            $table->dropColumn('karyawan_id');
        });
    }
};
