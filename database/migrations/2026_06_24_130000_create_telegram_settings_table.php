<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengaturan bot Telegram ("Noud Bot") — singleton (id=1).
 * Token & username diisi via Settings → Integrasi → Telegram (BotFather).
 * webhook_secret dipakai sebagai segmen rahasia di URL webhook publik.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('telegram_settings', function (Blueprint $table) {
            $table->id();
            $table->string('bot_token')->nullable();
            $table->string('bot_username')->nullable();   // tanpa '@', untuk deep-link t.me/<username>
            $table->string('webhook_secret')->nullable(); // segmen URL webhook + secret_token header
            $table->string('admin_chat_id')->nullable();  // grup/akun penampung notifikasi approval
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_settings');
    }
};
