<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hasil pengukuran paket setelah dipacking (sub-tab "Perlu Ukur").
 *
 * Dimensi memakai kolom `package_length/width/height` yang sudah ada — yang belum ada cuma
 * BERATNYA (selama ini selalu dijumlah dari `products.weight_gram`, jadi tak pernah mencerminkan
 * kardus sungguhan) dan penanda "sudah diukur".
 *
 * Penandanya kolom sendiri, BUKAN "package_length terisi": kolom dimensi itu juga terisi dari
 * Cek Ongkir saat SO dibuat, jadi tidak bisa membedakan taksiran dari hasil timbang.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->unsignedInteger('package_weight_gram')->nullable()->after('package_height');
            $table->timestamp('measured_at')->nullable()->after('package_weight_gram');
            $table->foreignId('measured_by')->nullable()->after('measured_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('measured_by');
            $table->dropColumn(['package_weight_gram', 'measured_at']);
        });
    }
};
