<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('production_step_time_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_step_id')
                  ->constrained('production_order_steps')
                  ->cascadeOnDelete();
            $table->enum('event_type', [
                'started', 'paused', 'resumed', 'completed',
                'auto_paused', 'auto_resumed',
            ]);
            $table->datetime('occurred_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['production_order_step_id', 'occurred_at'], 'step_time_logs_step_occurred_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_step_time_logs');
    }
};
