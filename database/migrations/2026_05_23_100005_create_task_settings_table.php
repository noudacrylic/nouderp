<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('task_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('printing_default_user_id')->nullable();
            $table->timestamps();

            $table->foreign('printing_default_user_id')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_settings');
    }
};
