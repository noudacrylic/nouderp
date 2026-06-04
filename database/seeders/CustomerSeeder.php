<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Customer;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        Customer::create([
            'code' => 'CUST-001',
            'name' => 'Suwandi',
            'phone' => '08123456789',
            'address' => 'Semarang',
            'is_active' => true,
        ]);

        Customer::create([
            'code' => 'CUST-002',
            'name' => 'Shopee',
            'phone' => '-',
            'address' => 'Marketplace',
            'is_active' => true,
        ]);
    }
}
