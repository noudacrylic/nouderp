<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Label percakapan jadi DATA, bukan tetapan di kode.
 *
 * Sebelumnya bunyi antrean dipatok di CrmConversation::QUEUE_LABELS, jadi
 * mengganti kata "Menunggu Desain" saja butuh deploy. Kodenya (queue_state di
 * percakapan) sengaja TIDAK ikut berubah saat namanya diganti — itu yang
 * membuat penggantian nama aman: yang berpindah cuma tulisan di layar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_labels', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 40)->unique();
            $table->string('nama', 60);
            $table->string('warna', 20)->default('gray');
            $table->unsignedInteger('urutan')->default(0);
            $table->boolean('aktif')->default(true);
            $table->timestamps();
        });

        // Empat label bawaan = persis QUEUE_LABELS lama, supaya percakapan yang
        // sudah ada tetap punya nama dan tidak ada layar yang mendadak kosong.
        $now = now();
        DB::table('crm_labels')->insert([
            ['kode' => 'menunggu_kita',      'nama' => 'Menunggu Kita',      'warna' => 'orange', 'urutan' => 1, 'aktif' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['kode' => 'menunggu_pelanggan', 'nama' => 'Menunggu Pelanggan', 'warna' => 'blue',   'urutan' => 2, 'aktif' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['kode' => 'menunggu_desain',    'nama' => 'Menunggu Desain',    'warna' => 'purple', 'urutan' => 3, 'aktif' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['kode' => 'dingin',             'nama' => 'Dingin',             'warna' => 'gray',   'urutan' => 4, 'aktif' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_labels');
    }
};
