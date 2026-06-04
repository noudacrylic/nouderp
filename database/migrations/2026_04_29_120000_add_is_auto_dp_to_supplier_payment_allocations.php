<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('supplier_payment_allocations', function (Blueprint $table) {
            // Tandai allocation yang dibuat otomatis saat invoice posting (auto-apply DP).
            // Digunakan saat void invoice agar hanya reverse alokasi auto, bukan pelunasan manual user.
            $table->boolean('is_auto_dp')->default(false)->after('amount_applied');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payment_allocations', function (Blueprint $table) {
            $table->dropColumn('is_auto_dp');
        });
    }
};
