<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sambungan Google Search Console lewat login Google (OAuth) — dipakai karena organisasi Google
 * Cloud memblokir kunci JSON service account (iam.disableServiceAccountKeyCreation, 21 Nov 2026).
 * Satu baris saja. client_secret & refresh_token dienkripsi (cast encrypted, APP_KEY).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_google_akun', function (Blueprint $t) {
            $t->id();
            $t->string('client_id', 255)->nullable();
            $t->text('client_secret')->nullable();
            $t->text('refresh_token')->nullable();
            $t->string('email', 255)->nullable();
            $t->timestamp('tersambung_at')->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_google_akun');
    }
};
