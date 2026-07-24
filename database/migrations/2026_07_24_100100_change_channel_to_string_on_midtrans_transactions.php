<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * `channel` semula enum('qris','va','bank_transfer','snap_auto') — terlalu kaku
     * untuk metode Snap (customer memilih sendiri) & channel baru (gopay, shopeepay,
     * alfamart/cstore, akulaku, kredivo, credit_card). Ubah ke VARCHAR agar bebas.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE midtrans_transactions MODIFY channel VARCHAR(30) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE midtrans_transactions MODIFY channel ENUM('qris','va','bank_transfer','snap_auto') NOT NULL");
    }
};
