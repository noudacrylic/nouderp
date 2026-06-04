<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sdm_slip_gaji_komponen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slip_gaji_id')->constrained('sdm_slip_gaji')->cascadeOnDelete();
            $table->unsignedInteger('urutan')->default(0);
            $table->string('label', 100);
            $table->enum('metode', ['nominal', 'percentage'])->default('nominal');
            $table->decimal('nilai', 15, 4)->default(0);
            $table->enum('basis', ['gaji_pokok', 'gaji_pokok_dibayar', 'subtotal'])->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('slip_gaji_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sdm_slip_gaji_komponen');
    }
};
