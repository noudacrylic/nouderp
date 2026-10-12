<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rincian potongan marketplace per pesanan (biaya admin, affiliate, pajak, saldo masuk).
 * Sumber `jubelio`: Shopee yang sudah selesai membawa `escrow_list` lengkap (final), sebelum
 * selesai hanya angka global (estimasi); TikTok baru terisi ±10 hari setelah selesai dan biaya
 * + pajaknya digabung. Sumber `laporan`: file laporan penghasilan marketplace yang diunggah di
 * Rekonsiliasi (TikTok/Tokopedia: rincian lengkap termasuk affiliate & PPh 22). Tanpa keduanya =
 * estimasi dari setingan integrasi, yang tidak disimpan di sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_order_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->unique()->constrained('sales_orders')->cascadeOnDelete();
            $table->decimal('kotor', 18, 2)->default(0);
            $table->decimal('admin', 18, 2)->default(0);
            $table->json('admin_rincian')->nullable();
            $table->decimal('affiliate', 18, 2)->default(0);
            $table->decimal('pajak', 18, 2)->default(0);
            $table->decimal('lain', 18, 2)->default(0);
            $table->decimal('saldo_masuk', 18, 2)->default(0);
            $table->string('sumber', 20)->default('jubelio');
            $table->boolean('final')->default(false);
            $table->boolean('pajak_asli')->default(false);
            $table->timestamp('diambil_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_order_fees');
    }
};
