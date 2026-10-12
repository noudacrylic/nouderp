<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hutang PPh Final UMKM — pajak setor sendiri yang belum disetor.
 *
 * 1. Akun 2114 Hutang PPh Final UMKM. Pajak masa dibukukan Dr 5012 / Cr 2114; setoran
 *    Dr 2114 / Cr kas-bank. Saldo 2114 = pajak yang belum disetor.
 * 2. tax_accruals — total yang sudah dibukukan ke 2114 per masa (jurnal penyesuaian
 *    `pph_final_accrual` menyusul bila angka masa berubah).
 * 3. tax_payments.via_hutang — setoran yang mendebit 2114 (setoran lama mendebit 5012 langsung,
 *    jadi tidak ikut dibukukan sebagai hutang).
 * 4. Marketplace memungut pajak atas pesanan yang MASUK sejak 2 Okt 2026 (bukan yang selesai
 *    sejak 1 Okt).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!DB::table('accounts')->where('code', '2114')->exists()) {
            DB::table('accounts')->insert([
                'code' => '2114', 'name' => 'Hutang PPh Final UMKM', 'type' => 'liability',
                'normal_balance' => 'credit', 'is_system' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Schema::create('tax_accruals', function (Blueprint $table) {
            $table->id();
            $table->string('tax_type', 30)->default('pph_final_umkm');
            $table->date('period');
            $table->decimal('amount', 18, 2)->default(0);
            $table->timestamps();

            $table->unique(['tax_type', 'period']);
        });

        Schema::table('tax_payments', function (Blueprint $table) {
            $table->boolean('via_hutang')->default(false)->after('cash_disbursement_id');
        });

        DB::table('marketplace_configs')->where('tax_start_date', '2026-10-01')
            ->update(['tax_start_date' => '2026-10-02']);
    }

    public function down(): void
    {
        Schema::table('tax_payments', function (Blueprint $table) {
            $table->dropColumn('via_hutang');
        });
        Schema::dropIfExists('tax_accruals');
    }
};
