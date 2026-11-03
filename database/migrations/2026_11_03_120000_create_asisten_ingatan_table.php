<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ingatan bot Telegram: aturan tetap dari user, permanen sampai dihapus lewat /lupa. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asisten_ingatan', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('isi', 300);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asisten_ingatan');
    }
};
