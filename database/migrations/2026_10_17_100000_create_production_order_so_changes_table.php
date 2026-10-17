<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat "Ganti SO" pada order produksi: SO lama void (mis. pesanan Shopee dibatalkan
 * lalu pelanggan pesan ulang lewat jalur manual) sementara produksinya sudah jalan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_order_so_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->foreignId('from_sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->foreignId('to_sales_order_id')->constrained('sales_orders');
            $table->string('reason', 255);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_order_so_changes');
    }
};
