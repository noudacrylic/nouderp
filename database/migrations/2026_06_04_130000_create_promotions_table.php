<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master promosi. 3 jenis:
 *  - item       : diskon per produk -> mengisi diskon item (per baris). Tampil harga coret di Kasir.
 *  - shipping   : diskon ongkir bertingkat (tier by total belanja) -> mengisi diskon ongkir.
 *  - cart_total : diskon berdasarkan total belanja (tier) -> mengisi diskon global.
 *
 * Promo TIDAK menambah logika finansial baru — hanya mengisi field diskon existing.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['item', 'shipping', 'cart_total']);

            // Dipakai oleh type 'item' (diskon langsung). Untuk shipping/cart_total nilainya ada di tier.
            $table->enum('discount_type', ['nominal', 'percent'])->default('nominal');
            $table->decimal('discount_value', 18, 2)->default(0);

            // type 'item': berlaku ke semua produk (true) atau produk terpilih (false, lihat promotion_products).
            $table->boolean('applies_to_all')->default(false);

            $table->boolean('is_voucher')->default(false);
            $table->string('voucher_code')->nullable()->unique();

            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0); // tie-break saat tumpang tindih (tinggi menang)

            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
