<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KAPAN marketplace menyatakan sebuah pesanan SELESAI.
 *
 * Kolom `wms_completed_at` yang sudah ada TIDAK menjawab itu, dan bedanya bukan
 * soal ketelitian: ia diisi saat KITA selesai memproses pesanan di WMS Jubelio
 * (picking → faktur → resi terbit), alias kira-kira tanggal barang keluar
 * gudang. Pesanan yang baru diterima pembeli sepuluh hari kemudian — atau
 * dibatalkan di tengah jalan — punya `wms_completed_at` yang sudah lama
 * menyala.
 *
 * Kolom baru ini diisi dari `received_date` milik Jubelio (tanggal pesanan
 * diterima pembeli menurut channel); bila detailnya tidak menyebutkannya,
 * diisi saat kita PERTAMA KALI melihat statusnya menjadi 'completed'.
 *
 * Dipakai grafik Penjualan di dashboard: garis hijaunya berdiri di tanggal
 * pesanan selesai, bukan tanggal faktur (yang untuk marketplace terbit di
 * muka, sebelum sebutir barang pun dikirim).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jubelio_order_links', function (Blueprint $table) {
            $table->timestamp('mp_completed_at')->nullable()->after('wms_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('jubelio_order_links', function (Blueprint $table) {
            $table->dropColumn('mp_completed_at');
        });
    }
};
