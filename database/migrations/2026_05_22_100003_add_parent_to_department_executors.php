<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('production_department_executors', function (Blueprint $table) {
            $table->foreignId('parent_executor_id')
                ->nullable()
                ->after('karyawan_id')
                ->constrained('production_department_executors')
                ->nullOnDelete();
            $table->index('parent_executor_id', 'pde_parent_idx');
        });
    }

    public function down(): void
    {
        Schema::table('production_department_executors', function (Blueprint $table) {
            $table->dropForeign(['parent_executor_id']);
            $table->dropIndex('pde_parent_idx');
            $table->dropColumn('parent_executor_id');
        });
    }
};
