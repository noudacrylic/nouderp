<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kotak Nota: foto dari PWA HP yang belum ditempel ke dokumen —
        // doc_type/doc_id kosong sampai ditempel dari halaman dokumen di ERP.
        Schema::table('document_attachments', function (Blueprint $table) {
            $table->string('doc_type', 40)->nullable()->change();
            $table->unsignedBigInteger('doc_id')->nullable()->change();
            $table->string('catatan', 190)->nullable()->after('size_bytes');
            $table->timestamp('ditempel_at')->nullable()->after('uploaded_by');
            $table->foreignId('ditempel_by')->nullable()->after('ditempel_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ditempel_by');
            $table->dropColumn(['catatan', 'ditempel_at']);
        });
    }
};
