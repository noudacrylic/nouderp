<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sederhanakan struktur biaya jadi satu pohon tetap:
 *
 *   Non Produksi
 *   Produksi
 *     ├─ Overhead Produksi
 *     └─ Divisi Produksi → tiap divisi bertipe 'produksi'
 *
 * Dua hal ikut hilang:
 *
 * 1. `production_cost_department_settings` (basis + charge_labor). Basis tidak lagi
 *    dipilih manual — tempat sebuah biaya sudah ditentukan tipe divisinya: divisi
 *    'produksi' punya node sendiri dan dibebankan per waktu, divisi non-produksi
 *    melebur ke grup Non Produksi. Basis 'per_unit' dihapus sepenuhnya.
 *    `charge_labor` juga tidak dipakai lagi: apa pun yang tercantum di halaman itu
 *    dibebankan; tidak ingin dibebankan berarti barisnya dihapus.
 *
 * 2. Komponen milik divisi NON-produksi. Divisi seperti Packing tidak lagi berdiri
 *    sendiri, jadi komponennya dipindah ke grup Non Produksi supaya nilainya tidak
 *    hilang dari perhitungan (namanya diberi awalan divisi asal agar tetap terlacak).
 */
return new class extends Migration
{
    public function up(): void
    {
        $nonProduksiDeptIds = DB::table('production_departments')
            ->where('type', '!=', 'produksi')->pluck('name', 'id');

        foreach ($nonProduksiDeptIds as $id => $name) {
            DB::table('production_cost_components')
                ->where('group_key', 'divisi')
                ->where('department_id', $id)
                ->update([
                    'group_key'     => 'non_produksi',
                    'department_id' => null,
                    // Nama divisi asal disematkan supaya baris "Overhead Packing" dkk
                    // masih bisa dikenali asalnya setelah melebur.
                    'notes'         => DB::raw("CONCAT('eks divisi " . addslashes($name) . "', COALESCE(CONCAT(' — ', notes), ''))"),
                    'updated_at'    => now(),
                ]);
        }

        // Komponen 'divisi' yang divisinya sudah terhapus juga dilebur, supaya tidak
        // menggantung tanpa induk di pohon baru.
        DB::table('production_cost_components')
            ->where('group_key', 'divisi')
            ->whereNull('department_id')
            ->update(['group_key' => 'non_produksi', 'updated_at' => now()]);

        Schema::dropIfExists('production_cost_department_settings');
    }

    public function down(): void
    {
        Schema::create('production_cost_department_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->unique()
                  ->constrained('production_departments')->cascadeOnDelete();
            $table->string('basis', 20)->default('abaikan');
            $table->boolean('charge_labor')->default(true);
            $table->timestamps();
        });
    }
};
