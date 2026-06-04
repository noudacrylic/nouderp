<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('account_category')->nullable()->after('type');
        });

        // 🔥 BACKFILL DATA: Isi data lama berdasarkan code
        DB::table('accounts')->where('code', '1101')->update(['account_category' => 'cash']);

        DB::table('accounts')->whereIn('code', ['1102', '1103', '1104', '1202'])
            ->update(['account_category' => 'cash_equivalent']);

        DB::table('accounts')->where('code', '1120')
            ->update(['account_category' => 'receivable']);

        DB::table('accounts')->where('code', '1130')
            ->update(['account_category' => 'inventory']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('account_category');
        });
    }
};
