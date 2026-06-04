<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('task_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id');
            $table->string('linked_type');
            $table->unsignedBigInteger('linked_id');
            $table->string('label_snapshot')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->unique(['task_id', 'linked_type', 'linked_id'], 'task_links_unique');
            $table->index(['linked_type', 'linked_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_links');
    }
};
