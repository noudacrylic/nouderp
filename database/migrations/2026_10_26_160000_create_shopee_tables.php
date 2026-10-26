<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shopee Open Platform — tahap 0 (koneksi) & tahap 1a (mutasi Saldo Penjual).
 *  - shopee_shops: toko yang diotorisasi; token disimpan terenkripsi (cast encrypted).
 *  - shopee_api_logs: jejak tiap panggilan API (tanpa token/kunci) untuk menelusuri galat.
 *  - shopee_wallet_transactions: baris mutasi Saldo Penjual (dari file Seller Centre, nanti API)
 *    beserta hasil pencocokannya dengan jurnal 1104.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_shops', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('env', 10);
            $table->string('nama')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->dateTime('access_expires_at')->nullable();
            $table->dateTime('refresh_expires_at')->nullable();
            $table->foreignId('terhubung_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('terhubung_at')->nullable();
            $table->text('galat_terakhir')->nullable();
            $table->timestamps();
            $table->unique(['shop_id', 'env']);
        });

        Schema::create('shopee_api_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id')->nullable()->index();
            $table->string('method', 6);
            $table->string('path', 120);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error', 120)->nullable();
            $table->text('message')->nullable();
            $table->string('request_id', 80)->nullable();
            $table->unsignedInteger('durasi_ms')->default(0);
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::create('shopee_wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->string('sumber', 10);              // excel | api
            $table->string('kunci', 64)->unique();     // pencegah baris kembar antar unggahan
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->dateTime('waktu')->index();
            $table->unsignedInteger('urutan')->default(0); // urutan dalam detik yang sama (saldo akhir)
            $table->string('tipe', 80);
            $table->text('deskripsi')->nullable();
            $table->string('order_sn', 40)->nullable()->index();
            $table->decimal('jumlah', 15, 2);
            $table->decimal('saldo_akhir', 15, 2)->nullable();
            $table->string('status', 60)->nullable();
            $table->string('kategori', 30)->index();
            $table->string('cocok', 20)->nullable()->index();   // cocok | selisih | belum
            $table->decimal('erp_jumlah', 15, 2)->nullable();
            $table->json('journal_ids')->nullable();
            $table->string('cocok_catatan')->nullable();
            $table->foreignId('diimpor_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_wallet_transactions');
        Schema::dropIfExists('shopee_api_logs');
        Schema::dropIfExists('shopee_shops');
    }
};
