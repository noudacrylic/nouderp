<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE production_orders MODIFY priority_level ENUM('low', 'medium', 'high', 'very_high', 'urgent') NULL");
    }

    public function down(): void
    {
        // Turunkan level 'urgent' ke 'very_high' agar tidak melanggar enum lama.
        DB::table('production_orders')->where('priority_level', 'urgent')->update(['priority_level' => 'very_high']);
        DB::statement("ALTER TABLE production_orders MODIFY priority_level ENUM('low', 'medium', 'high', 'very_high') NULL");
    }
};
