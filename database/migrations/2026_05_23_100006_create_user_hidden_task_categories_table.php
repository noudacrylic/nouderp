<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_hidden_task_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            // 0 = "Tanpa Kategori" virtual column; >0 = task_categories.id
            $table->unsignedBigInteger('task_category_id');
            $table->timestamps();

            $table->unique(['user_id', 'task_category_id'], 'uhtc_user_cat_unique');
            $table->index('user_id');

            $table->foreign('user_id')
                ->references('id')->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_hidden_task_categories');
    }
};
