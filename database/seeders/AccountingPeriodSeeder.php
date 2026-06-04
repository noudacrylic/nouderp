<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Core\Period\AccountingPeriod;
use Carbon\Carbon;

class AccountingPeriodSeeder extends Seeder
{
    public function run(): void
    {
        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        AccountingPeriod::truncate();
        \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();

        $year = now()->year;
        $month = now()->month;

        AccountingPeriod::create([
            'year' => $year,
            'month' => $month,
            'start_date' => Carbon::create($year, $month, 1, 0, 0, 0),
            'end_date' => Carbon::create($year, $month, 1, 0, 0, 0)->endOfMonth(),
            'status' => 'open'
        ]);
    }
}
