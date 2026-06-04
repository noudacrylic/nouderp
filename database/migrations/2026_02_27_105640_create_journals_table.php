<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('journals', function (Blueprint $table) {
            $table->id();

            $table->string('journal_number')->unique();
            $table->date('date');

            $table->string('reference_type');
            $table->unsignedBigInteger('reference_id');

            $table->text('description')->nullable();

            $table->unsignedBigInteger('period_id');

            $table->enum('status', ['posted', 'void'])->default('posted');

            $table->timestamp('posted_at')->nullable();
            $table->timestamp('voided_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journals');
    }
};
