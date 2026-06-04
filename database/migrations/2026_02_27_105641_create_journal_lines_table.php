<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('journal_id');
            $table->unsignedBigInteger('account_id');

            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);

            $table->text('description')->nullable();

            $table->timestamps();

            $table->foreign('journal_id')
                ->references('id')
                ->on('journals')
                ->cascadeOnDelete();

            $table->foreign('account_id')
                ->references('id')
                ->on('accounts');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
    }
};
