<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sejak KAPAN sebuah notifikasi tertahan.
 *
 * Tidak bisa diturunkan dari kolom yang sudah ada: created_at menghitung sejak
 * diantrekan (bisa berjam-jam sebelumnya karena jam sopan), sedangkan
 * scheduled_at justru terus digeser ke depan setiap kali percobaan gagal —
 * memakai keduanya membuat batas "3 jam" mengukur hal yang salah, dan
 * akibatnya persis yang paling mahal: pesan berbayar dikirim terlalu cepat,
 * atau kabar penting tak pernah dieskalasi sama sekali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_outbox', function (Blueprint $table) {
            $table->timestamp('held_since')->nullable()->after('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('crm_outbox', function (Blueprint $table) {
            $table->dropColumn('held_since');
        });
    }
};
