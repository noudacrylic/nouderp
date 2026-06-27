<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengaturan Claude AI (Anthropic) — singleton (id=1). Diisi via
 * Settings → Integrasi → Claude AI, supaya tidak perlu edit .env di server.
 * .env (config services.anthropic) tetap jadi fallback.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('anthropic_settings', function (Blueprint $table) {
            $table->id();
            $table->string('api_key')->nullable();
            $table->string('model_text')->default('claude-sonnet-4-6');   // perintah teks
            $table->string('model_vision')->default('claude-opus-4-8');   // baca struk (Fase 3)
            $table->unsignedBigInteger('confirm_threshold')->default(100000); // di atas ini wajib konfirmasi
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anthropic_settings');
    }
};
