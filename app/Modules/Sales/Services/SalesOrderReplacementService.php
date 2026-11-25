<?php

namespace App\Modules\Sales\Services;

use App\Core\Journal\JournalPostingService;
use App\DTO\JournalEntryDTO;
use App\DTO\JournalLineDTO;
use App\Models\CustomerOverpayment;
use App\Models\CustomerPaymentAllocation;
use App\Modules\Production\Services\PreorderAutoProductionService;
use App\Modules\Sales\Models\SalesAdvance;
use App\Modules\Sales\Models\SalesOrder;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ganti Pesanan: pelanggan sudah bayar DP lalu minta ganti (mis. ukuran). SO baru dibuat,
 * DP SO lama DIPINDAH ke SO baru, lalu SO lama di-void.
 *
 * Pembayaran aslinya TIDAK di-void — tanggal & baris kasnya tetap, jadi rekonsiliasi
 * bank/Midtrans tetap cocok. Yang dipindah hanya alokasinya (customer_payment_allocations,
 * sales_advances, paid_amount); jurnal DP-nya tetap Cr 2105 Uang Muka Customer.
 *
 * DP melebihi total SO baru → kelebihannya jadi Saldo Pelanggan: Dr 2105 / Cr 2106 +
 * baris customer_overpayments. Jurnal & baris saldo itu memakai referensi pembayaran
 * asal, sehingga bila pembayaran itu kelak di-void, PaymentController::void() ikut
 * membalik keduanya.
 */
class SalesOrderReplacementService
{
    public function __construct(
        protected SalesOrderService $orders,
        protected JournalPostingService $journals,
    ) {}

    /** Alasan SO tidak bisa diganti, atau null bila boleh. */
    public function blocker(SalesOrder $so): ?string
    {
        if ($so->customer?->is_marketplace) {
            return 'Pesanan marketplace tidak bisa diganti dari ERP.';
        }
        if ($this->activeAllocations($so)->isEmpty()) {
            return 'Ganti Pesanan hanya untuk SO yang sudah ada DP-nya. SO tanpa DP cukup di-void lalu buat baru.';
        }
        return $this->orders->voidBlocker($so, ignorePayments: true);
    }

    /**
     * Pindahkan DP $old → $new (draft yang baru dibuat), post $new, lalu void $old.
     *
     * @return array{moved: float, to_balance: float, unfinished_production: string[]}
     */
    public function replace(SalesOrder $old, SalesOrder $new): array
    {
        return DB::transaction(function () use ($old, $new) {
            $old = SalesOrder::with('customer')->lockForUpdate()->findOrFail($old->id);
            $new = SalesOrder::lockForUpdate()->findOrFail($new->id);

            if ($reason = $this->blocker($old)) {
                throw new DomainException($reason);
            }
            if ((int) $new->customer_id !== (int) $old->customer_id) {
                throw new DomainException('Pelanggan SO pengganti harus sama dengan SO lama (DP milik pelanggan tersebut).');
            }

            // Penawaran sumber ikut pindah, supaya voidConfirmed() SO lama tidak
            // mengembalikannya ke draft (masih dirujuk SO baru).
            if ($old->quotation_id && !$new->quotation_id) {
                $new->quotation_id = $old->quotation_id;
                \App\Models\SalesQuotation::where('id', $old->quotation_id)->update(['sales_order_id' => $new->id]);
            }
            $new->notes = trim(($new->notes ?? '') . "\n[Pengganti {$old->order_number}]");
            $new->save();

            // Post SO baru lebih dulu: reservasi stok + nomor pickup. SO lama di-void
            // belakangan, jadi stok yg dipesan keduanya sempat dobel sesaat dalam transaksi.
            $this->orders->confirm($new->id);
            $new->refresh();

            $capacity  = max(0, round((float) $new->grand_total - (float) $new->paid_amount, 2));
            $moved     = 0.0;
            $toBalance = 0.0;

            foreach ($this->activeAllocations($old) as $alloc) {
                $payment = $alloc->payment;
                $amount  = round((float) $alloc->amount, 2);
                $move    = min($amount, $capacity);
                $excess  = round($amount - $move, 2);
                $advance = SalesAdvance::where('advance_number', 'ADV-' . $payment->payment_number)
                    ->where('sales_order_id', $old->id)->first();

                if ($move > 0) {
                    $alloc->update(['sales_order_id' => $new->id, 'amount' => $move]);
                    $advance?->update(['sales_order_id' => $new->id, 'amount' => $move]);
                } else {
                    $alloc->delete();
                    $advance?->delete();
                }

                if ($excess > 0) {
                    $this->moveToBalance($payment, $excess, $old, $new);
                }

                $capacity  = round($capacity - $move, 2);
                $moved     += $move;
                $toBalance += $excess;
            }

            $old->paid_amount = max(0, round((float) $old->paid_amount - $moved - $toBalance, 2));
            $old->notes = trim(($old->notes ?? '') . "\n[Diganti {$new->order_number}]");
            $old->save();

            $new->paid_amount = round((float) $new->paid_amount + $moved, 2);
            $new->save();

            $this->orders->voidConfirmed($old);

            // OP yang sudah terlanjur dikerjakan (tak ikut dibatalkan voidConfirmed) ikut
            // pindah ke SO baru — pelanggannya sama. Dulu tertinggal di SO void: hasil
            // finalisasinya terikat SO lama sehingga faktur SO baru macet (produk custom
            // hanya boleh memakai lapisan OP milik SO-nya — SO/2026/11/00031, OP 00117), dan
            // pemicu preorder di bawah tak melihatnya sehingga bisa membuat OP dobel.
            // Harus SEBELUM pemicu preorder: idempotensinya menghitung qty OP milik SO baru.
            [$movedOps, $unfinished] = $this->pindahkanProduksi($old, $new);

            // SalesAdvanceObserver hanya bereaksi pada advance BARU; advance yang dipindah
            // tidak memicunya, jadi OP preorder SO baru dibuat manual di sini.
            if ($moved > 0) {
                try {
                    $hasil = app(PreorderAutoProductionService::class)
                        ->runForSalesOrder($new->fresh(['items.product', 'customer']));
                    app(\App\Modules\KaryawanVirtual\Services\KaryawanVirtualService::class)->catat(
                        'produksi.op-preorder',
                        array_map(fn ($r) => $r + ['sales_order_number' => $new->order_number], $hasil),
                        \App\Modules\KaryawanVirtual\Services\KaryawanVirtualService::PEMICU_PERISTIWA,
                        "Ganti Pesanan {$old->order_number} → {$new->order_number}",
                        auth()->id(),
                    );
                } catch (\Throwable $e) {
                    Log::error('PreorderAutoProduction (Ganti Pesanan) gagal', [
                        'sales_order_id' => $new->id, 'error' => $e->getMessage(),
                    ]);
                }
            }

            return [
                'moved'                 => $moved,
                'to_balance'            => $toBalance,
                'moved_production'      => $movedOps,
                'unfinished_production' => $unfinished,
            ];
        });
    }

