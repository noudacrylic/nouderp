<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Core\Inventory\Warehouse;

class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        Warehouse::create([
            'name' => 'Utama',
            'location' => 'Gudang Utama',
            'is_sellable' => true,
            'is_active' => true,
        ]);

        Warehouse::create([
            'name' => 'Cadangan',
            'location' => 'Gudang Cadangan',
            'is_sellable' => false,
            'is_active' => true,
        ]);
    }
}
