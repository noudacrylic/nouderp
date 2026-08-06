<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat revisi target produksi (qty & siklus rencana) pada OP yang sedang berjalan.
 *
 * Angka rencana dipakai sebagai PEMBAGI saat penyelesaian partial, jadi perubahannya
 * ikut menentukan HPP batch berikutnya — karena itu disimpan sebagai riwayat, bukan
 * sekadar menimpa kolom di production_orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_target_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->decimal('from_planned_qty', 18, 4)->default(0);
            $table->decimal('to_planned_qty', 18, 4)->default(0);
            $table->decimal('from_planned_cycles', 10, 4)->default(0);
            $table->decimal('to_planned_cycles', 10, 4)->default(0);
            $table->json('outputs_before')->nullable();  // [{output_id, qty_planned}]
            $table->json('outputs_after')->nullable();
            $table->string('reason');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            $table->index('production_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_target_revisions');
    }
};
