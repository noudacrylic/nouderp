<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tax_settings', function (Blueprint $table) {

            $table->id();

            $table->string('code'); // PPN, PPH23
            $table->string('name');

            $table->decimal('default_percent', 5, 2);

            $table->unsignedBigInteger('account_id');

            $table->boolean('is_withholding')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->foreign('account_id')
                ->references('id')
                ->on('accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_settings');
    }
};
