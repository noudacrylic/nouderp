<?php

namespace App\Modules\Shipping\Services;

use App\Core\Accounting\Account;
use App\Core\Journal\Journal;
use App\Core\Journal\JournalLine;
use App\Core\Period\AccountingPeriod;
use App\Models\FreightSetting;
use App\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesOrder;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Fase 5 — akuntansi ongkir Biteship.
 *  - postBooking : saat resi dibuat → Dr Titipan Ongkir / Cr Saldo Biteship (ongkir aktual).
 *  - settleOrder : saat invoice posted / booking → selisih (N customer − C aktual) ke Pendapatan/Diskon ongkir.
 *  - reverseBooking : saat SJ di-void → balik jurnal booking + re-settle.
 *  - reconcileSaldo : samakan saldo buku vs saldo asli Biteship → selisih ke Beban Layanan Biteship.
 */
class ShippingAccountingService
{
    private const TITIPAN_CODE = '1203';

    public function __construct(protected \App\Core\Period\PeriodService $periodService) {}

    /** Jurnal saat booking resi sukses: Dr Titipan (aktual) / Cr Saldo Biteship. */
    public function postBooking(SalesDelivery $delivery): void
    {
        if ($delivery->shipping_provider !== 'biteship') return;

        $cost = (float) $delivery->shipping_cost; // ongkir aktual (price Biteship)
        if ($cost <= 0) return;

        // Idempotent
        if (Journal::where('reference_type', 'shipping_booking')->where('reference_id', $delivery->id)
            ->where('status', '!=', 'void')->exists()) {
            return;
        }

        $saldoId   = $this->saldoAccountId();
        $titipanId = $this->accId(self::TITIPAN_CODE);
        $date      = Carbon::parse($delivery->delivery_date ?: now());

        DB::transaction(function () use ($delivery, $cost, $saldoId, $titipanId, $date) {
            $journal = $this->makeJournal('shipping_booking', $delivery->id, $delivery->delivery_number,
                'Booking ongkir ' . $delivery->delivery_number, $date);

            $this->line($journal, $titipanId, $cost, 0, 'Titipan ongkir ' . $delivery->delivery_number, 'shipping_booking', $delivery->id, $delivery->delivery_number);
            $this->line($journal, $saldoId, 0, $cost, 'Potong Saldo Biteship ' . $delivery->delivery_number, 'shipping_booking', $delivery->id, $delivery->delivery_number);
        });

        // Coba settle kalau invoice sudah ada.
        if ($delivery->sales_order_id && $delivery->order) {
            $this->settleOrder($delivery->order);
        }
    }

    /**
     * Settle selisih ongkir untuk SO (idempotent via sales_orders.shipping_settled).
     * Hanya jalan kalau ada booking Biteship + invoice posted ber-ongkir.
     */
    public function settleOrder(SalesOrder $so): void
    {
        $booked = SalesDelivery::where('sales_order_id', $so->id)
            ->where('shipping_provider', 'biteship')
            ->where('shipping_status', 'booked')
            ->where('status', '!=', 'void')
            ->get();
        if ($booked->isEmpty()) return; // non-Biteship → alur manual (Bayar Ongkir)

        $N = (float) SalesInvoice::where('sales_order_id', $so->id)
            ->where('status', 'posted')->sum('shipping_cost'); // ongkir ditagih customer (net)
        if ($N <= 0) return; // tunggu invoice posted

        $C = (float) $booked->sum('shipping_cost'); // ongkir aktual
        $target = round($N - $C, 2);
        $delta  = round($target - (float) $so->shipping_settled, 2);
        if (abs($delta) < 0.005) return;

        $titipanId = $this->accId(self::TITIPAN_CODE);
        $setting   = FreightSetting::singleton();
        $date      = Carbon::now();

        DB::transaction(function () use ($so, $delta, $titipanId, $setting, $date, $target) {
            $journal = $this->makeJournal('shipping_settlement', $so->id, $so->order_number,
                'Selisih ongkir ' . $so->order_number, $date);

            if ($delta > 0) {
                // Customer bayar lebih dari ongkir aktual → Pendapatan Ongkir.
                if (!$setting->gain_account_id) throw new DomainException("Akun 'Selisih Lebih (Gain)' belum diset di Settings → Pengaturan Ongkir.");
                $this->line($journal, $titipanId, $delta, 0, 'Lepas titipan ongkir ' . $so->order_number, 'shipping_settlement', $so->id, $so->order_number);
                $this->line($journal, $setting->gain_account_id, 0, $delta, 'Pendapatan ongkir ' . $so->order_number, 'shipping_settlement', $so->id, $so->order_number);
            } else {
                // Ongkir aktual lebih mahal (diskon/free ongkir) → Diskon/Beban Ongkir.
                $loss = abs($delta);
                if (!$setting->loss_account_id) throw new DomainException("Akun 'Selisih Kurang (Loss)' belum diset di Settings → Pengaturan Ongkir.");
                $this->line($journal, $setting->loss_account_id, $loss, 0, 'Diskon/beban ongkir ' . $so->order_number, 'shipping_settlement', $so->id, $so->order_number);
                $this->line($journal, $titipanId, 0, $loss, 'Lepas titipan ongkir ' . $so->order_number, 'shipping_settlement', $so->id, $so->order_number);
            }

            $so->shipping_settled = $target;
            $so->save();
        });
    }

