<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();

            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('creator_user_id');
            $table->unsignedBigInteger('assignee_user_id')->nullable();

            $table->enum('status', ['open', 'in_progress', 'done', 'cancelled'])->default('open');
            $table->enum('priority', ['low', 'normal', 'high'])->default('normal');

            $table->date('due_date')->nullable();
            $table->dateTime('done_at')->nullable();

            // Polymorphic link ke dokumen lain (PurchaseInvoice, SalesOrder, dll)
            $table->string('taskable_type')->nullable();
            $table->unsignedBigInteger('taskable_id')->nullable();

            $table->enum('source', ['manual', 'scheduled', 'event'])->default('manual');
            $table->unsignedBigInteger('schedule_id')->nullable();

            $table->integer('position')->default(0);

            $table->timestamps();

            $table->foreign('category_id')->references('id')->on('task_categories')->nullOnDelete();
            $table->foreign('creator_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('assignee_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('schedule_id')->references('id')->on('task_schedules')->nullOnDelete();

            $table->index(['category_id', 'position']);
            $table->index(['assignee_user_id', 'status']);
            $table->index(['taskable_type', 'taskable_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
