<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penilaian operator CNC (28 Nov 2026): tanggap > 30 menit masuk Meja Owner. Putusan owner
 * disimpan di potongan "potong" yang selesainya memulai tunggu itu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_mesin_keadaan', function (Blueprint $t) {
            $t->string('putusan', 10)->nullable()->after('dilihat_at'); // wajar | abaikan | curang
            $t->unsignedBigInteger('putusan_by')->nullable()->after('putusan');
            $t->dateTime('putusan_at')->nullable()->after('putusan_by');
        });
    }

    public function down(): void
    {
        Schema::table('production_mesin_keadaan', function (Blueprint $t) {
            $t->dropColumn(['putusan', 'putusan_by', 'putusan_at']);
        });
    }
};
