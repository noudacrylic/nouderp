<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('task_automation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('assignee_user_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('priority', 10)->default('normal');
            $table->string('title_template', 200)->nullable();
            $table->text('description_template')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_triggered_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['type', 'is_active']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_automation_rules');
    }
};
