<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OP yatim (skill Manager Produksi "produksi.op-yatim"): pesanan pre-order umum dibatalkan
 * saat OP-nya sudah dikerjakan → OP dilepas dari SO (sales_order_id null). Riwayatnya dicatat
 * di production_order_so_changes dengan to_sales_order_id NULL ("dilepas"), dan saat diambil
 * alih pesanan baru dengan from_sales_order_id NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_order_so_changes', function (Blueprint $table) {
            $table->foreignId('to_sales_order_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('production_order_so_changes', function (Blueprint $table) {
            $table->foreignId('to_sales_order_id')->nullable(false)->change();
        });
    }
};
