<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Akun kas/bank yang tampil di kartu "Saldo Kas & Bank" dashboard, PER PENGGUNA.
 * Null = belum pernah diatur → bawaan (akun is_cash_account) dipakai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('dashboard_akun_saldo')->nullable()->after('crm_label_tampil');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('dashboard_akun_saldo');
        });
    }
};
