<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('inventory_adjustments', function (Blueprint $table) {
            $table->string('purpose')->default('stock_opname')->after('type'); // stock_opname | perbaikan_rusak
            $table->string('direction')->default('set_stock')->after('purpose'); // penambahan | pengurangan | set_stock
            $table->unsignedBigInteger('income_account_id')->nullable()->after('direction');
            $table->unsignedBigInteger('expense_account_id')->nullable()->after('income_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_adjustments', function (Blueprint $table) {
            $table->dropColumn(['purpose', 'direction', 'income_account_id', 'expense_account_id']);
        });
    }
};
