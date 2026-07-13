<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('warehouses', 'is_repair')) {
            Schema::table('warehouses', function (Blueprint $table) {
                // Penanda "Gudang Perbaikan" = wujud fisik akun 1131 Persediaan Perbaikan.
                $table->boolean('is_repair')->default(false)->after('is_sellable');
            });
        }

        // Seed satu Gudang Perbaikan (non-jual). Idempoten: hanya buat jika belum ada.
        $exists = DB::table('warehouses')->where('is_repair', true)->exists();
        if (!$exists) {
            DB::table('warehouses')->insert([
                'name'        => 'Perbaikan',
                'location'    => 'Area Perbaikan',
                'is_sellable' => false,
                'is_active'   => true,
                'is_repair'   => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Jangan hapus gudang jika sudah punya stok (hindari kehilangan data).
        $repair = DB::table('warehouses')->where('is_repair', true)->first();
        if ($repair) {
            $hasStock = DB::table('product_stocks')
                ->where('warehouse_id', $repair->id)
                ->where('qty_on_hand', '>', 0)
                ->exists();
            if (!$hasStock) {
                DB::table('warehouses')->where('id', $repair->id)->delete();
            }
        }

        if (Schema::hasColumn('warehouses', 'is_repair')) {
            Schema::table('warehouses', function (Blueprint $table) {
                $table->dropColumn('is_repair');
            });
        }
    }
};
