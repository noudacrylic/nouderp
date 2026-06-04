<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inventory_account_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('repair_account_id')->nullable()->after('inventory_loss_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_account_settings', function (Blueprint $table) {
            $table->dropColumn('repair_account_id');
        });
    }
};
