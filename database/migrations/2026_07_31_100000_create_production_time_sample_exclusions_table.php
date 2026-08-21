<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sampel OP yang DIKECUALIKAN dari analisa rata-rata waktu produksi.
 *
 * Bersifat opt-out: semua OP layak otomatis jadi sampel; baris di tabel ini
 * adalah OP yang sengaja dibuang operator (timer lupa distop, dikerjakan saat
 * mode testing, dsb). Dengan begitu OP baru otomatis ikut hitungan tanpa perlu
 * dicentang manual, dan hasil analisa tetap dihitung on-the-fly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_time_sample_exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->unique()
                  ->constrained('production_orders')->cascadeOnDelete();
            $table->string('reason', 255)->nullable();
            $table->foreignId('excluded_by')->nullable()
                  ->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_time_sample_exclusions');
    }
};
