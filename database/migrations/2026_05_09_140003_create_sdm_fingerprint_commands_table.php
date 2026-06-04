<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sdm_fingerprint_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained('sdm_fingerprint_machines')->cascadeOnDelete();
            $table->text('command')->comment('Raw ADMS command, mis. C:1:DATA UPDATE userinfo PIN=001...');
            $table->string('description', 100)->nullable();
            $table->enum('status', ['pending', 'sent', 'executed', 'failed'])->default('pending');
            $table->text('response')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['machine_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sdm_fingerprint_commands');
    }
};
