<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    /**
     * Alamat publik untuk halaman lacak pesanan.
     *
     * Kuncinya sengaja menempel di PESANAN, bukan di pembayaran. Dua token yang sudah
     * ada — `web_payments.public_token` dan `midtrans_transactions.link_token` —
     * keduanya milik tagihan: yang satu hanya ada untuk pesanan web, yang lain mati
     * begitu tautannya kedaluwarsa. Padahal pelacakan justru paling dibutuhkan
     * SESUDAH dibayar, dan pesanan tempo bahkan belum punya pembayaran sama sekali.
     *
     * Token TIDAK diterbitkan untuk semua pesanan. Hanya pesanan yang memang punya
     * wajah publik yang mendapatkannya (checkout web & pembuatan link bayar), jadi
     * pesanan marketplace tak pernah punya halaman ini — pelacakannya sudah ada di
     * aplikasi marketplace masing-masing, dan keputusan itu dijaga oleh struktur,
     * bukan oleh syarat yang bisa lupa dipasang.
     */
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->char('public_token', 36)->nullable()->unique()->after('order_number');
        });

        // Susulkan untuk pesanan yang SUDAH punya wajah publik hari ini: pesanan web
        // dan pesanan berlink-bayar. Tanpa ini, tautan yang sudah tersebar ke pembeli
        // akan menemui halaman kosong sampai pesanan itu disentuh lagi.
        $ids = DB::table('sales_orders')->whereNull('public_token')
            ->where(function ($q) {
                $q->whereIn('id', fn ($s) => $s->select('sales_order_id')->from('web_payments')->whereNotNull('sales_order_id'))
                  ->orWhereIn('id', fn ($s) => $s->select('sales_order_id')->from('midtrans_transactions')->whereNotNull('sales_order_id'));
            })
            ->pluck('id');

        foreach ($ids as $id) {
            DB::table('sales_orders')->where('id', $id)->update(['public_token' => (string) Str::uuid()]);
        }
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn('public_token');
        });
    }
};
