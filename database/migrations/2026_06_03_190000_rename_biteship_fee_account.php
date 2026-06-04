<?php

use Illuminate\Database\Migrations\Migration;
use App\Core\Accounting\Account;

/**
 * Rename akun 5291 → "Beban API Biteship" (sebelumnya "Beban Layanan Pengiriman (Biteship)").
 * Sekarang dipakai untuk pencatatan biaya request API harian (Settings → Pengaturan Ongkir →
 * "Catat Biaya API Biteship"): Dr 5291 / Cr 1115 Saldo Biteship.
 */
return new class extends Migration {
    public function up(): void
    {
        Account::where('code', '5291')->update(['name' => 'Beban API Biteship']);
    }

    public function down(): void
    {
        Account::where('code', '5291')->update(['name' => 'Beban Layanan Pengiriman (Biteship)']);
    }
};
