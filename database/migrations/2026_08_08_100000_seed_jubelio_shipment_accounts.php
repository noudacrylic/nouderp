<?php

use App\Core\Accounting\Account;
use App\Models\FreightSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Akun COA untuk Jubelio Shipment: 1116 Saldo (Coins) + 5292 Beban Layanan.
 *
 * Migrasi 2026_08_07_110000 hanya menambah KOLOM `jubelio_saldo_account_id` &
 * `jubelio_fee_account_id` di freight_settings; akunnya sendiri tidak pernah dibuat —
 * beda dengan jalur Biteship (2026_06_03_180000) yang membuat 1115/5291 sekaligus
 * memasangnya. Akibatnya di instalasi yang operatornya belum mengisi Settings →
 * Pengaturan Ongkir, tiap resi Jubelio terbit TANPA jurnal ongkir sama sekali:
 * ShippingAccountingService melempar DomainException, ditangkap ShipmentBookingService,
 * dan hanya muncul sebagai flash kuning yang mudah terlewat.
 *
 * 5292 sengaja TIDAK berkategori `bank_admin_fee` — lihat 2026_08_01_150000, kategori itu
 * dikunci ke 5103 supaya form Transfer Antar Bank/Bayar Gaji tidak salah ambil akun.
 */
return new class extends Migration {
    private const SALDO_CODE = '1116';
    private const FEE_CODE   = '5292';

    public function up(): void
    {
        // Akun deposit Coins — kas-like supaya bisa jadi tujuan Transfer Antar Bank (top-up).
        $saldo = Account::firstOrCreate(
            ['code' => self::SALDO_CODE],
            [
                'name'             => 'Saldo Jubelio Shipment',
                'type'             => 'asset',
                'normal_balance'   => 'debit',
                'is_cash_account'  => 1,
                'account_category' => 'cash_equivalent',
                'is_active'        => 1,
            ]
        );

        // Beban layanan: selisih saat rekonsiliasi saldo Coins (biaya cek ongkir/resi dll).
        $fee = Account::firstOrCreate(
            ['code' => self::FEE_CODE],
            [
                'name'             => 'Beban Layanan Jubelio Shipment',
                'type'             => 'expense',
                'normal_balance'   => 'debit',
                'is_cash_account'  => 0,
                'account_category' => null,
                'is_active'        => 1,
            ]
        );

        // Pasang hanya bila masih kosong — jangan menimpa pilihan operator yang sudah
        // terlanjur menunjuk akun lain (mis. dibuat manual dengan kode berbeda).
        $fs = FreightSetting::singleton();
        $patch = [];
        if (!$fs->jubelio_saldo_account_id) $patch['jubelio_saldo_account_id'] = $saldo->id;
        if (!$fs->jubelio_fee_account_id)   $patch['jubelio_fee_account_id']   = $fee->id;
        if ($patch) $fs->update($patch);
    }

    public function down(): void
    {
        $ids = Account::whereIn('code', [self::SALDO_CODE, self::FEE_CODE])->pluck('id', 'code');

        DB::table('freight_settings')
            ->whereIn('jubelio_saldo_account_id', $ids->values())
            ->update(['jubelio_saldo_account_id' => null]);
        DB::table('freight_settings')
            ->whereIn('jubelio_fee_account_id', $ids->values())
            ->update(['jubelio_fee_account_id' => null]);

        // Akun yang sudah kena jurnal TIDAK dihapus — rollback tidak boleh memutus histori.
        foreach ($ids as $id) {
            $terpakai = DB::table('journal_lines')->where('account_id', $id)->exists();
            if (!$terpakai) {
                Account::where('id', $id)->forceDelete();
            }
        }
    }
};