    /**
     * Pindahkan OP SO lama (sudah void) yang masih hidup ke SO baru lewat Ganti SO, bila
     * hasilnya ada di SO baru. Yang tak bisa dipindah (produk tak dipesan lagi, terlibat
     * penggabungan) dikembalikan sebagai daftar untuk dibereskan manual.
     *
     * @return array{0: string[], 1: string[]} [nomor OP dipindah, nomor OP tertinggal]
     */
    protected function pindahkanProduksi(SalesOrder $old, SalesOrder $new): array
    {
        $produkBaru = $new->items()->pluck('product_id')->map(fn ($id) => (int) $id)->all();
        $ops = \App\Modules\Production\Models\ProductionOrder::with('outputs')
            ->where('sales_order_id', $old->id)
            ->whereNotIn('status', ['cancelled', 'merged'])
            ->orderBy('id')
            ->get();

        $dipindah = [];
        $tertinggal = [];
        foreach ($ops as $op) {
            $cocok = $op->outputs->contains(fn ($o) => in_array((int) $o->product_id, $produkBaru, true));
            if (!$cocok) {
                $tertinggal[] = $op->order_number;
                continue;
            }
            try {
                app(\App\Modules\Production\Services\ProductionOrderService::class)->gantiSalesOrder(
                    $op->id,
                    $new->id,
                    "Ganti Pesanan {$old->order_number} → {$new->order_number}",
                    auth()->id(),
                );
                $dipindah[] = $op->order_number;
            } catch (\Throwable $e) {
                Log::warning('Ganti Pesanan: OP tidak bisa dipindah ke SO baru', [
                    'production_order_id' => $op->id,
                    'from'                => $old->id,
                    'to'                  => $new->id,
                    'message'             => $e->getMessage(),
                ]);
                $tertinggal[] = $op->order_number;
            }
        }

        return [$dipindah, $tertinggal];
    }

    /** Alokasi DP pembayaran posted yang menempel di SO ini. */
    protected function activeAllocations(SalesOrder $so)
    {
        return CustomerPaymentAllocation::with('payment')
            ->where('sales_order_id', $so->id)
            ->whereHas('payment', fn($q) => $q->where('status', 'posted'))
            ->orderBy('id')
            ->get();
    }

    /** Kelebihan DP → Saldo Pelanggan (Dr 2105 Uang Muka / Cr 2106 Kelebihan Bayar). */
    protected function moveToBalance($payment, float $amount, SalesOrder $old, SalesOrder $new): void
    {
        $advanceAccountId = DB::table('accounts')->where('code', '2105')->value('id');
        $overpayAccountId = DB::table('accounts')->where('code', '2106')->value('id');
        if (!$advanceAccountId || !$overpayAccountId) {
            throw new DomainException('Akun 2105 Uang Muka Customer / 2106 Kelebihan Bayar Customer belum ada.');
        }

        $desc = "Kelebihan DP {$old->order_number} → Saldo Pelanggan (diganti {$new->order_number})";
        $this->journals->post(new JournalEntryDTO(
            date: now()->toDateString(),
            reference_type: 'customer_payment',
            reference_id: $payment->id,
            description: $desc,
            lines: [
                new JournalLineDTO($advanceAccountId, $amount, 0, $desc, $payment->customer_id),
                new JournalLineDTO($overpayAccountId, 0, $amount, $desc, $payment->customer_id),
            ],
            reference_number: $payment->payment_number,
            allow_repeat: true,
        ));

        CustomerOverpayment::create([
            'customer_id' => $payment->customer_id,
            'amount'      => $amount,
            'reference'   => $payment->payment_number,
            'note'        => $desc,
        ]);

        $payment->overpay = round((float) $payment->overpay + $amount, 2);
        $payment->save();
    }
}
