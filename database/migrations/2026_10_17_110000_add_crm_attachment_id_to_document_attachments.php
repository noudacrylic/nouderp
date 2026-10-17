<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nota yang dimasukkan ke Kotak Nota langsung dari lampiran chat CRM
 * (nota supplier yang dikirim lewat WA). Penanda asal ini mencegah berkas
 * yang sama masuk dua kali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_attachments', function (Blueprint $table) {
            $table->foreignId('crm_attachment_id')->nullable()->after('catatan')
                ->constrained('crm_attachments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('crm_attachment_id');
        });
    }
};
