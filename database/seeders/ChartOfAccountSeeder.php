<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Core\Accounting\Account;

class ChartOfAccountSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            // ===== ASSET =====
            ['code' => '1101', 'name' => 'Kas', 'type' => 'asset', 'normal_balance' => 'debit', 'is_system' => 1, 'account_category' => 'cash'],
            ['code' => '1102', 'name' => 'Bank BCA', 'type' => 'asset', 'normal_balance' => 'debit', 'is_system' => 0, 'account_category' => 'cash_equivalent'],
            ['code' => '1103', 'name' => 'Bank BRI', 'type' => 'asset', 'normal_balance' => 'debit', 'is_system' => 0, 'account_category' => 'cash_equivalent'],
            ['code' => '1104', 'name' => 'Saldo Penjualan Shopee', 'type' => 'asset', 'normal_balance' => 'debit', 'is_system' => 0, 'account_category' => 'cash_equivalent'],
            ['code' => '1120', 'name' => 'Piutang Usaha', 'type' => 'asset', 'normal_balance' => 'debit', 'is_system' => 1, 'account_category' => 'receivable'],
            ['code' => '1130', 'name' => 'Persediaan', 'type' => 'asset', 'normal_balance' => 'debit', 'is_system' => 1, 'account_category' => 'inventory'],
            ['code' => '1131', 'name' => 'Persediaan Perbaikan', 'type' => 'asset', 'normal_balance' => 'debit', 'is_system' => 1, 'account_category' => 'inventory'],
            ['code' => '1132', 'name' => 'Beban Stok Garansi', 'type' => 'expense', 'normal_balance' => 'debit', 'is_system' => 1],
            ['code' => '1140', 'name' => 'Barang Dalam Proses Produksi (WIP)', 'type' => 'asset', 'normal_balance' => 'debit', 'is_system' => 1, 'account_category' => 'inventory'],
            ['code' => '1202', 'name' => 'Saldo Ditahan Shopee', 'type' => 'asset', 'normal_balance' => 'debit', 'is_system' => 0, 'account_category' => 'cash_equivalent'],
            ['code' => '1203', 'name' => 'Titipan Pengiriman', 'type' => 'asset', 'normal_balance' => 'credit', 'is_system' => 0],
            ['code' => '1107', 'name' => 'Uang Muka Supplier', 'type' => 'asset', 'normal_balance' => 'debit', 'is_system' => 1],
            ['code' => '1108', 'name' => 'Piutang Lebih Bayar Supplier', 'type' => 'asset', 'normal_balance' => 'debit', 'is_system' => 1, 'account_category' => 'receivable'],

            // ===== LIABILITY =====
            ['code' => '2101', 'name' => 'Hutang Usaha', 'type' => 'liability', 'normal_balance' => 'credit', 'is_system' => 1],
            ['code' => '2102', 'name' => 'Pajak Masukan (PPN Masukan)', 'type' => 'asset', 'normal_balance' => 'debit', 'is_system' => 1],
            ['code' => '2103', 'name' => 'Hutang PPN Keluaran', 'type' => 'liability', 'normal_balance' => 'credit', 'is_system' => 1],
            ['code' => '2104', 'name' => 'Hutang PPh', 'type' => 'liability', 'normal_balance' => 'credit', 'is_system' => 1],
            ['code' => '2105', 'name' => 'Uang Muka Customer', 'type' => 'liability', 'normal_balance' => 'credit', 'is_system' => 1],
            ['code' => '2106', 'name' => 'Kelebihan Bayar Customer', 'type' => 'liability', 'normal_balance' => 'credit', 'is_system' => 1],
            ['code' => '2110', 'name' => 'Hutang BPJS Kesehatan', 'type' => 'liability', 'normal_balance' => 'credit', 'is_system' => 1],
            ['code' => '2111', 'name' => 'Hutang BPJS Ketenagakerjaan', 'type' => 'liability', 'normal_balance' => 'credit', 'is_system' => 1],
            ['code' => '2112', 'name' => 'Hutang PPh 21', 'type' => 'liability', 'normal_balance' => 'credit', 'is_system' => 1],
            ['code' => '2113', 'name' => 'Hutang Gaji Karyawan', 'type' => 'liability', 'normal_balance' => 'credit', 'is_system' => 1],

            // ===== EQUITY =====
            ['code' => '3000', 'name' => 'Modal Awal', 'type' => 'equity', 'normal_balance' => 'credit', 'is_system' => 1],
            ['code' => '3100', 'name' => 'Laba Ditahan', 'type' => 'equity', 'normal_balance' => 'credit', 'is_system' => 1],

            // ===== REVENUE =====
            ['code' => '4000', 'name' => 'Pendapatan', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_system' => 0],
            ['code' => '4001', 'name' => 'Penjualan Produk', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_system' => 1],
            ['code' => '4002', 'name' => 'Pendapatan Tambahan', 'type' => 'revenue', 'normal_balance' => 'Credit', 'is_system' => 1],
            ['code' => '4003', 'name' => 'Diskon Penjualan', 'type' => 'revenue', 'normal_balance' => 'debit', 'is_system' => 1],
            ['code' => '4010', 'name' => 'Pendapatan Jasa', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_system' => 0],
            ['code' => '4011', 'name' => 'Pendapatan Pengiriman', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_system' => 1],
            ['code' => '4100', 'name' => 'Pendapatan Selisih Stok', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_system' => 1],

            // ===== EXPENSE =====
            ['code' => '5000', 'name' => 'Beban Operasional', 'type' => 'expense', 'normal_balance' => 'debit', 'is_system' => 1],
            ['code' => '5001', 'name' => 'Harga Pokok Penjualan', 'type' => 'expense', 'normal_balance' => 'debit', 'is_system' => 1],
            ['code' => '5101', 'name' => 'Beban Admin Shopee', 'type' => 'expense', 'normal_balance' => 'debit', 'is_system' => 0],
            ['code' => '5102', 'name' => 'Beban Pengiriman', 'type' => 'expense', 'normal_balance' => 'debit', 'is_system' => 0],
            ['code' => '5103', 'name' => 'Beban Administrasi Bank', 'type' => 'expense', 'normal_balance' => 'debit', 'is_system' => 1, 'account_category' => 'bank_admin_fee'],
            ['code' => '5200', 'name' => 'Beban Selisih Stok', 'type' => 'expense', 'normal_balance' => 'debit', 'is_system' => 1],
            ['code' => '6105', 'name' => 'Beban Kerugian Retur', 'type' => 'expense', 'normal_balance' => 'debit', 'is_system' => 1],
            ['code' => '6106', 'name' => 'Beban Garansi', 'type' => 'expense', 'normal_balance' => 'debit', 'is_system' => 1],

            // ===== PAYROLL (SDM) =====
            ['code' => '5300', 'name' => 'Beban Gaji Karyawan', 'type' => 'expense', 'normal_balance' => 'debit', 'is_system' => 1],
            ['code' => '5301', 'name' => 'Beban Tunjangan & Bonus Karyawan', 'type' => 'expense', 'normal_balance' => 'debit', 'is_system' => 1],
            ['code' => '5302', 'name' => 'Beban Lembur Karyawan', 'type' => 'expense', 'normal_balance' => 'debit', 'is_system' => 1],
            ['code' => '5303', 'name' => 'Beban BPJS Perusahaan', 'type' => 'expense', 'normal_balance' => 'debit', 'is_system' => 1],
            ['code' => '5304', 'name' => 'Beban THR', 'type' => 'expense', 'normal_balance' => 'debit', 'is_system' => 1],
            ['code' => '1150', 'name' => 'Piutang Kasbon Karyawan', 'type' => 'asset', 'normal_balance' => 'debit', 'is_system' => 1, 'account_category' => 'receivable'],

            // ===== FIXED ASSETS (Aset Tetap) =====
            ['code' => '1210', 'name' => 'Tanah', 'type' => 'asset', 'normal_balance' => 'debit', 'is_system' => 1],
            ['code' => '1220', 'name' => 'Bangunan', 'type' => 'asset', 'normal_balance' => 'debit', 'is_system' => 1],
            ['code' => '1228', 'name' => 'Akumulasi Penyusutan Bangunan', 'type' => 'asset', 'normal_balance' => 'credit', 'is_system' => 1],
            ['code' => '1230', 'name' => 'Mesin & Peralatan', 'type' => 'asset', 'normal_balance' => 'debit', 'is_system' => 1],
            ['code' => '1238', 'name' => 'Akumulasi Penyusutan Mesin & Peralatan', 'type' => 'asset', 'normal_balance' => 'credit', 'is_system' => 1],
            ['code' => '1240', 'name' => 'Kendaraan', 'type' => 'asset', 'normal_balance' => 'debit', 'is_system' => 1],
            ['code' => '1248', 'name' => 'Akumulasi Penyusutan Kendaraan', 'type' => 'asset', 'normal_balance' => 'credit', 'is_system' => 1],
            ['code' => '1250', 'name' => 'Furniture & Fixture', 'type' => 'asset', 'normal_balance' => 'debit', 'is_system' => 1],
            ['code' => '1258', 'name' => 'Akumulasi Penyusutan Furniture & Fixture', 'type' => 'asset', 'normal_balance' => 'credit', 'is_system' => 1],
            ['code' => '1290', 'name' => 'Aset Tetap Lainnya', 'type' => 'asset', 'normal_balance' => 'debit', 'is_system' => 0],

            // Beban Depresiasi
            ['code' => '6220', 'name' => 'Beban Depresiasi Bangunan', 'type' => 'expense', 'normal_balance' => 'debit', 'is_system' => 1],
            ['code' => '6230', 'name' => 'Beban Depresiasi Mesin & Peralatan', 'type' => 'expense', 'normal_balance' => 'debit', 'is_system' => 1],
            ['code' => '6240', 'name' => 'Beban Depresiasi Kendaraan', 'type' => 'expense', 'normal_balance' => 'debit', 'is_system' => 1],
            ['code' => '6250', 'name' => 'Beban Depresiasi Furniture & Fixture', 'type' => 'expense', 'normal_balance' => 'debit', 'is_system' => 1],

            // Disposisi Aset
            ['code' => '7100', 'name' => 'Keuntungan Pelepasan Aset', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_system' => 1],
            ['code' => '7200', 'name' => 'Kerugian Pelepasan Aset', 'type' => 'expense', 'normal_balance' => 'debit', 'is_system' => 1],
        ];

        foreach ($accounts as $acc) {
            $acc['is_active'] = 1;
            $acc['is_cash_account'] = in_array($acc['account_category'] ?? null, ['cash', 'cash_equivalent']) ? 1 : 0;

            Account::updateOrCreate(
                ['code' => $acc['code']], // kunci berdasarkan CODE
                $acc
            );
        }
    }
}
