<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * order_id tertimpa tiap pembeli memilih metode bayar lagi. Daftar yang lama disimpan supaya
 * pembayaran yang tetap masuk ke order lama bisa dikenali pesanannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('midtrans_transactions', function (Blueprint $t) {
            $t->json('order_id_lama')->nullable()->after('order_id');
        });
    }

    public function down(): void
    {
        Schema::table('midtrans_transactions', function (Blueprint $t) {
            $t->dropColumn('order_id_lama');
        });
    }
};
