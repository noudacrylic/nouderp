<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda "pancingan sudah dikirim untuk jendela ini".
 *
 * Yang disimpan bukan waktu kirimnya, melainkan JENDELA MANA yang dipancing —
 * nilai `window_expires_at` saat pancingan berangkat. Bedanya menentukan:
 * dengan waktu kirim, penjadwal harus menebak-nebak apakah jendela sekarang
 * masih jendela yang sama; dengan nilai jendelanya, pertanyaan itu tak pernah
 * muncul. Pelanggan membalas → jendela bergeser → angkanya tak lagi cocok →
 * pancingan berikutnya otomatis boleh berangkat, tanpa satu pun aturan
 * kedaluwarsa yang perlu ditulis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_conversations', function (Blueprint $table) {
            $table->dateTime('pancingan_untuk_jendela_at')->nullable()->after('window_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('crm_conversations', function (Blueprint $table) {
            $table->dropColumn('pancingan_untuk_jendela_at');
        });
    }
};
