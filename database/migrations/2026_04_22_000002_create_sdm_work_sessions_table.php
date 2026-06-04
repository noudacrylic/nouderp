<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sdm_work_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('production_departments')->cascadeOnDelete();
            $table->string('name', 100);
            $table->enum('type', ['regular', 'overtime'])->default('regular');
            $table->time('start_time');
            $table->time('end_time');
            $table->json('days_of_week')->comment('Array [1..7], 1=Senin, 7=Minggu');
            $table->tinyInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sdm_work_sessions');
    }
};
