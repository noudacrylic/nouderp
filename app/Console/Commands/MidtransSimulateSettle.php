<?php

namespace App\Console\Commands;

use App\Models\MidtransSetting;
use App\Models\MidtransTransaction;
use App\Modules\Payment\Services\MidtransService;
use Illuminate\Console\Command;

/**
 * Simulasi settlement Midtrans — HANYA sandbox.
 *
 * Dipakai saat simulator sandbox Midtrans tidak bisa menuntaskan pembayaran
 * (mis. QRIS yang QR-nya tak bisa di-decode). Command ini memicu jalur webhook
 * yang sama seperti pembayaran sungguhan (MidtransService::handleNotification),
 * sehingga pesanan otomatis LUNAS di ERP dan langkah "berhasil" bisa di-screenshot.
 *
 * Contoh:
 *   php artisan midtrans:simulate-settle --latest
 *   php artisan midtrans:simulate-settle NOUD-SOQR-XXXX
 *   php artisan midtrans:simulate-settle --token=<link_token>
 */
class MidtransSimulateSettle extends Command
{
    protected $signature = 'midtrans:simulate-settle
        {order_id? : order_id transaksi Midtrans}
        {--token= : link_token halaman /pay}
        {--latest : ambil transaksi pending terakhir}';

    protected $description = 'Simulasikan pembayaran Midtrans sukses (SANDBOX saja) agar pesanan jadi LUNAS';

    public function handle(MidtransService $midtrans): int
    {
        if (MidtransSetting::singleton()->is_production) {
            $this->error('DITOLAK: mode Production aktif. Command ini hanya untuk sandbox/testing.');

            return self::FAILURE;
        }

        $trx = $this->resolveTrx();
        if (! $trx) {
            $this->error('Transaksi tidak ditemukan. Beri order_id, --token, atau --latest.');

            return self::FAILURE;
        }

        if ($trx->isPaid()) {
            $this->warn("Transaksi {$trx->order_id} sudah LUNAS.");

            return self::SUCCESS;
        }

        $payload = [
            'order_id' => $trx->order_id,
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'status_code' => '200',
            'gross_amount' => (string) (int) round($trx->gross_amount),
            'payment_type' => $this->paymentTypeFor($trx->channel),
            'transaction_time' => now()->toDateTimeString(),
        ];

        $this->info("Mensimulasikan settlement untuk {$trx->order_id} (channel={$trx->channel}, Rp" . number_format($trx->gross_amount, 0, ',', '.') . ')…');

        $trx = $midtrans->handleNotification($payload);

        if ($trx->isPaid()) {
            $this->info("✓ LUNAS. customer_payment_id={$trx->customer_payment_id}. Silakan cek/screenshot status di ERP.");

            return self::SUCCESS;
        }

        $this->error('Gagal menuntaskan. Status sekarang: ' . $trx->status);

        return self::FAILURE;
    }

    protected function resolveTrx(): ?MidtransTransaction
    {
        if ($id = $this->argument('order_id')) {
            return MidtransTransaction::where('order_id', $id)->first();
        }
        if ($token = $this->option('token')) {
            return MidtransTransaction::where('link_token', $token)->latest('id')->first();
        }
        if ($this->option('latest')) {
            return MidtransTransaction::where('status', 'pending')->latest('id')->first();
        }

        return null;
    }

    /** Perkiraan payment_type Midtrans dari grup channel internal (untuk kelengkapan payload). */
    protected function paymentTypeFor(string $channel): string
    {
        return match ($channel) {
            'qris' => 'qris',
            'va', 'bank_transfer' => 'bank_transfer',
            'ewallet' => 'gopay',
            'alfamart' => 'cstore',
            'paylater' => 'akulaku',
            'credit_card' => 'credit_card',
            default => 'bank_transfer',
        };
    }
}
