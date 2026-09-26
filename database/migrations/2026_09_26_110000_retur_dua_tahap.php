<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tahap retur dipangkas dari tiga jadi dua: "Retur Baru" & "Banding".
 *
 * Tahap `diproses` tak pernah menjawab pertanyaan apa pun — "baru" dan "diproses"
 * sama-sama berarti retur yang belum selesai, dan memisahkannya cuma memaksa CS
 * menebak sedang di kotak mana sebuah kasus duduk. Yang benar-benar beda nasibnya
 * adalah retur yang sedang DISENGKETAKAN ke marketplace; itu yang kini punya
 * tempat sendiri (`banding`), dan hanya dimasuki lewat tombol.
 *
 * Seluruh 33 draft yang ada berada di `diproses` dan turun ke `baru`. Tahap
 * `baru` sendiri sudah kosong, jadi tak ada yang tertimpa.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sales_returns')->where('stage', 'diproses')->update(['stage' => 'baru']);
    }

    public function down(): void
    {
        // Retur yang jenisnya sudah terisi dulu memang duduk di `diproses`; itu yang
        // dikembalikan. Yang jenisnya masih kosong memang lahir di `baru`.
        DB::table('sales_returns')
            ->where('stage', 'baru')
            ->whereNotNull('return_type')
            ->update(['stage' => 'diproses']);
    }
};
