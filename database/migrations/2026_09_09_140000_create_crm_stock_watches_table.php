<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Kabari saya kalau stoknya ada" — titipan pelanggan atas sebuah SKU.
 *
 * Ada karena barang habis adalah kehilangan penjualan yang PALING mudah
 * diselamatkan: pembelinya sudah mau, cuma datang di waktu yang salah. Selama
 * ini janji "nanti saya kabari" hidup di kepala admin dan di gulungan chat,
 * dan hampir tak pernah ditepati — barangnya datang berminggu kemudian, saat
 * percakapan itu sudah terkubur.
 *
 * Barisnya TIDAK dihapus setelah dikabari, cuma dilepas (aktif → NULL).
 * Riwayatnya yang menjawab "kenapa pelanggan ini dapat kabar?" dan sekaligus
 * memperlihatkan berapa sering sebuah barang ditunggu orang — angka yang
 * berguna saat memutuskan apa yang perlu distok lebih banyak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_stock_watches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('crm_conversations')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // Berapa yang ditunggu. Pembeli yang butuh 10 pcs tidak terbantu
            // oleh kabar "stok ada" saat yang masuk cuma 1.
            $table->decimal('qty', 12, 2)->default(1);

            // Nomor disalin saat ditandai. Percakapan bisa berpindah/berganti
            // nama, dan yang harus dihubungi adalah nomor yang menitipkan.
            $table->string('recipient', 32)->nullable();

            /*
             * Penanda aktif yang sengaja NULLABLE, bukan boolean.
             *
             * MySQL mengabaikan baris ber-NULL pada indeks unik, jadi
             * unique(conversation_id, product_id, aktif) berarti: satu titipan
             * AKTIF per (chat, produk), sementara titipan lama yang sudah
             * dikabari (aktif = NULL) boleh menumpuk berapa pun. Itu persis
             * aturannya, dan ia dijaga basis data — bukan cuma oleh kode yang
             * bisa kalah oleh dua permintaan bersamaan.
             */
            $table->boolean('aktif')->nullable()->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('notified_at')->nullable();
            $table->foreignId('outbox_id')->nullable()->constrained('crm_outbox')->nullOnDelete();
            $table->timestamps();

            $table->unique(['conversation_id', 'product_id', 'aktif'], 'crm_stock_watch_aktif_unik');
            $table->index(['product_id', 'aktif']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_stock_watches');
    }
};
