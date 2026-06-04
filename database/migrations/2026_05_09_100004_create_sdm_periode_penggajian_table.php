<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sdm_periode_penggajian', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->tinyInteger('bulan');
            $table->smallInteger('tahun');
            $table->string('label', 100);
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['draft', 'imported', 'finalized', 'void'])->default('draft');
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['bulan', 'tahun']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sdm_periode_penggajian');
    }
};
