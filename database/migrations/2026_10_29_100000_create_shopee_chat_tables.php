<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat Shopee → CRM (Tahap A, baca saja).
 *
 * `shopee_chats` mencatat SEMUA percakapan Shopee, termasuk yang hanya dilayani AI Shopee.
 * Yang masuk CRM hanya percakapan yang `tampil` (pembeli bicara ke penjual / penjual
 * membalas) — keputusan user 29 Okt 2026: balasan AI disembunyikan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_chats', function (Blueprint $table) {
            $table->id();
            $table->string('env', 10);
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('to_id');
            $table->string('to_name')->nullable();
            $table->string('latest_message_id', 30)->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->boolean('tampil')->default(false);
            $table->foreignId('crm_conversation_id')->nullable()->constrained('crm_conversations')->nullOnDelete();
            $table->timestamps();

            $table->unique(['env', 'shop_id', 'conversation_id']);
        });

        Schema::table('shopee_shops', function (Blueprint $table) {
            // Penanda sinkron chat: waktu (nano) pesan terakhir yang sudah diproses, dan kursor
            // penarikan riwayat (mundur) supaya bisa dilanjutkan bila terputus.
            $table->string('chat_nano_terakhir', 25)->nullable();
            $table->string('chat_riwayat_kursor', 25)->nullable();
            $table->timestamp('chat_riwayat_selesai_at')->nullable();
        });

        Schema::table('shopee_settings', function (Blueprint $table) {
            $table->foreignId('chat_owner_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shopee_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chat_owner_user_id');
        });
        Schema::table('shopee_shops', function (Blueprint $table) {
            $table->dropColumn(['chat_nano_terakhir', 'chat_riwayat_kursor', 'chat_riwayat_selesai_at']);
        });
        Schema::dropIfExists('shopee_chats');
    }
};
