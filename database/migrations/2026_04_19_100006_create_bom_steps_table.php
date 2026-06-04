<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bom_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_id')->constrained('boms')->cascadeOnDelete();
            $table->unsignedInteger('step_number');
            $table->foreignId('department_id')->nullable()->constrained('production_departments')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('estimated_hours', 8, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bom_steps');
    }
};
