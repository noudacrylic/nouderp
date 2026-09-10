<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Potongan balasan" jadi "Template Pesan", dan sekarang bisa didaftarkan ke Meta.
 *
 * Namanya diganti karena pemisahan lamanya salah tempat. Dulu dibedakan
 * berdasarkan HARGA (gratis vs berbayar), padahal yang benar-benar berbeda bagi
 * orang yang bekerja adalah SIAPA YANG MENGIRIM:
 *
 *  - Notifikasi: dikirim sistem sendiri. Bunyinya milik kode, tidak diedit dari
 *    layar — justru itu yang menjamin jalur WAHA & jalur resmi mengucapkan
 *    kalimat yang sama persis saat antrean yang tertahan dieskalasi.
 *  - Template (tabel ini): DIPILIH MANUSIA saat chat. Nomor rekening, alamat,
 *    jam buka — dan juga sapaan pembuka ke nomor yang belum pernah menghubungi
 *    kita, yang mau tak mau harus lewat Meta.
 *
 * Keduanya duduk di satu daftar dengan penanda, bukan di dua tabel: yang
 * membedakannya cuma "didaftarkan ke Meta atau tidak", dan admin memilih dari
 * satu tempat yang sama. Penjaganya bukan tabel terpisah melainkan jalur
 * kirimnya — teks bebas tetap ditolak di luar jendela 24 jam, apa pun asalnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('crm_snippets', 'crm_templates');

        Schema::table('crm_templates', function (Blueprint $table) {
            /*
             * Nama template di Meta. NULL = template milik kita sendiri, gratis,
             * hanya sah di dalam jendela 24 jam. Terisi = sudah/sedang diajukan,
             * dan boleh dipakai membuka chat ke nomor dingin.
             */
            $table->string('meta_name', 120)->nullable()->unique()->after('body');
            // PENDING | APPROVED | REJECTED — apa adanya dari vendor.
            $table->string('meta_status', 20)->nullable()->after('meta_name');
            $table->string('meta_id', 64)->nullable()->after('meta_status');
            $table->text('meta_error')->nullable()->after('meta_id');
            $table->dateTime('meta_submitted_at')->nullable()->after('meta_error');
            /*
             * Contoh nilai tiap {{n}}, berurutan. Vendor MENOLAK pengajuan bila
             * jumlahnya tidak sama dengan jumlah placeholder di body — jadi ia
             * disimpan bersebelahan dengan bodynya, bukan dihitung ulang di
             * layar tiap kali.
             */
            $table->json('meta_variables')->nullable()->after('meta_submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('crm_templates', function (Blueprint $table) {
            $table->dropUnique(['meta_name']);
            $table->dropColumn([
                'meta_name', 'meta_status', 'meta_id',
                'meta_error', 'meta_submitted_at', 'meta_variables',
            ]);
        });

        Schema::rename('crm_templates', 'crm_snippets');
    }
};
