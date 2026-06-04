<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boms', function (Blueprint $table) {
            $table->id();
            $table->string('bom_number')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('cycles_description')->nullable(); // keterangan "1 siklus = ..."
            $table->integer('score')->default(0);           // urutan/prioritas produksi
            $table->enum('status', ['draft', 'active', 'archived'])->default('draft');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boms');
    }
};
