<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enam jenis retur diringkas jadi tiga. Empat jenis yang dibuang semuanya berarti hal
 * yang sama — pembeli mengajukan retur — dan alasan rincinya baru ketahuan setelah
 * paketnya dibuka, jadi tempatnya di Catatan Penanganan, bukan jadi cabang alur.
 *
 * Kolomnya varchar, jadi ini murni pemetaan data. Yang dipetakan cuma label; tidak ada
 * jurnal yang bergerak, karena nasib uang & barang ditentukan kondisi per baris dan
 * tujuan dana, bukan oleh jenis kasusnya.
 */
return new class extends Migration
{
    private const PETA = [
        'kembali_semula' => 'diajukan_konsumen',
        'tidak_sesuai'   => 'diajukan_konsumen',
        'rusak'          => 'diajukan_konsumen',
        'lainnya'        => 'diajukan_konsumen',
    ];

    public function up(): void
    {
        foreach (self::PETA as $lama => $baru) {
            DB::table('sales_returns')->where('return_type', $lama)->update(['return_type' => $baru]);
        }
    }

    /**
     * Tak bisa dikembalikan dengan setia — empat jenis lama sudah menyatu jadi satu, dan
     * menebak yang mana asalnya cuma mengarang data. Dibiarkan sebagaimana adanya.
     */
    public function down(): void
    {
    }
};
