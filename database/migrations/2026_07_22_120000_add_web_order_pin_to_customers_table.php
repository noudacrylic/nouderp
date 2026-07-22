<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PIN akun toko online (hash) — konsumen set saat checkout, lalu bisa melihat
 * daftar pesanannya dari device mana pun via Nomor HP + PIN. Nullable (customer
 * ERP lama / marketplace tak punya PIN).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('web_order_pin')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('web_order_pin');
        });
    }
};
