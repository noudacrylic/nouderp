<?php

namespace App\Modules\Sales\Services;

use App\Core\Journal\Journal;
use App\Models\CustomerPaymentAllocation;
use App\Models\MarketplaceConfig;
use App\Models\SalesInvoice;
use App\Modules\Finance\Models\MarketplaceSettlementLine;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Support\Collection;

/**
 * Jejak dana sebuah faktur — untuk halaman detail faktur, BUKAN untuk cetak.
 *
 * Faktur sendiri hanya bercerita "berapa nilai jualnya". Untuk pesanan marketplace, cerita
 * uangnya berlanjut di dokumen lain: uang muka pembeli yang ditahan marketplace, pencairan
 * (dipotong admin & pajak) ke Saldo Penjualan, retur, lalu rekonsiliasi terhadap laporan
 * marketplace. Service ini merangkai semuanya dari jurnal yang benar-benar terposting, supaya
 * yang tampil selalu sama dengan buku besar — bukan hitungan ulang yang bisa berbeda.
 */
class InvoiceTrailService
{
    public function untuk(SalesInvoice $inv): array
    {
        $config = MarketplaceConfig::where('customer_id', $inv->customer_id)->first();
        $soId   = $inv->sales_order_id;

        $retur = SalesReturn::with('items.product:id,name,sku')
            ->where(fn ($w) => $w->where('invoice_id', $inv->id)
                ->when($soId, fn ($q) => $q->orWhere('sales_order_id', $soId)))
            ->orderBy('id')
            ->get();

        // ── Jurnal yang menyangkut faktur ini ──
        $bayarIds = CustomerPaymentAllocation::query()
            ->where(fn ($w) => $w->where('invoice_id', $inv->id)
                ->when($soId, fn ($q) => $q->orWhere('sales_order_id', $soId)))
            ->pluck('customer_payment_id')->unique()->all();

        $jurnal = Journal::with('lines.account:id,code,name,type')
            ->where(fn ($w) => $w
                ->where(fn ($q) => $q->where('reference_type', 'customer_payment')->whereIn('reference_id', $bayarIds ?: [0]))
                ->orWhere(fn ($q) => $q->whereIn('reference_type', ['sales_invoice', 'sales_invoice_settlement'])->where('reference_id', $inv->id))
                ->orWhere(fn ($q) => $q->where('reference_type', 'sales_return')->whereIn('reference_id', $retur->pluck('id')->all() ?: [0])))
            ->orderBy('date')->orderBy('id')
            ->get();

        $returById = $retur->keyBy('id');
        $tahap = $jurnal->map(fn (Journal $j) => [
            'jenis'   => $j->reference_type,
            'judul'   => match ($j->reference_type) {
                'customer_payment'         => 'Pembayaran pembeli (uang muka)',
                'sales_invoice'            => 'Faktur terbit',
                'sales_invoice_settlement' => $this->dariRetur($j, $retur) ? 'Penyelesaian pesanan (lewat retur)' : 'Pencairan dana marketplace',
                'sales_return'             => 'Retur ' . ($returById->get($j->reference_id)?->return_number ?? ''),
                default                    => $j->reference_type,
            },
            'nomor'   => $j->journal_number ?: ('#' . $j->id),
            'tanggal' => $j->date,
            'void'    => $j->status === 'void',
            'baris'   => $j->lines->map(fn ($l) => [
                'kode'  => $l->account->code ?? '?',
                'akun'  => $l->account->name ?? '?',
                'debit' => (float) $l->debit,
                'kredit'=> (float) $l->credit,
                'ket'   => $l->description,
            ])->values()->all(),
        ])->values();

        return [
            'marketplace' => $config ? $this->ringkasMarketplace($inv, $config, $jurnal, $retur) : null,
            'retur'       => $this->ringkasRetur($retur, $jurnal),
            'tahap'       => $tahap,
        ];
    }

    /** Jurnal pencairan ini dipicu retur (blok Penyelesaian / cara lama)? */
    private function dariRetur(Journal $j, Collection $retur): bool
    {
        return $retur->contains(fn (SalesReturn $r) => (int) $r->settlement_journal_id === (int) $j->id);
    }

