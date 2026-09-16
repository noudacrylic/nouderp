<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cabang pelanggan — satu tagihan, banyak alamat kirim.
 *
 * Pelanggan korporat memesan untuk beberapa cabang sekaligus, tapi tagihannya
 * tetap satu pintu di purchasing pusat. Sebelum tabel ini ada, satu-satunya
 * cara mengirim ke tiga cabang adalah membuat tiga pelanggan — dan begitu itu
 * dilakukan, piutang, termin tempo, saldo DP, dan riwayat pembeliannya ikut
 * pecah jadi tiga. Cabang di sini SENGAJA bukan pelanggan: `customer_id` tetap
 * menunjuk induk, jadi sisi uang tidak pernah terbelah.
 *
 * Nama kolomnya SENGAJA sama persis dengan milik `customers`. Dua hal jadi
 * gratis karenanya: editor alamat `_shipping_fields.blade.php` bisa dipakai
 * ulang apa adanya, dan induk bisa dituangkan jadi "cabang bayangan" tanpa
 * pemetaan nama — yang membuat pembaca alamat di seluruh ERP cukup menghadapi
 * SATU bentuk, bukan dua yang harus dibedakan dengan ternary di mana-mana.
 *
 * Termasuk `*_area_id` dan lat/long: kalau ketiganya tertinggal di induk,
 * ongkir semua cabang dihitung ke kota pusat — dan salahnya tidak bersuara,
 * ongkirnya tetap keluar, cuma angkanya keliru.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_branches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // Nama CETAK, ditulis utuh ("PT Sumber Jaya - Cabang Bandung").
            // Kartu piutangnya atas nama induk, jadi nama yang memuat induknya
            // membuat nota dan kartu piutang langsung nyambung saat dicocokkan.
            $t->string('name');
            $t->string('pic_name')->nullable();

            $t->string('recipient_phone', 30)->nullable();
            $t->text('shipping_address')->nullable();
            $t->string('district', 100)->nullable();
            $t->string('city', 100)->nullable();
            $t->string('province', 100)->nullable();
            $t->string('postal_code', 10)->nullable();

            $t->string('biteship_area_id', 100)->nullable();
            $t->string('kiriminaja_area_id', 100)->nullable();
            $t->string('jubelio_area_id', 100)->nullable();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();

            // Diarsipkan, bukan dihapus: cabang yang sudah dipakai dokumen
            // namanya harus tetap bisa dibaca saat nota lama dibuka kembali.
            $t->boolean('is_active')->default(true);

            $t->timestamps();

            $t->index(['customer_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_branches');
    }
};
