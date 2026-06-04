<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('midtrans_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('order_id', 64)->unique();
            $table->foreignId('sales_invoice_id')->nullable()->constrained('sales_invoices')->nullOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('customers');
            $table->enum('source', ['link', 'instore']);
            $table->string('link_token', 64)->nullable()->unique();
            $table->enum('channel', ['qris', 'va', 'bank_transfer', 'snap_auto']);
            $table->string('bank', 32)->nullable();
            $table->decimal('gross_amount', 18, 2);
            $table->decimal('base_amount', 18, 2);
            $table->decimal('customer_admin_fee', 18, 2)->default(0);
            $table->decimal('midtrans_fee', 18, 2)->nullable();
            $table->string('snap_token', 64)->nullable();
            $table->string('snap_redirect_url', 255)->nullable();
            $table->text('qris_payload')->nullable();
            $table->string('va_number', 64)->nullable();
            $table->enum('status', ['pending', 'settlement', 'expire', 'cancel', 'deny', 'refund', 'failure'])->default('pending');
            $table->string('fraud_status', 32)->nullable();
            $table->dateTime('transaction_time')->nullable();
            $table->dateTime('expired_at');
            $table->dateTime('settled_at')->nullable();
            $table->foreignId('customer_payment_id')->nullable()->constrained('customer_payments')->nullOnDelete();
            $table->longText('raw_response')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['sales_invoice_id', 'status']);
            $table->index('status');
        });

        // ===== SEED: 4 master accounts for Midtrans =====
        // Pakai DB::table dgn check existence — aman kalau di-run di environment yang
        // sudah punya manual entry / di-run ulang setelah rollback.
        $accounts = [
            ['code' => '1112', 'name' => 'Kas Midtrans QRIS',          'type' => 'asset',   'normal_balance' => 'debit', 'is_system' => 1, 'account_category' => 'cash_equivalent'],
            ['code' => '1113', 'name' => 'Kas Midtrans VA',            'type' => 'asset',   'normal_balance' => 'debit', 'is_system' => 1, 'account_category' => 'cash_equivalent'],
            ['code' => '1114', 'name' => 'Kas Midtrans Bank Transfer', 'type' => 'asset',   'normal_balance' => 'debit', 'is_system' => 1, 'account_category' => 'cash_equivalent'],
            ['code' => '5290', 'name' => 'Beban Midtrans Gateway',     'type' => 'expense', 'normal_balance' => 'debit', 'is_system' => 1, 'account_category' => 'bank_admin_fee'],
        ];

        foreach ($accounts as $acc) {
            $exists = DB::table('accounts')->where('code', $acc['code'])->exists();
            if (!$exists) {
                DB::table('accounts')->insert(array_merge($acc, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }

        // ===== SEED: payment_fee_settings utk 3 kas Midtrans =====
        // Pointer ke beban Midtrans Gateway. Nilai fee_flat & fee_percent =0;
        // perhitungan fee real (Rp 4.000 VA, 0.7% QRIS) di-handle di MidtransFeeCalculator.
        $expenseId = DB::table('accounts')->where('code', '5290')->value('id');
        foreach (['1112', '1113', '1114'] as $cashCode) {
            $cashId = DB::table('accounts')->where('code', $cashCode)->value('id');
            if (!$cashId || !$expenseId) continue;

            $exists = DB::table('payment_fee_settings')->where('cash_account_id', $cashId)->exists();
            if (!$exists) {
                DB::table('payment_fee_settings')->insert([
                    'cash_account_id' => $cashId,
                    'fee_flat' => 0,
                    'fee_percent' => 0,
                    'expense_account_id' => $expenseId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('midtrans_transactions');

        // Cleanup seed data
        $expenseId = DB::table('accounts')->where('code', '5290')->value('id');
        foreach (['1112', '1113', '1114'] as $cashCode) {
            $cashId = DB::table('accounts')->where('code', $cashCode)->value('id');
            if ($cashId) {
                DB::table('payment_fee_settings')->where('cash_account_id', $cashId)->delete();
            }
        }
        DB::table('accounts')->whereIn('code', ['1112', '1113', '1114', '5290'])->delete();
    }
};
