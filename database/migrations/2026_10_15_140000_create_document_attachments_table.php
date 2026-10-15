<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Foto nota / bukti yang menempel di dokumen (faktur pembelian,
        // pembayaran supplier, pengeluaran). Dipakai sebagai PENGGANTI nota
        // kertas — karena itu dihapus pun hanya diarsipkan (soft delete).
        Schema::create('document_attachments', function (Blueprint $table) {
            $table->id();
            // Kunci jenis milik FotoNotaService::JENIS (mis. 'purchase_invoice'),
            // bukan nama kelas — tidak ikut berubah bila model dipindah.
            $table->string('doc_type', 40);
            $table->unsignedBigInteger('doc_id');
            $table->string('disk', 20)->default('local');
            $table->string('path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('size_bytes')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['doc_type', 'doc_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_attachments');
    }
};
