<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Core\Accounting\Account;
use App\Models\FreightSetting;

/**
 * Fase 5 — akuntansi ongkir Biteship.
 * - Akun "Saldo Biteship" (deposit) + "Beban Layanan Biteship" (biaya API/cek ongkir).
 * - FreightSetting: akun saldo & akun beban layanan.
 * - sales_orders.shipping_settled: akumulasi selisih ongkir yg sudah dijurnal (idempotent).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('sales_orders', 'shipping_settled')) {
                $table->decimal('shipping_settled', 15, 2)->default(0)->after('shipping_service_name');
            }
        });

        Schema::table('freight_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('freight_settings', 'biteship_saldo_account_id')) {
                $table->unsignedBigInteger('biteship_saldo_account_id')->nullable()->after('loss_account_id');
            }
            if (!Schema::hasColumn('freight_settings', 'biteship_fee_account_id')) {
                $table->unsignedBigInteger('biteship_fee_account_id')->nullable()->after('biteship_saldo_account_id');
            }
        });

        // Akun deposit Saldo Biteship (kas-like, ikut Transfer Antar Bank).
        $saldo = Account::firstOrCreate(
            ['code' => '1115'],
            [
                'name' => 'Saldo Biteship',
                'type' => 'asset',
                'normal_balance' => 'debit',
                'is_cash_account' => 1,
                'account_category' => 'cash_equivalent',
                'is_active' => 1,
            ]
        );

        // Akun beban layanan/biaya API Biteship (cek ongkir & cek resi).
        $fee = Account::firstOrCreate(
            ['code' => '5291'],
            [
                'name' => 'Beban Layanan Pengiriman (Biteship)',
                'type' => 'expense',
                'normal_balance' => 'debit',
                'is_cash_account' => 0,
                'account_category' => 'bank_admin_fee',
                'is_active' => 1,
            ]
        );

        // Pre-set ke FreightSetting bila belum diisi.
        $fs = FreightSetting::singleton();
        $patch = [];
        if (!$fs->biteship_saldo_account_id) $patch['biteship_saldo_account_id'] = $saldo->id;
        if (!$fs->biteship_fee_account_id)   $patch['biteship_fee_account_id']   = $fee->id;
        if ($patch) $fs->update($patch);
    }

    public function down(): void
    {
        Schema::table('sales_orders', fn (Blueprint $t) => $t->dropColumn('shipping_settled'));
        Schema::table('freight_settings', function (Blueprint $t) {
            $t->dropColumn(['biteship_saldo_account_id', 'biteship_fee_account_id']);
        });
    }
};
