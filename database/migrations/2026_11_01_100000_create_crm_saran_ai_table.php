<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Karyawan virtual CS Shopee, Tahap 1 — mode SARAN (disepakati owner 31 Okt 2026).
 *
 * AI tidak mengirim apa pun. Tiap pesan pembeli Shopee yang belum dibalas manusia diberi
 * satu saran balasan; admin memilih Pakai atau Abaikan. Hasilnya (dipakai utuh, dipakai
 * diubah, diabaikan + apa yang sebenarnya dikirim admin) adalah bahan evaluasi per butir
 * daftar tanya-jawab — butir yang konsisten dipakai utuh nanti dilepas jadi otomatis.
 *
 * Agen `cs-shopee` dibuat sekaligus dengan pengetahuan versi 1 dari
 * resources/agen/cs-shopee-pengetahuan.md (daftar yang di-review owner).
 */
return new class extends Migration
{
    private const PERSONA = <<<'TXT'
Kamu admin CS toko Shopee Noud Acrylic (akrilik, Semarang). Kamu membalas CHAT SHOPEE,
bukan WhatsApp. Kamu tidak memperkenalkan diri dan tidak pernah menyebut dirimu AI.

Tugasmu: baca pesan terakhir pembeli, cari butir yang cocok di daftar tanya-jawab, lalu
usulkan balasan lewat alat `usulkan_balasan`. Admin manusia yang memutuskan memakainya.

- Pakai kalimat contoh dari butir yang cocok. Boleh disesuaikan sedikit dengan
  pertanyaannya, tapi isinya jangan diubah. Kalimat dari daftar boleh lebih dari 3 baris.
- Kalau pertanyaannya tidak ada di daftar, atau butirnya bilang lempar: usulkan
  lempar ke admin/owner sesuai butirnya (tidak ada → admin), dengan balasan sementara
  bila butirnya menyediakannya.
- Larangan khusus Shopee: JANGAN PERNAH memberi nomor WhatsApp/telepon, tautan di luar
  Shopee selain yang ada di daftar, atau menawarkan pembelian di luar Shopee. Shopee
  bisa menindak toko.
- Jangan menyebut harga.
TXT;

    public function up(): void
    {
        Schema::create('crm_saran_ai', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('crm_conversations')->cascadeOnDelete();
            // Pesan masuk terakhir saat saran dibuat — satu saran per pesan.
            $table->foreignId('untuk_message_id')->nullable()->constrained('crm_messages')->nullOnDelete();
            $table->foreignId('agent_run_id')->nullable()->constrained('crm_agent_runs')->nullOnDelete();
            $table->string('butir', 10)->nullable();          // kode daftar tanya-jawab, mis. A1
            $table->text('teks')->nullable();                 // saran balasan (kosong = tak perlu dibalas)
            $table->string('lempar_ke', 10)->nullable();      // null | admin | owner
            $table->string('alasan', 255)->nullable();
            // baru | dipakai | diabaikan | kedaluwarsa | tak_perlu | gagal
            $table->string('status', 12)->default('baru');
            $table->text('teks_terkirim')->nullable();        // yang benar-benar dikirim admin
            $table->boolean('diubah')->nullable();            // dipakai tapi disunting dulu
            $table->string('catatan', 255)->nullable();       // alasan diabaikan / galat
            $table->foreignId('diputus_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('diputus_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'status']);
            $table->unique('untuk_message_id');
            $table->index(['butir', 'status']);
        });

        if (!DB::table('crm_agents')->where('kode', 'cs-shopee')->exists()) {
            $id = DB::table('crm_agents')->insertGetId([
                'kode'         => 'cs-shopee',
                'nama'         => 'CS Shopee',
                'deskripsi'    => 'Mengusulkan balasan chat Shopee dari daftar tanya-jawab (mode saran).',
                'persona'      => self::PERSONA,
                'maks_giliran' => 5,
                'is_active'    => false,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            DB::table('crm_agent_knowledge')->insert([
                'agent_id'      => $id,
                'versi'         => 1,
                'isi'           => file_get_contents(resource_path('agen/cs-shopee-pengetahuan.md')),
                'catatan'       => 'Daftar tanya-jawab hasil review owner 31 Okt 2026',
                'sumber_berkas' => 'resources/agen/cs-shopee-pengetahuan.md',
                'is_active'     => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_saran_ai');
        DB::table('crm_agents')->where('kode', 'cs-shopee')->delete();
    }
};
