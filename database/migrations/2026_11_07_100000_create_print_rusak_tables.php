<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Print rusak (7 Nov 2026): lembar bahan print (stiker vynil/chromo) yang rusak saat dicetak —
 * kebanyakan paperjam. Stok bahan langsung keluar (FIFO), jurnal Dr 5007 Beban Overhead
 * Produksi / Cr 1130 Persediaan → tetap masuk HPP, tidak ditempel ke OP tertentu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_rusak', function (Blueprint $t) {
            $t->id();
            $t->string('nomor', 30)->unique();
            $t->date('tanggal')->nullable();
            $t->foreignId('product_id')->constrained('products');
            $t->foreignId('warehouse_id')->constrained('warehouses');
            $t->decimal('qty', 12, 4);
            $t->decimal('nilai', 15, 2)->default(0);
            $t->string('sebab', 20);
            $t->string('catatan', 500)->nullable();
            // Hanya keterangan (rekap) — biayanya tidak masuk WIP OP ini.
            $t->foreignId('production_order_id')->nullable()->constrained('production_orders')->nullOnDelete();
            $t->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('voided_at')->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['tanggal', 'voided_at']);
        });

        // Bahan yang boleh dilaporkan rusak — diatur di halaman Print rusak.
        Schema::create('print_rusak_bahan', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $t->timestamps();
        });

        $ids = DB::table('products')->whereIn('sku', ['PRD00006', 'PRD00007'])->pluck('id');
        foreach ($ids as $id) {
            DB::table('print_rusak_bahan')->insert(['product_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('print_rusak_bahan');
        Schema::dropIfExists('print_rusak');
    }
};
