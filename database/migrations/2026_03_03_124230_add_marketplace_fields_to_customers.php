<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {

            $table->decimal('admin_percent', 8, 2)
                ->default(0)
                ->after('is_marketplace');

            $table->decimal('admin_nominal', 18, 2)
                ->default(0)
                ->after('admin_percent');

            $table->unsignedBigInteger('account_bank_id')
                ->nullable()
                ->after('admin_nominal');

            $table->unsignedBigInteger('account_revenue_id')
                ->nullable()
                ->after('account_bank_id');

            $table->unsignedBigInteger('account_admin_expense_id')
                ->nullable()
                ->after('account_revenue_id');

            $table->unsignedBigInteger('account_recon_plus_id')
                ->nullable()
                ->after('account_admin_expense_id');

            $table->unsignedBigInteger('account_recon_minus_id')
                ->nullable()
                ->after('account_recon_plus_id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'admin_percent',
                'admin_nominal',
                'account_bank_id',
                'account_revenue_id',
                'account_admin_expense_id',
                'account_recon_plus_id',
                'account_recon_minus_id',
            ]);
        });
    }
};
