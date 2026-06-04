<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sdm_fingerprint_machines', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->string('serial_number', 60)->nullable()->unique();
            $table->string('ip_address', 45)->nullable();
            $table->unsignedSmallInteger('port')->default(5005);
            $table->string('model', 60)->nullable();
            $table->string('firmware_version', 60)->nullable();
            $table->string('location', 150)->nullable();
            $table->string('comm_key', 60)->nullable()->comment('Auth key untuk ADMS push (opsional)');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable()->comment('Kapan terakhir mesin contact server');
            $table->timestamp('last_sync_at')->nullable()->comment('Kapan terakhir sync log ke attendance');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sdm_fingerprint_machines');
    }
};
