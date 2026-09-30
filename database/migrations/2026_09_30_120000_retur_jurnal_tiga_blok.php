<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retur jurnal tiga blok + potongan marketplace dipisah per akun.
 *
 * 1. Akun 5012 PPh Final UMKM 0,5% (sudah ada di buku besar; dibuat hanya bila belum ada). Pajak
 *    dulu dilebur ke Beban Admin, jadi pajak yang dipotong marketplace tiap bulan tak terlihat.
 *    Premi asuransi / Program Hemat Biaya Kirim SENGAJA tetap di Beban Admin (keputusan user
 *    30 Sep): dipotong di setiap pesanan, jadi akun terpisah yang hanya terisi dari retur
 *    menyesatkan.
 * 2. marketplace_configs: akun pajak dan % pajak. Persen bawaan 0 — potongan pajak marketplace
 *    baru berlaku ±Okt/Nov 2026, isi 0,5 saat mulai.
 * 3. sales_returns: blok Pembalikan & Penyelesaian yang ditulis/diubah admin, hasil banding, nilai
 *    penjualan yang benar-benar dibalik (dibaca rekonsiliasi), dan potongan yang dibukukan saat
 *    retur menuntaskan pesanan (dibatalkan lagi saat retur di-void).
 */
return new class extends Migration
{
    public function up(): void
    {
        $pajakId = $this->akun('5012', 'PPh Final UMKM 0,5%');

        Schema::table('marketplace_configs', function (Blueprint $table) {
            $table->foreignId('account_tax_id')->nullable()->after('account_fee_diff_id')
                ->constrained('accounts')->nullOnDelete();
            $table->decimal('tax_percent', 5, 2)->default(0)->after('admin_fee_fixed');
        });

        DB::table('marketplace_configs')->update(['account_tax_id' => $pajakId]);

        Schema::table('sales_returns', function (Blueprint $table) {
            $table->string('appeal_result', 10)->nullable()->after('return_type');
            $table->json('journal_reversal')->nullable()->after('journal_override');
            $table->json('journal_settlement')->nullable()->after('journal_reversal');
            $table->decimal('reversed_amount', 15, 2)->nullable()->after('grand_total');
            $table->decimal('settlement_fee', 15, 2)->nullable()->after('settlement_ar_applied');
        });
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropColumn(['appeal_result', 'journal_reversal', 'journal_settlement', 'reversed_amount', 'settlement_fee']);
        });

        Schema::table('marketplace_configs', function (Blueprint $table) {
            $table->dropForeign(['account_tax_id']);
            $table->dropColumn(['account_tax_id', 'tax_percent']);
        });
        // Akun sengaja tidak dihapus — bisa sudah punya jurnal.
    }

    private function akun(string $code, string $name): int
    {
        $id = DB::table('accounts')->where('code', $code)->value('id');
        if ($id) {
            return (int) $id;
        }

        return (int) DB::table('accounts')->insertGetId([
            'code'           => $code,
            'name'           => $name,
            'type'           => 'expense',
            'normal_balance' => 'debit',
            'is_active'      => 1,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }
};
