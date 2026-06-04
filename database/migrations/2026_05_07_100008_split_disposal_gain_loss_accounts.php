<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Drop dari asset_categories — gain/loss tidak per kategori, tapi global di settings.
        Schema::table('asset_categories', function (Blueprint $table) {
            $table->dropForeign(['disposal_gain_loss_account_id']);
            $table->dropColumn('disposal_gain_loss_account_id');
        });

        // Tambah 2 akun terpisah ke fixed_asset_settings: gain (7100) dan loss (7200).
        Schema::table('fixed_asset_settings', function (Blueprint $table) {
            $table->foreignId('disposal_gain_account_id')->nullable()->after('default_disposal_proceeds_account_id');
            $table->foreignId('disposal_loss_account_id')->nullable()->after('disposal_gain_account_id');

            $table->foreign('disposal_gain_account_id', 'fas_gain_acc_fk')
                ->references('id')->on('accounts')->nullOnDelete();
            $table->foreign('disposal_loss_account_id', 'fas_loss_acc_fk')
                ->references('id')->on('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fixed_asset_settings', function (Blueprint $table) {
            $table->dropForeign('fas_gain_acc_fk');
            $table->dropForeign('fas_loss_acc_fk');
            $table->dropColumn(['disposal_gain_account_id', 'disposal_loss_account_id']);
        });

        Schema::table('asset_categories', function (Blueprint $table) {
            $table->foreignId('disposal_gain_loss_account_id')->nullable()->after('depreciation_expense_account_id')
                ->constrained('accounts')->nullOnDelete();
        });
    }
};
