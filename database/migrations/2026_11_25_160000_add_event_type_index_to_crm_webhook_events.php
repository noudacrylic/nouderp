<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Pita "webhook sepi" (WebhookHealthService::sepi) mencari kabar TERAKHIR
 * yang bukan dari WAHA — di server cuma ±33 dari 56 ribu baris. Tanpa indeks
 * ini MariaDB membaca seluruh tabel (382 MB, kolom payload) setiap Inbox atau
 * PWA /cs dibuka: ±1,3 detik per halaman. Dengan indeks, kuerinya cukup
 * memindai indeks kecil ini (indeks sekunder InnoDB ikut membawa id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_webhook_events', function (Blueprint $table) {
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::table('crm_webhook_events', function (Blueprint $table) {
            $table->dropIndex(['event_type']);
        });
    }
};
