<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skema modul CRM (Tahap 2).
 *
 * Lima tabel, dan yang TIDAK ada sama pentingnya dengan yang ada:
 *  - tak ada tabel "lead"  → lead = percakapan yang belum punya dokumen ERP
 *  - tak ada entitas "deal" → wadah negosiasi adalah percakapan itu sendiri;
 *    Penawaran (sales_quotations) lahir belakangan lewat tombol, supaya nomor
 *    penawaran tidak penuh oleh "halo bisa custom?"
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_conversations', function (Blueprint $table) {
            $table->id();

            $table->string('channel', 20)->default('whatsapp');   // whatsapp|instagram|messenger

            /*
             * Identitas kontak yang sudah dinormalkan (nomor '628…' atau username IG).
             * Inilah kunci pencocokan, bukan id milik vendor — supaya percakapan
             * tetap utuh kalau kelak pindah penyedia.
             */
            $table->string('contact_key', 64);
            $table->string('display_name')->nullable();

            /* id pelanggan di sisi vendor — hanya untuk memanggil API mereka. */
            $table->string('provider_customer_id')->nullable();

            /* Nomor bisnis KITA yang melayani percakapan ini (multi-nomor/multi-WABA). */
            $table->string('business_number_id')->nullable();

            /* Pencocokan ke master ERP. Null = belum dikenali (kemungkinan lead baru). */
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            /* Kepemilikan & antrean — inti triase, tidak ada di sisi vendor. */
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('queue_state', 30)->default('menunggu_kita');
            $table->string('status', 20)->default('aktif');       // aktif|arsip

            /*
             * Jendela 24 jam. Disimpan (bukan selalu ditanya ke API) supaya daftar
             * percakapan bisa menandai thread tanpa satu panggilan API per baris.
             */
            $table->timestamp('window_expires_at')->nullable();

            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_outbound_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);

            $table->text('notes')->nullable();
            $table->timestamps();

            /* Satu kontak bisa punya percakapan terpisah per kanal & per nomor bisnis. */
            $table->unique(['channel', 'contact_key', 'business_number_id'], 'crm_conv_identity_unique');
            $table->index(['status', 'queue_state', 'last_message_at'], 'crm_conv_antrean_index');
            $table->index('owner_user_id');
        });

        Schema::create('crm_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('crm_conversations')->cascadeOnDelete();

            $table->string('direction', 10);                       // masuk|keluar
            $table->string('message_type', 20)->default('text');   // text|image|document|audio|video|template|interactive|…
            $table->text('content')->nullable();

            /*
             * Dari mana pesan keluar ini berasal:
             *   erp           = dikirim dari layar ERP kita
             *   whatsapp_app  = admin mengetik langsung di HP (coexistence)
             *   api           = jalur API lain di luar ERP
             * Kolom inilah yang membuat "kebocoran balas dari HP" bisa ditegakkan
             * kode, bukan sekadar soal disiplin.
             */
            $table->string('source', 20)->nullable();

            /* Identitas pesan di sisi vendor & Meta. Unik = penangkal kiriman ganda. */
            $table->string('provider_message_id')->nullable()->unique();
            $table->string('wam_id')->nullable()->index();
            $table->string('reply_to_wam_id')->nullable();

            $table->string('status', 20)->nullable();              // terkirim|delivered|read|failed
            $table->text('error')->nullable();

            /* Diisi hanya bila pesan keluar dikirim seorang pengguna ERP. */
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            /* Waktu menurut vendor/Meta — bukan waktu baris ini dibuat. */
            $table->timestamp('sent_at')->nullable();

            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'sent_at']);
        });

        Schema::create('crm_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('crm_messages')->cascadeOnDelete();

            /*
             * Media WAJIB diunduh ke penyimpanan sendiri saat masuk: Meta hanya
             * menyimpan ~30 hari, sedangkan diskusi custom bisa menggantung
             * berbulan-bulan lalu kehilangan logonya.
             */
            $table->string('disk', 30)->nullable();
            $table->string('path')->nullable();
            $table->string('provider_media_id')->nullable();
            $table->string('source_url', 1024)->nullable();

            $table->string('original_name')->nullable();
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->timestamp('downloaded_at')->nullable();
            $table->text('download_error')->nullable();

            $table->timestamps();
        });

        Schema::create('crm_webhook_events', function (Blueprint $table) {
            $table->id();

            /*
             * Penangkal kiriman ganda. Vendor mengirim ulang bila balasan 200
             * terlambat, dan urutannya bisa kacau — pola idempoten yang sama
             * dengan jubelio_order_links.
             */
            $table->string('idempotency_key')->unique();
            $table->uuid('event_id')->nullable()->index();
            $table->string('event_type', 40)->nullable();

            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('crm_outbox', function (Blueprint $table) {
            $table->id();

            /*
             * Kunci idempoten dibentuk pemanggil, mis.
             *   'so:142:siap_diambil'          (sekali seumur pesanan)
             *   'so:142:pembayaran:88'         (sekali per pembayaran)
             * Unik → status yang di-set ulang tidak mengirim pesan kedua.
             */
            $table->string('dedupe_key')->unique();

            $table->string('event', 40);                      // pembayaran_diterima|siap_diambil|dikirim
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('crm_conversations')->nullOnDelete();

            $table->string('recipient', 32)->nullable();      // nomor '628…' saat dikirim
            $table->string('template_name')->nullable();
            $table->json('template_body')->nullable();        // nilai {{1}}, {{2}}, …

            /* menunggu|terkirim|gagal|dilewati */
            $table->string('status', 20)->default('menunggu');
            $table->text('reason')->nullable();               // alasan dilewati / pesan gagal

            /*
             * Jam sopan: notifikasi yang jatuh di luar jam kerja ditunda ke jam
             * kerja berikutnya. Pesan pukul 23.00 mengundang blokir, dan blokir
             * menurunkan quality rating.
             */
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->string('provider_message_id')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index(['sales_order_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_outbox');
        Schema::dropIfExists('crm_webhook_events');
        Schema::dropIfExists('crm_attachments');
        Schema::dropIfExists('crm_messages');
        Schema::dropIfExists('crm_conversations');
    }
};
