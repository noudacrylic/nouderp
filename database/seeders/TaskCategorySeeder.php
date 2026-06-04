<?php

namespace Database\Seeders;

use App\Modules\Tasks\Models\TaskCategory;
use Illuminate\Database\Seeder;

class TaskCategorySeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['name' => 'Umum',        'color' => '#94a3b8', 'sort_order' => 1],
            ['name' => 'Pembelian',   'color' => '#f59e0b', 'sort_order' => 2],
            ['name' => 'Printing',    'color' => '#8b5cf6', 'sort_order' => 3],
            ['name' => 'Maintenance', 'color' => '#10b981', 'sort_order' => 4],
        ];

        foreach ($defaults as $row) {
            TaskCategory::firstOrCreate(['name' => $row['name']], $row + ['is_active' => true]);
        }
    }
}
