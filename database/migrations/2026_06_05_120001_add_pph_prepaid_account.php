<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tambah akun "PPh 23 Dibayar Dimuka" (1109) bila belum ada.
 * PPh yang dipotong customer atas penjualan adalah pajak dibayar dimuka (ASET/kredit
 * pajak), bukan hutang — dipakai InvoicePostingService untuk menyeimbangkan jurnal
 * invoice ber-PPh. Idempotent (untuk DB yang sudah jalan; DB fresh sudah lewat seeder).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!DB::table('accounts')->where('code', '1109')->exists()) {
            DB::table('accounts')->insert([
                'code' => '1109',
                'name' => 'PPh 23 Dibayar Dimuka',
                'type' => 'asset',
                'normal_balance' => 'debit',
                'is_system' => 1,
                'is_active' => 1,
                'account_category' => 'receivable',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Hanya hapus bila belum dipakai jurnal (aman).
        $id = DB::table('accounts')->where('code', '1109')->value('id');
        if ($id && !DB::table('journal_lines')->where('account_id', $id)->exists()) {
            DB::table('accounts')->where('id', $id)->delete();
        }
    }
};
