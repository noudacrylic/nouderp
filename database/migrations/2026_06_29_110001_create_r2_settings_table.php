<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('r2_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active')->default(false);
            $table->string('access_key_id')->nullable();
            $table->text('secret_access_key')->nullable();   // disimpan terenkripsi
            $table->string('bucket')->nullable();
            $table->string('endpoint')->nullable();          // https://<account>.r2.cloudflarestorage.com
            $table->string('public_url')->nullable();        // domain publik bucket (custom / r2.dev)
            $table->string('region')->default('auto');
            $table->boolean('use_path_style')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('r2_settings');
    }
};