    private function ringkasMarketplace(SalesInvoice $inv, MarketplaceConfig $config, Collection $jurnal, Collection $retur): array
    {
        $cair = $jurnal->where('reference_type', 'sales_invoice_settlement')->where('status', '!=', 'void');
        $mutasi = function (?int $akun, string $sisi = 'debit') use ($cair): float {
            if (!$akun) {
                return 0.0;
            }

            return round($cair->flatMap->lines->where('account_id', $akun)
                ->sum(fn ($l) => $sisi === 'debit' ? $l->debit - $l->credit : $l->credit - $l->debit), 2);
        };

        $dompet = $mutasi($config->account_wallet_id ? (int) $config->account_wallet_id : null);
        $admin  = $mutasi($config->account_fee_id ? (int) $config->account_fee_id : null);
        $pajak  = $mutasi($config->account_tax_id ? (int) $config->account_tax_id : null);

        $dibalik = round($retur->where('status', 'posted')->sum(fn (SalesReturn $r) => $r->reversedAmount()), 2);

        // Faktur gaya LAMA: biaya admin sudah dipotong di jurnal fakturnya sendiri.
        if (!$inv->fee_at_settlement) {
            $admin = round((float) $inv->marketplace_fee, 2);
        }

        $rekon = MarketplaceSettlementLine::with('settlement:id,number,status')
            ->where('sales_invoice_id', $inv->id)->get();
        $cocok = $rekon->where('is_matched', true);

        return [
            'nama'        => $inv->customer?->name,
            'gaya_lama'   => !$inv->fee_at_settlement,
            'kotor'       => round((float) $inv->grand_total + ($inv->fee_at_settlement ? 0 : (float) $inv->marketplace_fee), 2),
            'dibalik'     => $dibalik,
            'admin'       => $admin,
            'pajak'       => $pajak,
            'dompet'      => $dompet,
            'akun_dompet' => $config->walletAccount?->code . ' ' . $config->walletAccount?->name,
            'akun_tahan'  => $config->holdAccount?->code . ' ' . $config->holdAccount?->name,
            'cair'        => (bool) $inv->marketplace_processed,
            'tgl_cair'    => $cair->min('date'),
            'rekon'       => $cocok->isEmpty() ? null : [
                'nomor'    => $cocok->map(fn ($l) => $l->settlement?->number)->filter()->unique()->implode(', '),
                'posted'   => $cocok->every(fn ($l) => $l->settlement?->status === 'posted'),
                'net'      => round((float) $cocok->sum('net_amount'), 2),
                'tercatat' => round((float) $cocok->sum('fee_prebooked'), 2),
                'aktual'   => round((float) $cocok->sum('fee_actual'), 2),
                'selisih'  => round((float) $cocok->sum('fee_diff'), 2),
            ],
            'menunggu_rekon' => $rekon->where('is_matched', false)->isNotEmpty(),
        ];
    }

    private function ringkasRetur(Collection $retur, Collection $jurnal): array
    {
        return $retur->map(function (SalesReturn $r) use ($jurnal) {
            $baris = fn (?Journal $j) => $j ? $j->lines->map(fn ($l) => [
                'kode' => $l->account->code ?? '?', 'akun' => $l->account->name ?? '?',
                'debit' => (float) $l->debit, 'kredit' => (float) $l->credit, 'ket' => $l->description,
            ])->values()->all() : [];

            $jRetur = $jurnal->first(fn ($j) => $j->reference_type === 'sales_return' && (int) $j->reference_id === $r->id);
            $jCair  = $r->settlement_journal_id ? $jurnal->firstWhere('id', $r->settlement_journal_id) : null;

            return [
                'id'      => $r->id,
                'nomor'   => $r->return_number,
                'tanggal' => $r->return_date,
                'status'  => $r->status,
                'jenis'   => $r->returnTypeLabel(),
                'kasus'   => $r->caseLabel(),
                'nilai'   => (float) $r->grand_total,
                'dibalik' => $r->status === 'posted' ? $r->reversedAmount() : null,
                'items'   => $r->items->map(fn ($i) => [
                    'nama'    => $i->product->name ?? ('Produk #' . $i->product_id),
                    'qty'     => (float) $i->qty,
                    'kondisi' => SalesReturn::CONDITIONS[$i->condition] ?? (in_array($i->condition, SalesReturn::CONDITIONS_LAMA, true) ? 'Tidak Kembali' : $i->condition),
                    'nilai'   => (float) $i->subtotal,
                ])->values()->all(),
                'jurnal_retur' => $baris($jRetur),
                'jurnal_cair'  => $baris($jCair),
                'void'         => $jRetur?->status === 'void',
            ];
        })->values()->all();
    }
}
