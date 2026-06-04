<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fixed_asset_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('opening_offset_account_id')->nullable();
            $table->foreignId('default_disposal_proceeds_account_id')->nullable();
            $table->foreign('opening_offset_account_id', 'fas_opening_offset_acc_fk')
                ->references('id')->on('accounts')->nullOnDelete();
            $table->foreign('default_disposal_proceeds_account_id', 'fas_disposal_proceeds_acc_fk')
                ->references('id')->on('accounts')->nullOnDelete();
            $table->boolean('auto_run_depreciation_on_close')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_settings');
    }
};
