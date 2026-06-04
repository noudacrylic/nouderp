<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('freight_settings', function (Blueprint $table) {
            $table->id();
            // Selisih ongkir: titipan > aktual dibayar → Cr pendapatan (gain)
            $table->foreignId('gain_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            // Selisih ongkir: titipan < aktual dibayar → Dr beban (loss)
            $table->foreignId('loss_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freight_settings');
    }
};
