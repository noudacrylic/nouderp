<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Kerangka beranda versi 2 (dokumen "Beranda-Noud-Akrilik-v2", 18 Agu 2026).
     *
     * Tiga bagian benar-benar baru — sorotan produk, galeri instansi, dan strip
     * kepercayaan — plus lencana kecil di hero. Semuanya ikut pola yang sudah ada:
     * teks di tabel ini, foto produk tetap di modulnya sendiri.
     *
     * Yang SENGAJA tidak ada di sini:
     *  - Foto galeri instansi → diambil dari media grup `showcase` milik tiap Produk
     *    Store. Foto yang sama dipakai di halaman produk; mengunggahnya dua kali
     *    berarti suatu hari yang satu diperbarui dan yang lain tertinggal.
     *  - Angka terjual di kartu produk → dihitung dari faktur, bukan diketik.
     */
    public function up(): void
    {
        Schema::table('store_homepage_settings', function (Blueprint $table) {
            // ── Lencana kecil di bawah tombol hero: [{icon,label}] ─────────
            $table->json('hero_badges')->nullable()->after('hero_image_alt');

            // ── Kalimat penjelas di bawah judul kartu jalur pembeli ────────
            $table->string('segments_subheading')->nullable()->after('segments_heading');

            // ── "Kenapa pilih Noud" (memakai kembali kolom `advantages`) ────
            // Dulu strip tipis di bawah hero; kini bagian penuh berjudul, letaknya
            // sesudah sorotan produk. Isinya tetap: HANYA klaim yang berlaku untuk
            // seluruh katalog.
            $table->boolean('show_advantages')->default(true)->after('advantages');
            $table->string('advantages_heading')->nullable()->after('show_advantages');

            // ── Sorotan produk ─────────────────────────────────────────────
            // Satu produk bernilai tinggi diberi ruang penuh: foto besar, daftar
            // manfaat, dua foto pemakaian nyata, satu tombol.
            $table->boolean('show_spotlight')->default(true);
            $table->string('spotlight_eyebrow')->nullable();
            $table->string('spotlight_heading')->nullable();
            $table->text('spotlight_body')->nullable();
            $table->json('spotlight_bullets')->nullable();          // ["...", "..."]
            $table->string('spotlight_cta_label')->nullable();
            $table->string('spotlight_url')->nullable();
            $table->string('spotlight_image_url')->nullable();
            $table->string('spotlight_image_key')->nullable();
            $table->string('spotlight_image_alt')->nullable();
            // Dua foto pemakaian — slot tetap, bukan repeater: berkas unggahan di
            // dalam repeater Alpine kehilangan pasangan nama/indeksnya begitu satu
            // baris dihapus, dan yang terunggah jadi menimpa slot yang salah.
            $table->string('spotlight_photo1_url')->nullable();
            $table->string('spotlight_photo1_key')->nullable();
            $table->string('spotlight_photo2_url')->nullable();
            $table->string('spotlight_photo2_key')->nullable();
            $table->string('spotlight_photos_caption')->nullable();

            // ── Galeri "dipercaya instansi" ────────────────────────────────
            $table->boolean('show_gallery')->default(true);
            $table->string('gallery_heading')->nullable();
            $table->string('gallery_note')->nullable();
            $table->string('gallery_link_label')->nullable();
            $table->string('gallery_url')->nullable();              // kosong = tautan disembunyikan
            $table->unsignedTinyInteger('gallery_limit')->default(8);

            // ── Strip kepercayaan sebelum footer: [{icon,title,text}] ──────
            $table->boolean('show_trust')->default(true);
            $table->json('trust_items')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('store_homepage_settings', function (Blueprint $table) {
            $table->dropColumn([
                'hero_badges', 'segments_subheading',
                'show_advantages', 'advantages_heading',
                'show_spotlight', 'spotlight_eyebrow', 'spotlight_heading', 'spotlight_body',
                'spotlight_bullets', 'spotlight_cta_label', 'spotlight_url',
                'spotlight_image_url', 'spotlight_image_key', 'spotlight_image_alt',
                'spotlight_photo1_url', 'spotlight_photo1_key',
                'spotlight_photo2_url', 'spotlight_photo2_key', 'spotlight_photos_caption',
                'show_gallery', 'gallery_heading', 'gallery_note', 'gallery_link_label',
                'gallery_url', 'gallery_limit',
                'show_trust', 'trust_items',
            ]);
        });
    }
};
