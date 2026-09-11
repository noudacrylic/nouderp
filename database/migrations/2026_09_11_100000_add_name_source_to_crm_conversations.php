<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asal-usul `display_name` percakapan.
 *
 * `name_source`:
 *   'whatsapp' — nama profil WhatsApp, diambil dari vendor. Boleh diperbarui
 *                otomatis kapan saja.
 *   'manual'   — diketik CS lewat "Nama kontak…". TIDAK PERNAH ditimpa
 *                otomatis: nama yang sengaja ditulis orang lebih benar daripada
 *                nama profil yang bisa berupa apa saja.
 *
 * `name_checked_at` — kapan vendor terakhir DITANYA, berhasil membawa nama atau
 * tidak. Tanpanya, kontak yang memang tak punya nama profil akan ditanyakan
 * ulang tiap menit selamanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_conversations', function (Blueprint $table) {
            $table->string('name_source', 16)->nullable()->after('display_name');
            $table->dateTime('name_checked_at')->nullable()->after('name_source');
        });
    }

    public function down(): void
    {
        Schema::table('crm_conversations', function (Blueprint $table) {
            $table->dropColumn(['name_source', 'name_checked_at']);
        });
    }
};
