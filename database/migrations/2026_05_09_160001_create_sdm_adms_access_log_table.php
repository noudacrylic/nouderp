<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sdm_adms_access_log', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at')->useCurrent();
            $table->string('method', 10);
            $table->string('path', 255);
            $table->text('query_string')->nullable();
            $table->string('serial_number', 60)->nullable();
            $table->string('client_ip', 45)->nullable();
            $table->text('body_sample')->nullable()->comment('first 1KB of request body');
            $table->smallInteger('response_code')->nullable();
            $table->text('response_sample')->nullable()->comment('first 500 chars of response');
            $table->foreignId('machine_id')->nullable()->constrained('sdm_fingerprint_machines')->nullOnDelete();
            $table->timestamps();

            $table->index('occurred_at');
            $table->index('serial_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sdm_adms_access_log');
    }
};
