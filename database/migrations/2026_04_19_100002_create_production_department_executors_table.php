<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_department_executors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('production_departments')->cascadeOnDelete();
            $table->string('name');
            $table->string('role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_department_executors');
    }
};
