<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_quotation_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('quotation_id');
            $table->string('image_path');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('quotation_id')
                ->references('id')->on('sales_quotations')
                ->onDelete('cascade');

            $table->index('quotation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_quotation_attachments');
    }
};
