<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inventory_account_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inventory_asset_account_id');
            $table->unsignedBigInteger('inventory_gain_account_id');
            $table->unsignedBigInteger('inventory_loss_account_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_account_settings');
    }
};
