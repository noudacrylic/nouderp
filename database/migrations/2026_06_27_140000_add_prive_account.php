<?php

use App\Core\Accounting\Account;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Akun Prive (pengambilan pribadi pemilik) — kontra-ekuitas, saldo normal debit.
     * Dipakai asisten Telegram untuk mencatat "prive" sebagai Pengeluaran umum
     * (Dr Prive / Cr Kas). Dibuat sebagai anak dari Modal Awal (3000) bila ada.
     */
    public function up(): void
    {
        if (Account::where('code', '3200')->exists()) {
            return;
        }

        Account::create([
            'code'           => '3200',
            'name'           => 'Prive',
            'type'           => 'equity',
            'normal_balance' => 'debit',
            'parent_id'      => Account::where('code', '3000')->value('id'),
            'is_active'      => 1,
            'is_system'      => 0,
        ]);
    }

    public function down(): void
    {
        Account::where('code', '3200')->where('is_system', 0)->forceDelete();
    }
};
