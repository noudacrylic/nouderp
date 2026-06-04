<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update existing custom products to preorder
        \Illuminate\Support\Facades\DB::table('products')
            ->where('sale_type', 'custom')
            ->update(['sale_type' => 'preorder']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reverse needed as we want to permanently merge custom into preorder
    }
};
