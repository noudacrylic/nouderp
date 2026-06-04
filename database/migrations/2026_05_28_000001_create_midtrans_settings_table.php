<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('midtrans_settings', function (Blueprint $table) {
            $table->id();
            $table->string('server_key')->nullable();
            $table->string('client_key')->nullable();
            $table->string('merchant_id')->nullable();
            $table->boolean('is_production')->default(false);
            $table->unsignedInteger('link_expiry_days')->default(7);
            $table->unsignedInteger('qris_expiry_minutes')->default(15);
            // Tarif MDR (potongan Midtrans) — sumber tunggal, bukan dari webhook.
            $table->decimal('va_fee', 18, 2)->default(4000);          // flat per transaksi VA/Transfer
            $table->decimal('qris_fee_percent', 6, 3)->default(0.7);  // persen dari gross utk QRIS
            // Biaya admin yang dibebankan ke customer.
            $table->decimal('customer_fee_threshold', 18, 2)->default(500000);
            $table->decimal('customer_fee_amount', 18, 2)->default(2000);
            // Akun: kas tunggal + beban gateway.
            $table->foreignId('cash_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('fee_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->timestamps();
        });

        // ===== Akun tunggal Saldo Midtrans (1111) =====
        if (!DB::table('accounts')->where('code', '1111')->exists()) {
            DB::table('accounts')->insert([
                'code' => '1111',
                'name' => 'Saldo Midtrans',
                'type' => 'asset',
                'normal_balance' => 'debit',
                'is_system' => 1,
                'is_active' => 1,
                'account_category' => 'cash_equivalent',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $cashId = DB::table('accounts')->where('code', '1111')->value('id');
        $feeId = DB::table('accounts')->where('code', '5290')->value('id');

        // payment_fee_settings utk kas tunggal → beban gateway (fee real dihitung di service).
        if ($cashId && $feeId && !DB::table('payment_fee_settings')->where('cash_account_id', $cashId)->exists()) {
            DB::table('payment_fee_settings')->insert([
                'cash_account_id' => $cashId,
                'fee_flat' => 0,
                'fee_percent' => 0,
                'expense_account_id' => $feeId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Pensiunkan 3 akun per-channel lama (disimpan utk histori, dinonaktifkan).
        DB::table('accounts')->whereIn('code', ['1112', '1113', '1114'])->update([
            'is_active' => 0,
            'updated_at' => now(),
        ]);

        // ===== Seed 1 baris setting dari nilai .env/config saat ini =====
        DB::table('midtrans_settings')->insert([
            'server_key' => config('services.midtrans.server_key'),
            'client_key' => config('services.midtrans.client_key'),
            'merchant_id' => config('services.midtrans.merchant_id'),
            'is_production' => (bool) config('services.midtrans.is_production', false),
            'link_expiry_days' => (int) config('services.midtrans.link_expiry_days', 7),
            'qris_expiry_minutes' => (int) config('services.midtrans.qris_expiry_minutes', 15),
            'va_fee' => 4000,
            'qris_fee_percent' => 0.7,
            'customer_fee_threshold' => 500000,
            'customer_fee_amount' => 2000,
            'cash_account_id' => $cashId,
            'fee_account_id' => $feeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('midtrans_settings');

        // Aktifkan kembali akun per-channel lama.
        DB::table('accounts')->whereIn('code', ['1112', '1113', '1114'])->update([
            'is_active' => 1,
            'updated_at' => now(),
        ]);

        $cashId = DB::table('accounts')->where('code', '1111')->value('id');
        if ($cashId) {
            DB::table('payment_fee_settings')->where('cash_account_id', $cashId)->delete();
        }
        DB::table('accounts')->where('code', '1111')->delete();
    }
};
