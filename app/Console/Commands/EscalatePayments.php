<?php

namespace App\Console\Commands;

use App\Models\PaymentSetting;
use App\Models\WebPayment;
use App\Modules\Notifications\Services\TelegramNotifier;
use Illuminate\Console\Command;

/**
 * Konfirmasi lapis 2 (cadangan): bila pembeli sudah tap "Saya sudah transfer"
 * tetapi dalam N menit belum tercocokkan otomatis (email/moota gagal/telat),
 * kirim ke owner via Telegram dengan tombol [✅ Sudah Masuk] / [❌ Batalkan].
 * Dijadwalkan tiap menit.
 */
class EscalatePayments extends Command
{
    protected $signature = 'payments:escalate';
    protected $description = 'Eskalasi pembayaran toko online yang belum terkonfirmasi ke Telegram';

    public function handle(TelegramNotifier $telegram): int
    {
        $setting = PaymentSetting::singleton();
        if (! $setting->is_active) {
            return self::SUCCESS;
        }

        $minutes     = max(1, (int) $setting->escalation_minutes);
        $qrisMinutes = $setting->qrisEscalationMinutes();

        // QRIS dieskalasi lebih cepat: deteksinya bergantung Mobile App Listener di HP,
        // jadi kegagalan lebih mungkin daripada jalur email mutasi bank.
        $due = WebPayment::where('status', WebPayment::STATUS_CLAIMED)
            ->whereNull('escalated_at')
            ->whereNotNull('buyer_claimed_at')
            ->where(function ($q) use ($minutes, $qrisMinutes) {
                $q->where(fn ($w) => $w->where('method', WebPayment::METHOD_QRIS)
                    ->where('buyer_claimed_at', '<=', now()->subMinutes($qrisMinutes)))
                  ->orWhere(fn ($w) => $w->where('method', '!=', WebPayment::METHOD_QRIS)
                    ->where('buyer_claimed_at', '<=', now()->subMinutes($minutes)));
            })
            ->with('salesOrder.customer')
            ->get();

        $accounts = $setting->accounts();

        foreach ($due as $wp) {
            $so   = $wp->salesOrder;
            $cust = $so?->customer?->name ?? '—';
            $amt  = 'Rp ' . number_format((float) $wp->expected_amount, 0, ',', '.');

            $isQris = $wp->isQris();

            $text = "🔔 <b>Konfirmasi Pembayaran Toko Online</b>\n\n"
                . "Order: <b>" . e($so?->order_number ?? ('#' . $wp->sales_order_id)) . "</b>\n"
                . "Pelanggan: " . e($cust) . "\n"
                . ($isQris
                    ? "Metode: <b>QRIS</b>\nNominal: <b>{$amt}</b>\n\n"
                      . "Pembeli menyatakan sudah bayar QRIS, tetapi belum terdeteksi otomatis. "
                      . "Cek aplikasi merchant QRIS (pastikan HP listener hidup &amp; online), lalu tekan tombol."
                    : "Nominal (dgn kode unik <b>{$wp->unique_code}</b>): <b>{$amt}</b>\n\n"
                      . "Pembeli menyatakan sudah transfer, tetapi belum tercocokkan otomatis. "
                      . "Cek mutasi rekening, lalu tekan tombol sesuai bank penerima.");

            // QRIS: satu tombol (akun kas = penampung QRIS). Transfer: satu tombol per rekening.
            $rows = [];
            if ($isQris) {
                $rows[] = [['text' => '✅ Sudah Masuk (QRIS)', 'callback_data' => "wp:confirm:{$wp->id}"]];
            } else {
                foreach ($accounts as $a) {
                    $label = trim(($a['bank_name'] ?: 'Rekening'));
                    $rows[] = [['text' => "✅ Masuk {$label}", 'callback_data' => "wp:confirm:{$wp->id}:{$a['key']}"]];
                }
            }
            if (empty($rows)) {
                $rows[] = [['text' => '✅ Sudah Masuk', 'callback_data' => "wp:confirm:{$wp->id}"]];
            }
            $rows[] = [['text' => '❌ Batalkan', 'callback_data' => "wp:cancel:{$wp->id}"]];

            $opts = ['reply_markup' => json_encode(['inline_keyboard' => $rows])];

            $primaryChat = null;
            $primaryMsg  = null;
            foreach ($telegram->approverChatIds() as $chatId) {
                $mid = $telegram->sendAndGetMessageId($chatId, $text, $opts);
                if ($mid && ! $primaryMsg) {
                    $primaryChat = $chatId;
                    $primaryMsg  = $mid;
                }
            }

            $wp->update([
                'escalated_at'        => now(),
                'telegram_chat_id'    => $primaryChat,
                'telegram_message_id' => $primaryMsg,
            ]);
        }

        $this->info("Eskalasi Telegram: {$due->count()}");
        return self::SUCCESS;
    }
}
