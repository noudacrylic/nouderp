<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Agen `cs-whatsapp` (keputusan owner 16 Nov 2026): Saran AI untuk nomor utama WhatsApp (WAHA),
 * persona sama dengan CS Shopee — admin chat yang meneruskan ke admin bagian. Mulai mode SARAN
 * saja (kirim otomatis mati); checkout lewat AI belum ada sampai alat cek_ongkir & buat_pesanan.
 *
 * Pengetahuan versi 1 = resources/agen/cs-whatsapp-pengetahuan.md (daftar disetujui owner).
 * Versi berikutnya: `php artisan crm:muat-pengetahuan cs-whatsapp`.
 */
return new class extends Migration
{
    private const PERSONA = <<<'TXT'
Kamu admin chat WhatsApp Noud Acrylic (akrilik, Semarang). Kamu membalas chat WHATSAPP nomor
utama toko. Kamu tidak memperkenalkan diri dan tidak pernah menyebut dirimu AI.

Tugasmu: baca pesan terakhir pengirim, cari butir yang cocok di daftar tanya-jawab, lalu
usulkan balasan lewat alat `usulkan_balasan`. Admin manusia yang memutuskan memakainya.

- Sampaikan isi butir yang cocok sesingkat mungkin, mengikuti bagian "Gaya balasan" di pengetahuan.
- Kalau pertanyaannya tidak ada di daftar, atau butirnya bilang lempar: isi `lempar_ke` menurut
  pengetahuan, dengan balasan sementara yang singkat.
- Bagian "Koreksi dari owner" menang bila bertabrakan dengan butir daftar.
- Pengirim bukan pelanggan (kurir, pemasok, notifikasi, OTP, promo): ikuti bagian "Bukan pelanggan".
- Alat `cek_ongkir` dan `buat_pesanan` BELUM ADA. Untuk checkout (bagian K): kumpulkan datanya
  satu-satu seperti biasa; begitu perlu ongkir atau pesanan dibuat, lempar ke `admin` dengan
  ringkasan pesanan di `alasan`. Jangan menyebut ongkir atau potongan ongkir sendiri.
TXT;

    public function up(): void
    {
        if (DB::table('crm_agents')->where('kode', 'cs-whatsapp')->exists()) {
            return;
        }

        $id = DB::table('crm_agents')->insertGetId([
            'kode'           => 'cs-whatsapp',
            'nama'           => 'CS WhatsApp',
            'deskripsi'      => 'Mengusulkan balasan chat WhatsApp nomor utama dari daftar tanya-jawab (mode saran).',
            'persona'        => self::PERSONA,
            'maks_giliran'   => 5,
            'is_active'      => false,
            'kirim_otomatis' => 'mati',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        DB::table('crm_agent_knowledge')->insert([
            'agent_id'      => $id,
            'versi'         => 1,
            'isi'           => trim((string) file_get_contents(resource_path('agen/cs-whatsapp-pengetahuan.md'))),
            'catatan'       => 'Daftar tanya-jawab WA disetujui owner 16 Nov 2026',
            'sumber_berkas' => 'resources/agen/cs-whatsapp-pengetahuan.md',
            'is_active'     => true,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    public function down(): void
    {
        $id = DB::table('crm_agents')->where('kode', 'cs-whatsapp')->value('id');
        if ($id) {
            DB::table('crm_agent_knowledge')->where('agent_id', $id)->delete();
            DB::table('crm_agents')->where('id', $id)->delete();
        }
    }
};
