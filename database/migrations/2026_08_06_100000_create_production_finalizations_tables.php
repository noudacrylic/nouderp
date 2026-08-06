<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penyelesaian Partial Produksi.
 *
 * Sebelumnya 1 order produksi = 1 kali finalisasi, sehingga jurnal & FIFO layer cukup
 * ditandai dengan production_order_id saja. Dengan partial, satu order bisa melepas
 * hasil beberapa kali (batch), jadi tiap pelepasan perlu identitasnya sendiri supaya
 * pembatalan bisa dilakukan per batch (LIFO) tanpa menyentuh batch lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_finalizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->unsignedInteger('sequence');                       // urutan batch: 1, 2, 3...
            $table->boolean('is_closing')->default(false);             // batch penutup → menyapu sisa WIP
            $table->decimal('wip_released', 18, 4)->default(0);        // biaya WIP yang keluar di batch ini
            $table->decimal('wip_total_snapshot', 18, 4)->default(0);  // WIP keseluruhan OP saat batch dibuat (audit)
            $table->unsignedBigInteger('journal_id')->nullable();      // jurnal Dr. Persediaan / Cr. WIP
            $table->unsignedBigInteger('void_journal_id')->nullable(); // jurnal balik saat batch dibatalkan
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['production_order_id', 'sequence']);
            $table->index(['production_order_id', 'voided_at']);
        });

        Schema::create('production_finalization_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_finalization_id')->constrained('production_finalizations')->cascadeOnDelete();
            $table->foreignId('production_order_output_id')->constrained('production_order_outputs')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->decimal('qty', 18, 4)->default(0);
            $table->decimal('cost', 18, 4)->default(0);       // porsi WIP untuk baris ini
            $table->decimal('unit_cost', 18, 4)->default(0);  // cost / qty → HPP layer FIFO
            $table->decimal('percentage', 9, 4)->nullable();  // hanya untuk produk sampingan
            $table->json('warehouse_allocations')->nullable();
            $table->string('variance_notes')->nullable();
            $table->timestamps();

            $table->index('production_order_output_id');
        });

        // Penanda batch pada layer FIFO output, supaya pembatalan batch hanya menghapus
        // layer milik batch itu (source_id = production_order_id dipakai bersama semua batch).
        Schema::table('stock_layers', function (Blueprint $table) {
            $table->unsignedBigInteger('production_finalization_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('stock_layers', function (Blueprint $table) {
            $table->dropColumn('production_finalization_id');
        });

        Schema::dropIfExists('production_finalization_items');
        Schema::dropIfExists('production_finalizations');
    }
};
