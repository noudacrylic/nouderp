<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dua kelompok media per produk:
 *  - gallery  : galeri produk (dipakai carousel di halaman produk)  ← perilaku lama
 *  - showcase : foto instansi/klien yang pernah memesan produk ini,
 *               tampil sebagai bukti sosial di bawah deskripsi.
 *
 * caption = nama instansi / keterangan foto (khusus showcase, boleh kosong).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('store_product_media', function (Blueprint $table) {
            $table->enum('group', ['gallery', 'showcase'])->default('gallery')->after('store_product_id');
            $table->string('caption')->nullable()->after('alt_text');
            $table->index(['store_product_id', 'group'], 'spm_product_group_idx');
        });
    }

    public function down(): void
    {
        Schema::table('store_product_media', function (Blueprint $table) {
            $table->dropIndex('spm_product_group_idx');
            $table->dropColumn(['group', 'caption']);
        });
    }
};