    /** Balik jurnal booking saat SJ di-void; lalu re-settle (C berkurang). */
    public function reverseBooking(SalesDelivery $delivery): void
    {
        $orig = Journal::where('reference_type', 'shipping_booking')
            ->where('reference_id', $delivery->id)->where('status', '!=', 'void')->first();
        if (!$orig) return;

        $cost      = (float) $delivery->shipping_cost;
        $saldoId   = $this->saldoAccountId();
        $titipanId = $this->accId(self::TITIPAN_CODE);

        DB::transaction(function () use ($delivery, $cost, $saldoId, $titipanId, $orig) {
            $journal = $this->makeJournal('shipping_booking_void', $delivery->id, $delivery->delivery_number,
                'Void booking ongkir ' . $delivery->delivery_number, Carbon::now());
            // Balik: kembalikan Saldo Biteship, hapus titipan.
            $this->line($journal, $saldoId, $cost, 0, 'Refund Saldo Biteship ' . $delivery->delivery_number, 'shipping_booking_void', $delivery->id, $delivery->delivery_number);
            $this->line($journal, $titipanId, 0, $cost, 'Balik titipan ongkir ' . $delivery->delivery_number, 'shipping_booking_void', $delivery->id, $delivery->delivery_number);

            $orig->update(['status' => 'void']);
        });
    }

    /**
     * Rekonsiliasi saldo: $actual = saldo asli di dashboard Biteship.
     * Selisih (buku − asal) di-jurnal ke Beban Layanan Biteship (biaya cek ongkir/resi dll).
     * @return array{posted:bool, diff:float, book:float}
     */
    public function reconcileSaldo(float $actual, ?Carbon $date = null): array
    {
        $saldoId = $this->saldoAccountId();
        $feeId   = FreightSetting::singleton()->biteship_fee_account_id;
        if (!$feeId) throw new DomainException("Akun 'Beban Layanan Biteship' belum diset di Settings → Pengaturan Ongkir.");

        $book = (float) JournalLine::where('account_id', $saldoId)
            ->whereHas('journal', fn ($q) => $q->where('status', '!=', 'void'))
            ->sum(DB::raw('debit - credit'));

        $diff = round($book - $actual, 2); // buku > asal → ada biaya yg belum tercatat
        if (abs($diff) < 0.005) {
            return ['posted' => false, 'diff' => 0, 'book' => $book];
        }

        $date = $date ?: Carbon::now();
        DB::transaction(function () use ($diff, $saldoId, $feeId, $date) {
            $journal = $this->makeJournal('shipping_recon', 0, null, 'Penyesuaian Saldo Biteship (biaya layanan)', $date);
            if ($diff > 0) {
                // Buku lebih tinggi → biaya keluar: Dr Beban / Cr Saldo.
                $this->line($journal, $feeId, $diff, 0, 'Biaya layanan Biteship', 'shipping_recon', 0, null);
                $this->line($journal, $saldoId, 0, $diff, 'Penyesuaian Saldo Biteship', 'shipping_recon', 0, null);
            } else {
                // Asal lebih tinggi (mis. bonus/koreksi): Dr Saldo / Cr Beban (pulihkan beban).
                $amt = abs($diff);
                $this->line($journal, $saldoId, $amt, 0, 'Penyesuaian Saldo Biteship', 'shipping_recon', 0, null);
                $this->line($journal, $feeId, 0, $amt, 'Koreksi biaya layanan Biteship', 'shipping_recon', 0, null);
            }
        });

        return ['posted' => true, 'diff' => $diff, 'book' => $book];
    }

    // ---------- helpers ----------

    private function saldoAccountId(): int
    {
        $id = FreightSetting::singleton()->biteship_saldo_account_id;
        if (!$id) throw new DomainException("Akun 'Saldo Biteship' belum diset di Settings → Pengaturan Ongkir.");
        return (int) $id;
    }

    private function accId(string $code): int
    {
        $id = Account::where('code', $code)->value('id');
        if (!$id) throw new DomainException("Akun $code tidak ditemukan di COA.");
        return (int) $id;
    }

    private function makeJournal(string $refType, int $refId, ?string $refNumber, string $desc, Carbon $date): Journal
    {
        $this->periodService->ensureOpen($date);
        $period = AccountingPeriod::where('year', $date->year)->where('month', $date->month)->first();
        if (!$period) throw new DomainException('Periode akuntansi tidak ditemukan untuk ' . $date->format('Y-m'));

        return Journal::create([
            'journal_number'   => $this->journalNumber($date),
            'date'             => $date->toDateString(),
            'period_id'        => $period->id,
            'reference_type'   => $refType,
            'reference_id'     => $refId,
            'reference_number' => $refNumber,
            'description'      => $desc,
            'status'           => 'posted',
            'posted_at'        => now(),
        ]);
    }

    private function line(Journal $j, int $accountId, float $debit, float $credit, string $desc, string $refType, int $refId, ?string $refNumber): void
    {
        JournalLine::create([
            'journal_id'       => $j->id,
            'account_id'       => $accountId,
            'debit'            => $debit,
            'credit'           => $credit,
            'description'      => $desc,
            'reference_type'   => $refType,
            'reference_id'     => $refId,
            'reference_number' => $refNumber,
        ]);
    }

    private function journalNumber(Carbon $date): string
    {
        $prefix = 'JV-SHP/' . $date->format('Y/m') . '/';
        for ($i = 0; $i < 8; $i++) {
            $count = Journal::where('journal_number', 'like', $prefix . '%')->count() + 1 + $i;
            $candidate = $prefix . str_pad((string) $count, 4, '0', STR_PAD_LEFT);
            if (!Journal::where('journal_number', $candidate)->exists()) {
                return $candidate;
            }
        }
        return $prefix . substr(md5(uniqid()), 0, 6);
    }
}
