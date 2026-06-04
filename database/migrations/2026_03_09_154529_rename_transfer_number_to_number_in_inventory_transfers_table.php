<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('inventory_transfers', function (Blueprint $table) {
            $table->renameColumn('transfer_number', 'number');
        });
    }

    public function down()
    {
        Schema::table('inventory_transfers', function (Blueprint $table) {
            $table->renameColumn('number', 'transfer_number');
        });
    }
};
