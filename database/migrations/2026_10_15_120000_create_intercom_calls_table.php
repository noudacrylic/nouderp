<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Log panggilan intercom: siapa memanggil siapa, kapan, berapa lama.
        // Suaranya TIDAK direkam.
        Schema::create('intercom_calls', function (Blueprint $table) {
            $table->id();
            $table->string('dari', 30);
            $table->string('ke', 30);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // tersambung | tak_terjawab | ditolak | sibuk | offline | gagal
            $table->string('status', 20);
            $table->timestamp('mulai_at');
            $table->unsignedInteger('durasi_detik')->default(0);
            $table->timestamps();

            $table->index('mulai_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intercom_calls');
    }
};
