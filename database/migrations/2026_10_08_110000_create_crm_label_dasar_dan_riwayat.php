<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Tahap 2 — label ikut berpindah saat chat dioper.
 *
 *  - crm_label_dasar   : label bawaan tiap agen (Burhan → Tanya Harga,
 *                        Saiful Munif → Print). Diatur di layar CRM > Label.
 *  - crm_label_riwayat : label TERAKHIR sebuah chat selama dipegang seorang
 *                        agen. Chat yang dioper balik ke agen yang pernah
 *                        memegangnya kembali ke label miliknya, bukan ke label
 *                        dasar — pekerjaan yang sudah sampai "Desain" tidak
 *                        boleh mundur ke "Tanya Harga" cuma karena sempat
 *                        mampir ke meja orang lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_label_dasar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('kode', 40);
            $table->timestamps();
        });

        Schema::create('crm_label_riwayat', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('crm_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kode', 40);
            $table->timestamps();
            $table->unique(['conversation_id', 'user_id'], 'crm_label_riwayat_unik');
        });

        // Label tahapan pesanan (dipakai otomatis mulai Tahap 3).
        $urutan = (int) DB::table('crm_labels')->max('urutan');
        foreach ([
            ['kode' => 'menunggu_pembayaran', 'nama' => 'Menunggu Pembayaran', 'warna' => 'amber'],
            ['kode' => 'menunggu_dikirim',    'nama' => 'Menunggu Dikirim',    'warna' => 'blue'],
            ['kode' => 'menunggu_pelunasan',  'nama' => 'Menunggu Pelunasan',  'warna' => 'red'],
        ] as $label) {
            if (! DB::table('crm_labels')->where('kode', $label['kode'])->exists()) {
                DB::table('crm_labels')->insert($label + [
                    'urutan' => ++$urutan, 'aktif' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        // Disepakati: "Cetak" disebut "Print". Hanya kalau namanya belum diganti orang.
        DB::table('crm_labels')->where('kode', 'cetak')->where('nama', 'Cetak')
            ->update(['nama' => 'Print', 'updated_at' => now()]);

        // Label dasar yang disebut user saat diskusi — dipasang bila namanya cocok persis.
        foreach (['Burhan' => 'tanya_harga', 'Saiful Munif' => 'cetak'] as $nama => $kode) {
            $id = DB::table('users')->where('name', $nama)->value('id');

            if ($id && DB::table('crm_labels')->where('kode', $kode)->exists()) {
                DB::table('crm_label_dasar')->updateOrInsert(['user_id' => $id], [
                    'kode' => $kode, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        // Riwayat dimulai dari keadaan hari ini: label chat di tangan pemiliknya sekarang.
        DB::statement('
            INSERT INTO crm_label_riwayat (conversation_id, user_id, kode, created_at, updated_at)
            SELECT id, owner_user_id, queue_state, NOW(), NOW()
            FROM crm_conversations
            WHERE owner_user_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_label_riwayat');
        Schema::dropIfExists('crm_label_dasar');
    }
};
