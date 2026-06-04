<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('production_step_executor_status', function (Blueprint $table) {
            $table->id();
            $table->foreignId('step_id')->constrained('production_order_steps')->cascadeOnDelete();
            $table->foreignId('executor_id')->constrained('production_department_executors')->cascadeOnDelete();
            $table->foreignId('karyawan_id')->nullable()->constrained('sdm_karyawan')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('paused_at')->nullable();
            $table->string('paused_reason', 100)->nullable();
            $table->timestamps();

            $table->unique(['step_id', 'executor_id'], 'pses_step_exec_uniq');
            $table->index('karyawan_id', 'pses_karyawan_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_step_executor_status');
    }
};
