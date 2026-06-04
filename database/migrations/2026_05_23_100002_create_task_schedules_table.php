<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('task_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('assignee_user_id')->nullable();
            $table->enum('priority', ['low', 'normal', 'high'])->default('normal');

            $table->enum('frequency', ['daily', 'weekly', 'monthly', 'custom_cron']);
            $table->time('time_of_day')->nullable();
            $table->tinyInteger('day_of_week')->nullable();   // 0=Sunday .. 6=Saturday
            $table->tinyInteger('day_of_month')->nullable();  // 1..31
            $table->string('cron_expression')->nullable();

            $table->json('subtasks_template')->nullable();

            $table->boolean('is_active')->default(true);
            $table->dateTime('last_run_at')->nullable();
            $table->dateTime('next_run_at')->nullable();

            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('category_id')->references('id')->on('task_categories')->nullOnDelete();
            $table->foreign('assignee_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['is_active', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_schedules');
    }
};
