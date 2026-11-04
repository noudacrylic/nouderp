<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alur beli baru: pembayaran tertaut ke PO, pengajuan bayar ke owner, dan skill "barang belum
 * datang" diganti "Selesaikan pesanan" (mode & pengaturannya diwarisi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payments', function (Blueprint $t) {
            $t->foreignId('purchase_order_id')->nullable()->after('supplier_id')->constrained('purchase_orders')->nullOnDelete();
        });

        Schema::create('pengajuan_bayar_owner', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->cascadeOnDelete();
            $t->foreignId('purchase_invoice_id')->nullable()->constrained('purchase_invoices')->cascadeOnDelete();
            $t->foreignId('diajukan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $t->boolean('tempo')->default(false);
            $t->timestamps();
        });

        DB::table('karyawan_virtual_skills')->where('skill', 'admin.kedatangan')->update(['skill' => 'admin.selesaikan-pesanan']);
        DB::table('meja_kerja_skill')->where('skill', 'kedatangan')->update(['skill' => 'selesaikan-pesanan']);
        DB::table('tasks')->where('skill', 'kedatangan')->whereIn('status', ['open', 'in_progress', 'pending'])
            ->update(['status' => 'cancelled', 'done_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('meja_kerja_skill')->where('skill', 'selesaikan-pesanan')->update(['skill' => 'kedatangan']);
        DB::table('karyawan_virtual_skills')->where('skill', 'admin.selesaikan-pesanan')->update(['skill' => 'admin.kedatangan']);

        Schema::dropIfExists('pengajuan_bayar_owner');
        Schema::table('supplier_payments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('purchase_order_id');
        });
    }
};
