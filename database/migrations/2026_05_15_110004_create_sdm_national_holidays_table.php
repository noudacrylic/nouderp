<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sdm_national_holidays', function (Blueprint $table) {
            $table->id();
            $table->date('tanggal');
            $table->string('nama', 200);
            $table->boolean('is_cuti_bersama')->default(false);
            $table->text('catatan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('tanggal');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sdm_national_holidays');
    }
};
