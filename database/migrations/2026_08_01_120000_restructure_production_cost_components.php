<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Satukan "pool manual" + "pemetaan akun" jadi SATU daftar komponen biaya, supaya
 * halaman Biaya Divisi bisa menampilkan seluruh penyusun biaya berdampingan
 * (akun GL maupun input manual) dan menambah komponen langsung di grupnya.
 *
 * Grup:
 *   non_produksi      → biaya operasional & sewa gedung; dibagi ke divisi per jam
 *   overhead_produksi → overhead pabrik; dibagi ke divisi per jam
 *   divisi            → milik satu divisi (CNC, Assembling, Packing)
 *
 * Gaji TIDAK disimpan di sini — selalu dihitung dari slip gaji per karyawan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_cost_components', function (Blueprint $table) {
            $table->id();
            $table->string('group_key', 20)->default('divisi'); // non_produksi | overhead_produksi | divisi
            $table->foreignId('department_id')->nullable()
                  ->constrained('production_departments')->nullOnDelete();
            $table->string('name');
            $table->string('source', 10)->default('manual');    // akun | manual
            $table->foreignId('account_id')->nullable()
                  ->constrained('accounts')->nullOnDelete();
            $table->decimal('percentage', 5, 2)->default(100);   // porsi akun yang dianggap biaya produksi
            $table->decimal('amount_monthly', 18, 2)->default(0); // dipakai bila source = manual
            $table->string('notes', 255)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['group_key', 'department_id']);
        });

        // Pindahkan data lama supaya tidak hilang.
        if (Schema::hasTable('production_cost_pools')) {
            foreach (DB::table('production_cost_pools')->get() as $pool) {
                DB::table('production_cost_components')->insert([
                    'group_key'      => $pool->department_id ? 'divisi' : 'non_produksi',
                    'department_id'  => $pool->department_id,
                    'name'           => $pool->name,
                    'source'         => 'manual',
                    'amount_monthly' => $pool->amount_monthly,
                    'notes'          => $pool->notes,
                    'is_active'      => $pool->is_active,
                    'created_at'     => $pool->created_at,
                    'updated_at'     => $pool->updated_at,
                ]);
            }
        }

        if (Schema::hasTable('production_cost_account_maps')) {
            foreach (DB::table('production_cost_account_maps')->get() as $map) {
                $name = DB::table('accounts')->where('id', $map->account_id)->value('name');
                DB::table('production_cost_components')->insert([
                    'group_key'     => $map->department_id ? 'divisi' : 'non_produksi',
                    'department_id' => $map->department_id,
                    'name'          => $name ?: 'Akun #' . $map->account_id,
                    'source'        => 'akun',
                    'account_id'    => $map->account_id,
                    'percentage'    => $map->percentage,
                    'is_active'     => $map->is_active,
                    'created_at'    => $map->created_at,
                    'updated_at'    => $map->updated_at,
                ]);
            }
        }

        Schema::dropIfExists('production_cost_account_maps');
        Schema::dropIfExists('production_cost_pools');

        // basis 'pool' ditambahkan: biaya divisi non-produksi ikut dibagi per jam,
        // bukan diabaikan. Enum diganti string supaya tidak perlu ALTER enum lagi.
        Schema::table('production_cost_department_settings', function (Blueprint $table) {
            $table->string('basis', 20)->default('abaikan')->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_cost_components');
    }
};
