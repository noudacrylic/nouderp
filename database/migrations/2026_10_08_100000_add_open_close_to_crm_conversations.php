<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Tahap 1 — chat Open / Close.
 *
 * `status` TIDAK diganti nilainya: 'aktif' = Open, 'arsip' = Close. Nilainya
 * dipakai belasan tempat (lencana, pancingan, notifikasi), dan mengganti kata
 * di basis data cuma memindahkan risiko tanpa menambah apa pun — yang berubah
 * adalah ARTINYA dan kata di layar.
 *
 * Yang baru:
 *  - closed_at     : kapan ditutup. Penentu "kembali ke pemilik terakhir"
 *                    (< 30 hari) atau "jadi pesanan baru di Belum dioper".
 *  - is_distributor: sifat KONTAK, bukan label yang berganti. Distributor
 *                    selalu kembali ke pemilik terakhirnya dan tutup sendiri
 *                    setelah 3 hari sepi.
 *  - selalu_tutup  : nomor promo/iklan. Pesan berikutnya tetap tercatat tapi
 *                    chatnya tidak pernah dibuka lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_conversations', function (Blueprint $table) {
            $table->dateTime('closed_at')->nullable()->after('status');
            $table->boolean('is_distributor')->default(false)->after('closed_at');
            $table->boolean('selalu_tutup')->default(false)->after('is_distributor');
        });

        /*
         * Dua label yang digerakkan sistem. Kodenya dipatok (kode memang beku
         * sejak lahir), namanya tetap bisa diganti di layar Label.
         */
        foreach ([
            ['kode' => 'selesai',     'nama' => 'Selesai',     'warna' => 'green',  'urutan' => 90],
            ['kode' => 'distributor', 'nama' => 'Distributor', 'warna' => 'purple', 'urutan' => 91],
        ] as $label) {
            if (! DB::table('crm_labels')->where('kode', $label['kode'])->exists()) {
                DB::table('crm_labels')->insert($label + ['aktif' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        // Chat yang sudah diarsip dianggap ditutup saat terakhir disentuh.
        DB::table('crm_conversations')
            ->where('status', 'arsip')
            ->update(['closed_at' => DB::raw('updated_at')]);

        /*
         * Bersih-bersih saat rilis (disepakati): chat tanpa pemilik yang sepi
         * lebih dari 7 hari ditutup. Tanpa ini tab "Belum dioper" lahir berisi
         * ratusan chat lama dan berhenti dipercaya di hari pertama.
         */
        DB::table('crm_conversations')
            ->where('status', 'aktif')
            ->whereNull('owner_user_id')
            ->whereRaw('COALESCE(last_message_at, created_at) < ?', [now()->subDays(7)])
            ->update(['status' => 'arsip', 'closed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('crm_conversations', function (Blueprint $table) {
            $table->dropColumn(['closed_at', 'is_distributor', 'selalu_tutup']);
        });
    }
};
