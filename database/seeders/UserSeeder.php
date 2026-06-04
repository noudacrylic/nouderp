<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['username' => 'admin'],
            [
                'name'      => 'Administrator',
                'email'     => null,
                'password'  => Hash::make('admin123'),
                'role'      => 'super_admin',
                'is_active' => true,
            ]
        );

        $this->command?->info('Default super_admin: username=admin, password=admin123 — GANTI SEGERA setelah login pertama.');
    }
}
