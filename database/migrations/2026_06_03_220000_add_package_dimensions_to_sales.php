<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dimensi paket (cm) diisi manual di panel cek ongkir SO/Invoice. Disimpan supaya
 * BOOKING resi di Surat Jalan pakai dimensi yang SAMA dengan saat cek ongkir
 * (ongkir/kurir instant konsisten — tidak ambil dimensi per-produk yang bisa beda).
 */
return new class extends Migration {
    private array $tables = ['sales_orders', 'sales_invoices'];

    public function up(): void
    {
        foreach ($this->tables as $t) {
            Schema::table($t, function (Blueprint $table) use ($t) {
                if (!Schema::hasColumn($t, 'package_length')) $table->decimal('package_length', 8, 2)->nullable()->after('shipping_service_name');
                if (!Schema::hasColumn($t, 'package_width'))  $table->decimal('package_width', 8, 2)->nullable()->after('package_length');
                if (!Schema::hasColumn($t, 'package_height')) $table->decimal('package_height', 8, 2)->nullable()->after('package_width');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropColumn(['package_length', 'package_width', 'package_height']));
        }
    }
};
