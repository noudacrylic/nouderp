<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Sorotan produk: dari satu blok tetap menjadi beberapa slide yang bisa digeser.
     *
     * Perubahan pentingnya bukan jumlahnya, melainkan sumber gambarnya. Versi lama
     * meminta admin mengunggah foto produk lagi ke halaman Beranda — foto yang sudah
     * ada di Produk Store. Sekarang tiap slide cukup menyebut SLUG produknya, lalu
     * etalase mengambil sendiri foto, nama, dan harga dari katalog. Satu foto tetap
     * satu tempat, dan slide tidak pernah basi saat foto produknya diperbarui.
     *
     * Karena itu semua kolom unggahan sorotan dibuang; tak ada data yang hilang,
     * fitur ini belum pernah dipakai.
     */
    public function up(): void
    {
        Schema::table('store_homepage_settings', function (Blueprint $table) {
            // [{slug, eyebrow, heading, body, bullets, cta_label}] — `bullets` satu
            // baris per manfaat; `heading` kosong = pakai nama produk apa adanya.
            $table->json('spotlights')->nullable()->after('show_spotlight');

            $table->dropColumn([
                'spotlight_eyebrow', 'spotlight_heading', 'spotlight_body',
                'spotlight_bullets', 'spotlight_cta_label', 'spotlight_url',
                'spotlight_image_url', 'spotlight_image_key', 'spotlight_image_alt',
                'spotlight_photo1_url', 'spotlight_photo1_key',
                'spotlight_photo2_url', 'spotlight_photo2_key',
                'spotlight_photos_caption',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('store_homepage_settings', function (Blueprint $table) {
            $table->dropColumn('spotlights');

            $table->string('spotlight_eyebrow')->nullable();
            $table->string('spotlight_heading')->nullable();
            $table->text('spotlight_body')->nullable();
            $table->json('spotlight_bullets')->nullable();
            $table->string('spotlight_cta_label')->nullable();
            $table->string('spotlight_url')->nullable();
            $table->string('spotlight_image_url')->nullable();
            $table->string('spotlight_image_key')->nullable();
            $table->string('spotlight_image_alt')->nullable();
            $table->string('spotlight_photo1_url')->nullable();
            $table->string('spotlight_photo1_key')->nullable();
            $table->string('spotlight_photo2_url')->nullable();
            $table->string('spotlight_photo2_key')->nullable();
            $table->string('spotlight_photos_caption')->nullable();
        });
    }
};
