<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Pecah tipe 'repair' menjadi 'perbaikan' (berbasis SKU Gudang Perbaikan) dan
        // 'garansi' (berbasis dokumen warranty). 'repair' dipertahankan untuk data lama.
        DB::statement("ALTER TABLE production_orders MODIFY type ENUM('ready_stock','custom','perbaikan','garansi','repair') NOT NULL");
    }

    public function down(): void
    {
        // Kembalikan tipe baru ke 'repair' agar tidak melanggar enum lama.
        DB::table('production_orders')->whereIn('type', ['perbaikan', 'garansi'])->update(['type' => 'repair']);
        DB::statement("ALTER TABLE production_orders MODIFY type ENUM('ready_stock','custom','repair') NOT NULL");
    }
};
