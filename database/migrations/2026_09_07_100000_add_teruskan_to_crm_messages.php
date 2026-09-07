<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak "diteruskan": dari pesan mana isi ini disalin.
 *
 * WhatsApp menandai pesan teruskan dengan label "Diteruskan", tapi penandanya
 * TIDAK bisa disetel lewat Cloud API — di HP pelanggan yang sampai hanyalah
 * pesan biasa. Kolom ini murni untuk layar ERP: tanpa jejaknya, gambar yang
 * tiba-tiba muncul di chat pelanggan B tampak seperti dikirim entah dari mana,
 * dan tidak ada yang bisa menelusuri lagi itu berasal dari chat siapa.
 *
 * Kutipan (balas) TIDAK butuh kolom baru — `reply_to_wam_id` sudah ada sejak
 * migrasi awal dan diisi jalur webhook maupun jalur kirim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_messages', function (Blueprint $table) {
            $table->foreignId('forwarded_from_message_id')
                ->nullable()
                ->after('reply_to_wam_id')
                // Pesan sumber boleh hilang (percakapannya dihapus) tanpa ikut
                // menyeret pesan yang sudah telanjur sampai ke pelanggan lain.
                ->constrained('crm_messages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crm_messages', function (Blueprint $table) {
            $table->dropForeign(['forwarded_from_message_id']);
            $table->dropColumn('forwarded_from_message_id');
        });
    }
};
