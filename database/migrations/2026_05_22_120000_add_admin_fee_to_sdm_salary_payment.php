<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sdm_salary_payment', function (Blueprint $table) {
            $table->decimal('admin_fee', 14, 2)->default(0)->after('nett_dibayar');
            $table->unsignedBigInteger('admin_fee_account_id')->nullable()->after('admin_fee');

            $table->foreign('admin_fee_account_id')->references('id')->on('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sdm_salary_payment', function (Blueprint $table) {
            $table->dropForeign(['admin_fee_account_id']);
            $table->dropColumn(['admin_fee', 'admin_fee_account_id']);
        });
    }
};
