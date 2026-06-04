<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Core\Inventory\Product;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        Product::create([
            'sku' => 'AKR-001',
            'name' => 'Kotak Saran Akrilik 1 Kotak',
            'sale_type' => 'ready',
            'base_unit' => 'pcs',
            'is_active' => true,
        ]);
    }
}
