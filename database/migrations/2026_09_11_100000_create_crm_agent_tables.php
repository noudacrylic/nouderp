<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 8 — rangka agen AI untuk CRM.
 *
 * Tiga tabel, dan pembagiannya mengikuti tiga hal yang benar-benar berbeda umur:
 *
 *  - crm_agents          : jarang berubah (siapa agennya, model apa, hidup/mati)
 *  - crm_agent_knowledge : berubah tiap kali pengetahuannya ditajamkan, DAN
 *                          versinya tidak boleh hilang
 *  - crm_agent_runs      : tumbuh tiap kali agen dijalankan
 *
 * Menyatukannya berarti riwayat penajaman ikut tertimpa tiap kali model diganti,
 * dan itu menghapus satu-satunya cara menjawab "aturan mana yang membuat agen
 * menjawab begitu semalam".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_agents', function (Blueprint $table) {
            $table->id();

            /*
             * Kode dipakai KODE untuk menemukan agennya (bukan id), supaya
             * pemanggilan di dalam kode tidak bergantung pada urutan seed.
             */
            $table->string('kode', 32)->unique();
            $table->string('nama', 80);
            $table->string('deskripsi', 255)->nullable();

            /*
             * Persona: blok nada yang boleh disunting dari layar — nama agen,
             * cara menyapa, seberapa santai. SENGAJA dipisah dari pengetahuan:
             * yang satu jarang disentuh, yang satu ditajamkan terus-menerus,
             * dan menaruhnya di satu kotak membuat gaya bicara ikut tertimpa
             * tiap kali kebijakan baru diunggah.
             */
            $table->text('persona')->nullable();

            // Kosong = ikut bawaan di config('crm.agen.model_bawaan').
            $table->string('model', 60)->nullable();

            /*
             * Batas putaran tool per satu pesan pelanggan. Penjaga terhadap agen
             * yang berputar memanggil tool tanpa pernah menjawab — tanpa batas
             * ini satu pesan bisa menghabiskan biaya tanpa ujung.
             */
            $table->unsignedTinyInteger('maks_giliran')->default(6);

            /*
             * SAKLAR PELUNCURAN, mati sejak lahir. Agen yang menyala sendiri
             * setelah migrasi adalah cara tercepat mengirim kalimat setengah
             * jadi ke pelanggan sungguhan.
             */
            $table->boolean('is_active')->default(false);

            $table->timestamps();
        });

        Schema::create('crm_agent_knowledge', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('crm_agents')->cascadeOnDelete();

            // Nomor urut naik per agen. Ditampilkan di layar & dicatat tiap run.
            $table->unsignedInteger('versi');

            $table->longText('isi');
            $table->string('catatan', 255)->nullable();
            $table->string('sumber_berkas', 255)->nullable();

            /*
             * Satu versi aktif per agen. Versi lama TIDAK dihapus: ia yang
             * dipakai membaca ulang balasan lama, dan yang membuat "kembalikan
             * ke versi kemarin" jadi satu klik, bukan menulis ulang.
             */
            $table->boolean('is_active')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['agent_id', 'versi']);
            $table->index(['agent_id', 'is_active']);
        });

        Schema::create('crm_agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('crm_agents')->cascadeOnDelete();

            // Kosong pada mode uji tanpa percakapan nyata.
            $table->foreignId('conversation_id')->nullable()
                ->constrained('crm_conversations')->nullOnDelete();

            // Versi pengetahuan yang berlaku SAAT ITU — inti dari seluruh jejak ini.
            $table->foreignId('knowledge_id')->nullable()
                ->constrained('crm_agent_knowledge')->nullOnDelete();

            $table->string('mode', 12);      // uji | langsung
            $table->string('status', 12);    // sukses | gagal | dilempar

            $table->text('masukan')->nullable();
            $table->longText('keluaran')->nullable();

            /*
             * Jejak tool: nama, argumen, hasil, urut. Inilah yang membedakan
             * "agen menjawab salah" dari "agen dapat data salah" — dua penyakit
             * dengan obat yang sama sekali berbeda.
             */
            $table->json('jejak')->nullable();

            $table->unsignedInteger('token_masuk')->default(0);
            $table->unsignedInteger('token_cache')->default(0);
            $table->unsignedInteger('token_keluar')->default(0);

            /*
             * Biaya dihitung saat run, bukan saat dibaca: tarif model berubah,
             * dan menghitung ulang belakangan akan mengarang angka untuk masa
             * lalu dengan tarif hari ini.
             */
            $table->decimal('biaya_rp', 12, 2)->default(0);

            $table->unsignedInteger('durasi_ms')->default(0);
            $table->text('galat')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['agent_id', 'created_at']);
            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_agent_runs');
        Schema::dropIfExists('crm_agent_knowledge');
        Schema::dropIfExists('crm_agents');
    }
};
