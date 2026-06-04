<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_order_step_executors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('step_id')->constrained('production_order_steps')->cascadeOnDelete();
            $table->foreignId('executor_id')->constrained('production_department_executors')->cascadeOnDelete();
            $table->timestamp('joined_at')->useCurrent();
            $table->unique(['step_id', 'executor_id']);
        });

        // Migrasi data executor_id lama ke pivot table
        DB::statement("
            INSERT INTO production_order_step_executors (step_id, executor_id, joined_at)
            SELECT id, executor_id, COALESCE(started_at, NOW())
            FROM production_order_steps
            WHERE executor_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('production_order_step_executors');
    }
};
