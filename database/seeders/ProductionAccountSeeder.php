<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Core\Accounting\Account;

class ProductionAccountSeeder extends Seeder
{
    public function run(): void
    {
        Account::updateOrCreate(
            ['code' => '1140'],
            [
                'name'             => 'Barang Dalam Proses Produksi (WIP)',
                'type'             => 'asset',
                'normal_balance'   => 'debit',
                'is_system'        => 1,
                'account_category' => 'inventory',
                'is_active'        => 1,
            ]
        );
    }
}
