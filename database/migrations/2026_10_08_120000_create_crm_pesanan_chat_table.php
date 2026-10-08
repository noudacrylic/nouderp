<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Tahap 3 — tautan chat ↔ pesanan.
 *
 * Dulu chat dan SO hanya bersinggungan lewat pelanggan (customer_id). Itu
 * tidak cukup untuk label otomatis: pesanan Shopee tercatat atas nama
 * pelanggan marketplace, bukan orang yang chat, dan satu pelanggan bisa
 * punya beberapa chat. Tautan ini menyebut terang-terangan "pesanan ini
 * dibicarakan di chat ini".
 *
 * Satu pesanan = satu chat (unique sales_order_id). Satu chat boleh punya
 * banyak pesanan; labelnya mengikuti pesanan TERBARU (disepakati 8 Okt 2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_pesanan_chat', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('crm_conversations')->cascadeOnDelete();
            $table->foreignId('sales_order_id')->unique()->constrained('sales_orders')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['conversation_id', 'sales_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_pesanan_chat');
    }
};
