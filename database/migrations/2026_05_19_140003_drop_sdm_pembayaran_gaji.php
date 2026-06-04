<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('sdm_pembayaran_gaji_item');
        Schema::dropIfExists('sdm_pembayaran_gaji');
    }

    public function down(): void
    {
        // No-op: replaced by CashDisbursement type=salary flow.
    }
};
