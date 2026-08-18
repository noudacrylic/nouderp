<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Isi beranda etalase (noudakrilik.com) — singleton, dikelola di Store → Beranda.
     *
     * Alasan tabel ini ada: seluruh teks beranda dulu ditulis di dalam komponen React.
     * Setiap koreksi kalimat berarti deploy ulang etalase. Yang benar-benar sering
     * berubah (judul, tombol, kalimat penawaran, FAQ) karena itu dipindah ke sini.
     *
     * Yang SENGAJA tidak ada di sini:
     *  - Alamat, jam buka, telepon → sudah di lib/site.ts etalase & harus sama persis
     *    dengan Profil Bisnis Google. Dua sumber = cepat atau lambat berbeda.
     *  - Tangga potongan ongkir → dibaca live dari promo aktif (/api/storefront/promotions),
     *    supaya halaman ketentuan mustahil berbeda dengan hitungan checkout.
     *  - Daftar produk unggulan → sudah ada flag is_featured di Produk Store.
     */
    public function up(): void
    {
        Schema::create('store_homepage_settings', function (Blueprint $table) {
            $table->id();

            // ── Meta & berbagi tautan ──────────────────────────────────────
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 300)->nullable();
            $table->string('og_image_url')->nullable();
            $table->string('og_image_key')->nullable();

            // ── Hero ───────────────────────────────────────────────────────
            $table->string('hero_eyebrow')->nullable();
            $table->string('hero_heading')->nullable();          // H1 halaman
            $table->text('hero_subheading')->nullable();
            $table->string('hero_primary_label')->nullable();
            $table->string('hero_primary_url')->nullable();
            $table->string('hero_secondary_label')->nullable();
            $table->text('hero_secondary_wa')->nullable();       // teks awal WhatsApp
            $table->string('hero_image_url')->nullable();
            $table->string('hero_image_key')->nullable();
            $table->string('hero_image_alt')->nullable();

            // ── Strip keunggulan: [{icon,title,text}] ──────────────────────
            $table->json('advantages')->nullable();

            // ── Tiga jalur pembeli: [{icon,title,text,url}] ────────────────
            $table->boolean('show_segments')->default(true);
            $table->string('segments_heading')->nullable();
            $table->json('segments')->nullable();

            // ── Grid kategori ──────────────────────────────────────────────
            $table->boolean('show_categories')->default(true);
            $table->string('categories_heading')->nullable();

            // ── Produk unggulan ────────────────────────────────────────────
            $table->string('featured_heading')->nullable();
            $table->unsignedTinyInteger('featured_limit')->default(8);

            // ── "Beli di sini lebih hemat" ─────────────────────────────────
            $table->boolean('show_savings')->default(true);
            $table->string('savings_heading')->nullable();
            $table->string('savings_price_title')->nullable();
            $table->text('savings_price_text')->nullable();
            $table->string('savings_ship_title')->nullable();
            $table->text('savings_ship_text')->nullable();
            $table->string('savings_link_label')->nullable();

            // ── Pengadaan instansi ─────────────────────────────────────────
            $table->boolean('show_institution')->default(true);
            $table->string('institution_heading')->nullable();
            $table->text('institution_body')->nullable();
            $table->json('institution_bullets')->nullable();     // ["...", "..."]
            $table->string('institution_cta_label')->nullable();
            $table->text('institution_cta_wa')->nullable();

            // ── Custom & logo instansi ─────────────────────────────────────
            $table->boolean('show_custom')->default(true);
            $table->string('custom_heading')->nullable();
            $table->text('custom_body')->nullable();
            $table->string('custom_cta_label')->nullable();
            $table->text('custom_cta_wa')->nullable();

            // ── Workshop Semarang (alamat & jam tetap dari lib/site) ───────
            $table->boolean('show_workshop')->default(true);
            $table->boolean('show_map')->default(true);
            $table->string('workshop_heading')->nullable();
            $table->text('workshop_body')->nullable();

            // ── FAQ: [{q,a}] — juga jadi data terstruktur FAQPage ──────────
            $table->boolean('show_faq')->default(true);
            $table->string('faq_heading')->nullable();
            $table->json('faqs')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_homepage_settings');
    }
};
