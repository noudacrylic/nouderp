<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Langganan Web Push per perangkat (PWA Karyawan). Satu user (akun login)
 * bisa punya beberapa langganan (HP + tablet, dll). Endpoint unik per device.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('endpoint', 500)->unique();
            $table->string('public_key', 255);          // p256dh
            $table->string('auth_token', 255);          // auth
            $table->string('content_encoding', 20)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
