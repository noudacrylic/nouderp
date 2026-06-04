<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kas & Bank di-restruktur: sub-tipe disbursement/receipt digabung ke umbrella
 * `cash-bank.disbursements` & `cash-bank.receipts` (gaya Absensi). Migrasi permission
 * user biasa: siapa pun yang punya salah satu key lama → diberi key umbrella; key lama dihapus.
 */
return new class extends Migration {
    private array $map = [
        'cash-bank.disbursements' => [
            'cash-bank.disbursements.general',
            'cash-bank.disbursements.freight',
            'cash-bank.disbursements.refund',
            'cash-bank.salary-payments',
        ],
        'cash-bank.receipts' => [
            'cash-bank.receipts.general',
            'cash-bank.receipts.refund',
        ],
    ];

    public function up(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('user_menu_permissions')) return;

        foreach ($this->map as $umbrella => $oldKeys) {
            // User yang punya minimal 1 key lama → pastikan punya umbrella.
            $userIds = DB::table('user_menu_permissions')->whereIn('menu_key', $oldKeys)
                ->distinct()->pluck('user_id');

            foreach ($userIds as $uid) {
                $exists = DB::table('user_menu_permissions')
                    ->where('user_id', $uid)->where('menu_key', $umbrella)->exists();
                if (!$exists) {
                    DB::table('user_menu_permissions')->insert([
                        'user_id'    => $uid,
                        'menu_key'   => $umbrella,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // Hapus key lama (sudah tidak valid di registry).
            DB::table('user_menu_permissions')->whereIn('menu_key', $oldKeys)->delete();
        }
    }

    public function down(): void
    {
        // Tidak reversibel granular (umbrella → sub-tipe). Biarkan apa adanya.
    }
};
