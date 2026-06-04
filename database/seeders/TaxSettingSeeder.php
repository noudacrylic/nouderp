<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TaxSetting;

class TaxSettingSeeder extends Seeder
{
    public function run(): void
    {
        TaxSetting::firstOrCreate([
            'code' => 'PPN'
        ], [
            'name' => 'PPN',
            'default_percent' => 11.00,
            'account_id' => \App\Core\Accounting\Account::where('code', '2110')->value('id') ?? 1,
            'is_withholding' => false,
            'is_active' => true
        ]);
    }
}
