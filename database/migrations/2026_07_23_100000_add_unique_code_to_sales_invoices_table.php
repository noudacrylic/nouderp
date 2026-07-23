<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kode unik pembayaran transfer toko online ikut mengalir dari SO ke Invoice.
 * Tanpa ini, faktur menagih penuh sementara pembeli mentransfer nominal yang
 * sudah dikurangi kode unik → sisa piutang Rp1–999 menggantung di tiap pesanan web.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->unsignedInteger('unique_code')->default(0)->after('grand_total');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', fn (Blueprint $t) => $t->dropColumn('unique_code'));
    }
};
