<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Pengaturan jembatan API etalase (singleton). api_key = kunci rahasia
        // yang dipakai VPS/etalase untuk mengakses endpoint baca /api/storefront/*.
        Schema::create('storefront_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active')->default(false);
            $table->string('api_key')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_settings');
    }
};
