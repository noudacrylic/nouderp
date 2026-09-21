<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dokumen "Kredit Pelanggan" — pemberian / pengurangan saldo kredit pelanggan secara MANUAL.
 *
 * Sebelum ini saldo kredit hanya bisa lahir dari dua kejadian: pelanggan kelebihan bayar, dan
 * refund lewat Kas Bank. Tidak ada cara memberi kredit untuk BARANG yang masuk tanpa uang —
 * mis. tukar ukuran atas penjualan lama yang fakturnya tidak ada di ERP. Dokumen ini pintunya.
 *
 * Saldonya sendiri tetap tinggal di kolam yang sudah ada (`customer_overpayments`), yang sudah
 * dipakai pembayaran faktur untuk memotong saldo. Tabel ini adalah DOKUMENNYA: nomor, alasan,
 * akun lawan, jurnal, dan jejak void.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_credits', function (Blueprint $table) {
            $table->id();
            $table->string('credit_number')->unique();
            $table->foreignId('customer_id')->constrained('customers');
            $table->date('credit_date');
            // tambah = saldo pelanggan bertambah; kurang = saldo dipotong (mis. hangus/koreksi)
            $table->string('direction', 10);
            $table->decimal('amount', 15, 2);
            // Akun lawan jurnalnya. Untuk kasus retur/tukar biasanya 6105 Beban Kerugian Retur.
            $table->foreignId('counter_account_id')->constrained('accounts');
            $table->text('reason')->nullable();
            $table->string('status', 10)->default('posted');
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credits');
    }
};
