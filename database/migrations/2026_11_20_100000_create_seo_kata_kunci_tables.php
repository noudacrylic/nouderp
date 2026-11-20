<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bank kata kunci SEO (20 Nov 2026): istilah yang dipakai orang mencari produk akrilik, per jenis
 * produk. Sumbernya riset AI (Claude + pencarian web), Google Search Console (kata yang benar-benar
 * mendatangkan pengunjung), dan tambahan manual. Dipakai skill Galeri untuk caption/alt foto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_jenis', function (Blueprint $t) {
            $t->id();
            $t->string('nama', 100)->unique();
            $t->string('petunjuk', 500)->nullable();   // konteks untuk riset AI
            $t->boolean('aktif')->default(true);
            $t->unsignedInteger('urutan')->default(0);
            $t->timestamp('riset_diminta_at')->nullable();
            $t->timestamp('riset_at')->nullable();
            $t->string('riset_ringkasan', 500)->nullable();
            $t->unsignedInteger('riset_biaya_rp')->nullable();
            $t->timestamps();
        });

        Schema::create('seo_kata_kunci', function (Blueprint $t) {
            $t->id();
            $t->foreignId('seo_jenis_id')->nullable()->constrained('seo_jenis')->nullOnDelete();
            $t->string('kata', 150)->unique();
            $t->string('niat', 20)->nullable();        // beli | info | lokal | custom
            $t->string('perkiraan', 10)->nullable();   // tinggi | sedang | rendah (taksiran AI)
            $t->string('sumber', 10);                  // riset | gsc | manual
            $t->boolean('aktif')->default(true);
            $t->string('catatan', 255)->nullable();
            $t->unsignedInteger('gsc_tayang')->nullable();
            $t->unsignedInteger('gsc_klik')->nullable();
            $t->decimal('gsc_posisi', 6, 2)->nullable();
            $t->date('gsc_sampai')->nullable();
            $t->timestamps();
            $t->index(['seo_jenis_id', 'aktif']);
        });

        $kategori = ['Box Mahar', 'Kotak Saran', 'Tempat Brosur', 'Box Charger', 'Rak Pulpen', 'Tempat Kartu Nama',
            'Nama Meja', 'Kotak Tisu', 'Frame Poster', 'Frame Foto', 'Tent Card/Holder'];
        $custom = [
            'Plakat Akrilik'            => 'plakat/penghargaan akrilik custom untuk instansi, wisuda, purna tugas',
            'Akrilik Sign / Papan Nama' => 'papan nama, signage, petunjuk ruangan dari akrilik',
            'Display Akrilik'           => 'display/standee/stand produk dari akrilik untuk toko & pameran',
            'Box Akrilik Custom'        => 'kotak/box akrilik ukuran custom (souvenir, hampers, etalase)',
            'Trophy Akrilik'            => 'trofi/piala akrilik untuk lomba & event',
            'Gantungan Kunci Akrilik'   => 'gantungan kunci akrilik custom cetak (souvenir, merchandise)',
        ];
        $n = 0;
        $baris = [];
        foreach ($kategori as $k) $baris[] = ['nama' => $k, 'petunjuk' => null, 'urutan' => ++$n];
        foreach ($custom as $k => $p) $baris[] = ['nama' => $k, 'petunjuk' => $p, 'urutan' => ++$n];
        DB::table('seo_jenis')->insert(array_map(fn ($b) => $b + ['aktif' => true, 'created_at' => now(), 'updated_at' => now()], $baris));
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_kata_kunci');
        Schema::dropIfExists('seo_jenis');
    }
};
