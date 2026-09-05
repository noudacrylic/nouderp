<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Potongan balasan siap pakai ("template teks") untuk rail kanan Inbox.
 *
 * ⚠️ JANGAN dikelirukan dengan template Meta (`crm_settings` → dasbor vendor).
 * Bedanya menentukan dan tidak boleh kabur di kepala siapa pun:
 *
 *   - Potongan teks (tabel ini): milik kita sendiri, gratis, bisa diubah kapan
 *     saja, TAPI hanya sah dipakai di DALAM jendela 24 jam.
 *   - Template Meta: wajib lolos persetujuan, berbayar, dan satu-satunya yang
 *     boleh keluar di LUAR jendela.
 *
 * Menyatukan keduanya di satu daftar tanpa penanda = admin memilih yang salah
 * setiap hari, lalu ditolak API tanpa tahu sebabnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_snippets', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            // Pengelompokan bebas ("Sapaan", "Harga", "Pengiriman"); kosong = tanpa grup.
            $table->string('category')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            // Berapa kali dipakai — dasar untuk mengurutkan yang paling sering
            // di atas, supaya daftar tetap berguna setelah isinya puluhan.
            $table->unsignedInteger('used_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_snippets');
    }
};
