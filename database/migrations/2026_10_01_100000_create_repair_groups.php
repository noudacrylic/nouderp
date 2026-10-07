<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kelompok Perbaikan — barang di Gudang Perbaikan (asal retur & opname) dikumpulkan operator
 * dulu, baru satu kelompok dibuatkan SATU OP Perbaikan. Kelompok tidak memindah stok & tidak
 * menjurnal; ia hanya memesan qty supaya satu unit tak masuk dua kelompok.
 *
 * Sekalian dua kolom "gagal": unit yang ternyata gagal diperbaiki saat finalisasi tidak masuk
 * stok, nilainya (HPP awal + porsi biaya perbaikan) dibebankan ke 6105.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_groups', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('name');
            // terbuka → di_op (dipakai OP) → selesai (OP difinalisasi); batal = dibatalkan operator.
            $table->string('status', 10)->default('terbuka')->index();
            $table->foreignId('production_order_id')->nullable()->constrained('production_orders')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('repair_group_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repair_group_id')->constrained('repair_groups')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->decimal('qty', 18, 4);
            $table->timestamps();
            $table->unique(['repair_group_id', 'product_id']);
        });

        Schema::table('production_order_outputs', function (Blueprint $table) {
            $table->decimal('qty_failed', 18, 4)->default(0)->after('qty_produced');
        });

        Schema::table('production_finalization_items', function (Blueprint $table) {
            $table->decimal('qty_failed', 18, 4)->default(0)->after('qty');
            $table->decimal('cost_failed', 18, 4)->default(0)->after('cost');
        });
    }

    public function down(): void
    {
        Schema::table('production_finalization_items', function (Blueprint $table) {
            $table->dropColumn(['qty_failed', 'cost_failed']);
        });
        Schema::table('production_order_outputs', function (Blueprint $table) {
            $table->dropColumn('qty_failed');
        });
        Schema::dropIfExists('repair_group_items');
        Schema::dropIfExists('repair_groups');
    }
};
