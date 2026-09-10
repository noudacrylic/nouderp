<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flag "Akses PWA CRM": izin masuk aplikasi chat HP (`/cs`).
 *
 * Berdiri sendiri, TIDAK menumpang izin menu `crm.inbox`, karena tujuannya
 * justru akun CS yang cuma boleh membalas chat dan tidak punya ERP sama sekali.
 * Konsekuensinya flag ini juga membuka endpoint `erp/crm/*` — lihat
 * EnsureMenuAccess: tanpa itu layar chat tampil tapi seluruh isinya 403.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('pwa_crm')->default(false)->after('karyawan_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pwa_crm');
        });
    }
};
