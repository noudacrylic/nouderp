<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salinan pengajuan retur Shopee (get_return_list) — ditarik tiap 15 menit supaya kartu retur
 * di Meja Kerja (sinkron tiap 2 menit) cukup membaca database, tanpa memanggil API Shopee.
 * seller_due_at = batas respons penjual (return_seller_due_date) → tenggat kartu "Periksa".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_returns', function (Blueprint $table) {
            $table->id();
            $table->string('env', 10);
            $table->unsignedBigInteger('shop_id');
            $table->string('return_sn', 40);
            $table->string('order_sn', 40);
            $table->string('status', 30);
            $table->string('reason', 60)->nullable();
            $table->text('text_reason')->nullable();
            $table->decimal('refund_amount', 15, 2)->nullable();
            $table->boolean('needs_logistics')->default(false);
            $table->string('negotiation_status', 40)->nullable();
            $table->string('seller_proof_status', 40)->nullable();
            $table->string('seller_compensation_status', 40)->nullable();
            $table->timestamp('seller_due_at')->nullable();
            $table->timestamp('dibuat_shopee_at')->nullable();
            $table->timestamp('diperbarui_shopee_at')->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('disinkron_at')->nullable();
            $table->timestamps();

            $table->unique(['env', 'shop_id', 'return_sn']);
            $table->index('order_sn');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_returns');
    }
};
