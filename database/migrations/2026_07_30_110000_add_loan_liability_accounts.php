<?php

use App\Core\Accounting\Account;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Akun hutang untuk kas masuk yang bukan pendapatan (pinjaman bank, hutang ke
     * pemilik, hutang lain-lain). Dipakai di Kas & Bank > Pemasukan Umum
     * (Dr Kas / Cr Hutang) dan pelunasannya di Pengeluaran Umum (Dr Hutang / Cr Kas).
     *
     * Sengaja BUKAN 2101 Hutang Usaha — akun itu dibentuk faktur pembelian, jadi
     * saldonya harus tetap cocok dengan modul Pembelian.
     */
    protected array $accounts = [
        ['code' => '2120', 'name' => 'Hutang Bank / Pinjaman'],
        ['code' => '2121', 'name' => 'Hutang Pemilik'],
        ['code' => '2130', 'name' => 'Hutang Lain-lain'],
    ];

    public function up(): void
    {
        foreach ($this->accounts as $acc) {
            if (Account::where('code', $acc['code'])->exists()) {
                continue;
            }

            Account::create([
                'code'           => $acc['code'],
                'name'           => $acc['name'],
                'type'           => 'liability',
                'normal_balance' => 'credit',
                'is_active'      => 1,
                'is_system'      => 0,
            ]);
        }
    }

    public function down(): void
    {
        foreach ($this->accounts as $acc) {
            Account::where('code', $acc['code'])->where('is_system', 0)->forceDelete();
        }
    }
};
