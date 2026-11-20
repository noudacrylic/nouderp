<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Iklan Shopee tahap 2 — kelompok iklan dengan jadwal jam ramai + rotasi jam sepi.
 *  - shopee_iklan_kelompok : pengaturan kelompok (mode mati/bayangan/otomatis).
 *  - shopee_iklan.kelompok_id : satu iklan paling banyak di satu kelompok.
 *  - shopee_iklan_aksi     : catatan tiap rencana/aksi pause & lanjut (bayangan maupun nyata).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_iklan_kelompok', function (Blueprint $t) {
            $t->id();
            $t->string('env', 10);
            $t->string('nama', 100);
            $t->string('mode', 12)->default('mati');            // mati | bayangan | otomatis
            $t->json('jam_ramai')->nullable();                  // daftar jam 0–23: semua anggota aktif
            $t->unsignedTinyInteger('aktif_sepi')->default(2);  // jumlah iklan aktif di jam sepi
            $t->unsignedTinyInteger('rotasi_jam')->default(3);  // ganti giliran tiap N jam
            $t->decimal('batas_belanja', 14, 2)->nullable();    // belanja kelompok/hari; tercapai → mode sepi
            $t->json('status_simulasi')->nullable();            // mode bayangan: {campaign_id: aktif?}
            $t->timestamp('dijalankan_at')->nullable();
            $t->timestamps();
        });

        Schema::table('shopee_iklan', function (Blueprint $t) {
            $t->foreignId('kelompok_id')->nullable()->after('shop_id')
                ->constrained('shopee_iklan_kelompok')->nullOnDelete();
        });

        Schema::create('shopee_iklan_aksi', function (Blueprint $t) {
            $t->id();
            $t->string('env', 10);
            $t->foreignId('kelompok_id')->nullable()->constrained('shopee_iklan_kelompok')->nullOnDelete();
            $t->unsignedBigInteger('campaign_id');
            $t->string('aksi', 10);        // jeda | lanjut
            $t->string('mode', 12);        // bayangan | otomatis
            $t->string('hasil', 12);       // bayangan | berhasil | gagal | dilewati
            $t->string('alasan', 100)->nullable();
            $t->text('pesan')->nullable();
            $t->timestamps();

            $t->index(['env', 'created_at']);
            $t->index('campaign_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_iklan_aksi');
        Schema::table('shopee_iklan', function (Blueprint $t) {
            $t->dropConstrainedForeignId('kelompok_id');
        });
        Schema::dropIfExists('shopee_iklan_kelompok');
    }
};
