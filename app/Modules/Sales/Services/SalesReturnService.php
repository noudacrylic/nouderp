<?php

namespace App\Modules\Sales\Services;

use App\DTO\SalesReturnDTO;
use App\Models\SalesInvoice;
use App\Enums\AccountCodeEnum;
use App\Core\Accounting\Account;
use App\DTO\JournalEntryDTO;
use App\DTO\JournalLineDTO;
use App\Core\Journal\JournalPostingService;
use App\Core\Inventory\FifoService;
use App\Core\Inventory\BundleComponent;
use App\Core\Inventory\ProductBundle;
use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnItem;
use App\Models\CustomerOverpayment;
use App\Services\NumberGeneratorService;
use Illuminate\Support\Facades\DB;
use Exception;

class SalesReturnService
{
    /**
     * Save return as draft (no accounting impact)
     *
     * $stage default `diproses` = barang sudah tiba & tinggal dicek. Tapi tahap `baru` MENANG
     * atas argumen ini bila jenis retur belum diisi: "Retur Baru" menurut definisinya adalah
     * retur yang belum ketahuan kasusnya apa (paket hilang / gagal kirim / dst). Selama
     * `return_type` kosong, retur menunggu di tab "Baru" untuk didefinisikan admin — termasuk
     * retur yang ditarik otomatis dari Jubelio, yang datang tanpa keterangan jenis.
     */
    public function saveDraft(SalesReturnDTO $dto, string $stage = 'diproses'): SalesReturn
    {
        return DB::transaction(function () use ($dto, $stage) {
            $doc = $this->getDoc($dto);
            $totals = $this->calculateTotals($dto, $doc);

            $stage = array_key_exists($stage, SalesReturn::STAGES) ? $stage : 'diproses';
            if (empty($dto->return_type)) {
                $stage = 'baru';
            }

            $return = SalesReturn::create([
                'return_number'  => NumberGeneratorService::generate('SR'),
                'customer_id'    => $dto->customer_id,
                'invoice_id'     => $dto->invoice_id,
                'sales_order_id' => $dto->sales_order_id,
                'return_date'    => $dto->date,
                'grand_total'    => $totals['net'],
                'status'         => 'draft',
                'stage'          => $stage,
                'return_type'            => $dto->return_type,
                'external_return_number' => $dto->external_return_number,
                'notes'                  => $dto->notes,
            ]);

            foreach ($dto->items as $item) {
                $docItem = $doc->items->firstWhere('id', $item['invoice_item_id']);
                $docItemSubtotal = $docItem->subtotal ?? $docItem->line_total ?? 0;

                SalesReturnItem::create([
                    'sales_return_id'   => $return->id,
                    'reference_item_id' => $item['invoice_item_id'],
                    'product_id'        => $docItem->product_id,
                    'qty'               => $item['qty'],
                    'unit_price'        => $docItem->unit_price,
                    'subtotal'          => round(($docItemSubtotal / $docItem->qty) * $item['qty'], 2),
                    'condition'         => $item['condition'],
                    'component_conditions' => $this->normalizeComponentConditions($item['component_conditions'] ?? null),
                ]);
            }

            return $return;
        });
    }

    /**
     * Update an existing draft
     */
    public function updateDraft(int $id, SalesReturnDTO $dto): SalesReturn
    {
        return DB::transaction(function () use ($id, $dto) {
            $return = SalesReturn::findOrFail($id);
            if ($return->status !== 'draft') {
                throw new Exception('Only draft returns can be updated.');
            }

            $doc = $this->getDoc($dto);
            $totals = $this->calculateTotals($dto, $doc);

            // Mengisi jenis retur = mendefinisikan kasusnya → retur naik dari "baru" ke
            // "diproses". Tahap yang sudah lebih jauh tidak ditarik mundur.
            $return->update([
                'return_date' => $dto->date,
                'grand_total' => $totals['net'],
                'return_type'            => $dto->return_type,
                'external_return_number' => $dto->external_return_number,
                'notes'                  => $dto->notes,
                'stage' => (!empty($dto->return_type) && $return->stage === 'baru') ? 'diproses' : $return->stage,
            ]);

            $return->items()->delete();

            foreach ($dto->items as $item) {
                $docItem = $doc->items->firstWhere('id', $item['invoice_item_id']);
                $docItemSubtotal = $docItem->subtotal ?? $docItem->line_total ?? 0;

                SalesReturnItem::create([
                    'sales_return_id'   => $return->id,
                    'reference_item_id' => $item['invoice_item_id'],
                    'product_id'        => $docItem->product_id,
                    'qty'               => $item['qty'],
                    'unit_price'        => $docItem->unit_price,
                    'subtotal'          => round(($docItemSubtotal / $docItem->qty) * $item['qty'], 2),
                    'condition'         => $item['condition'],
                    'component_conditions' => $this->normalizeComponentConditions($item['component_conditions'] ?? null),
                ]);
            }

            return $return;
        });
    }

    /**
     * Post the return (creates journal entries and updates inventory)
     */
    public function post(SalesReturnDTO $dto, ?int $existingReturnId = null): SalesReturn
    {
        return DB::transaction(function () use ($dto, $existingReturnId) {
            $doc = $this->getDoc($dto);
            $this->validatePosting($dto, $doc, $existingReturnId);
            $totals = $this->calculateTotals($dto, $doc);

            if ($existingReturnId) {
                $return = SalesReturn::findOrFail($existingReturnId);
                $return->update([
                    'return_date' => $dto->date,
                    'grand_total' => $totals['net'],
                    'status'      => 'posted',
                    'stage'       => 'selesai',
                    'return_type'            => $dto->return_type ?? $return->return_type,
                    'external_return_number' => $dto->external_return_number ?? $return->external_return_number,
                    'notes'                  => $dto->notes ?? $return->notes,
                ]);
                $return->items()->delete();
                foreach ($dto->items as $item) {
                    $this->storeReturnItem($return->id, $doc, $item);
                }
            } else {
                $return = SalesReturn::create([
                    'return_number'  => NumberGeneratorService::generate('SR'),
                    'customer_id'    => $dto->customer_id,
                    'invoice_id'     => $dto->invoice_id,
                    'sales_order_id' => $dto->sales_order_id,
                    'return_date'    => $dto->date,
                    'grand_total'    => $totals['net'],
                    'status'         => 'posted',
                    'stage'          => 'selesai',
                    'return_type'            => $dto->return_type,
                    'external_return_number' => $dto->external_return_number,
                        'notes'                  => $dto->notes,
                ]);

                foreach ($dto->items as $item) {
                    $this->storeReturnItem($return->id, $doc, $item);
                }
            }

            // Penjualan hanya dibalik sebesar baris yang BUKAN `tidak_kembali`. Baris
            // `tidak_kembali` dananya diganti marketplace, jadi omzet & HPP-nya tetap sah dan
            // barangnya memang tidak pernah kembali — tidak ada yang perlu dibalik untuk baris
            // itu. Kalau semua barisnya `tidak_kembali`, jurnalnya kosong sama sekali dan
            // dokumen retur murni jadi catatan kasus.
            $isSO = (bool) $dto->sales_order_id;

            $journalLines = [];
            if ($totals['reversed'] > 0) {
                $journalLines = array_merge($journalLines, $this->getRevenueReversalLines($dto, $doc, $totals['reversed'], $isSO));
            }
            $journalLines = array_merge($journalLines, $this->getCogsReversalLines($dto, $doc, $return->id));

            if (!empty($journalLines)) {
                $docNumber = $doc->invoice_number ?? $doc->order_number;
                $description = "Sales Return Reversal (Ref: {$docNumber})";

                app(JournalPostingService::class)->post(new JournalEntryDTO(
                    date: $dto->date,
                    reference_type: 'sales_return',
                    reference_id: $return->id,
                    description: $description,
                    lines: $journalLines,
                    reference_number: $return->return_number
                ));
            }

            // Kolam saldo kredit hanya bertambah bila uangnya memang DIJADIKAN kredit —
            // bukan setiap kali pelanggan biasa meretur. Retur yang uangnya ditransfer balik
            // atau dipotong dari dompet marketplace tidak menciptakan hak beli apa pun, dan
            // menambahkannya ke kolam berarti pelanggan dibayar dua kali.
            if (!$isSO && $totals['reversed'] > 0) {
                $uang = $this->hitungUang($doc, $totals['reversed'], $dto->refund_target, $dto->refund_amount);

                $return->forceFill([
                    'refund_target'      => $uang['target'],
                    'refund_account_id'  => $dto->refund_account_id,
                    'refund_customer_id' => $dto->refund_customer_id,
                    'refund_amount'      => $uang['refund'],
                    'fee_reversed'       => $uang['fee'],
                ])->save();

                if ($uang['target'] === 'credit' && $uang['refund'] > 0) {
                    CustomerOverpayment::create([
                        'customer_id' => $dto->refund_customer_id ?: $dto->customer_id,
                        'amount'      => $uang['refund'],
                        'reference'   => $return->return_number,
                        'note'        => 'Retur ' . $return->return_number,
                    ]);
                }
            }

            return $return;
        });
    }

    private function storeReturnItem(int $returnId, $doc, array $item): void
    {
        $docItem = $doc->items->firstWhere('id', $item['invoice_item_id']);
        $docItemSubtotal = $docItem->subtotal ?? $docItem->line_total ?? 0;

        SalesReturnItem::create([
            'sales_return_id'   => $returnId,
            'reference_item_id' => $item['invoice_item_id'],
            'product_id'        => $docItem->product_id,
            'qty'               => $item['qty'],
            'unit_price'        => $docItem->unit_price,
            'subtotal'          => round(($docItemSubtotal / $docItem->qty) * $item['qty'], 2),
            'condition'         => $item['condition'],
            'component_conditions' => $this->normalizeComponentConditions($item['component_conditions'] ?? null),
        ]);
    }

    /**
     * Bersihkan map kondisi per komponen: buang nilai kosong/tak valid, kunci = product_id int.
     * Return null bila tidak ada (item non-bundle) → item pakai `condition` tunggal.
     */
    private function normalizeComponentConditions($raw): ?array
    {
        if (!is_array($raw) || empty($raw)) {
            return null;
        }
        $out = [];
        foreach ($raw as $pid => $cond) {
            if (in_array($cond, ['good', 'repair', 'damaged'], true)) {
                $out[(int) $pid] = $cond;
            }
        }
        return $out ?: null;
    }

    /**
     * Jenis penanganan retur — ditentukan dari KEADAAN DANA, bukan dari channel.
     *
     * Orang menyebutnya "retur marketplace" dan "retur biasa", dan itu benar untuk sebagian
     * besar kasus. Tapi pembeda sesungguhnya bukan channel-nya: faktur marketplace yang
     * pesanannya SUDAH selesai berperilaku persis seperti penjualan toko — dananya sudah cair,
     * jadi pengembaliannya harus keluar dari dompet/bank, bukan dari saldo yang sudah kosong.
     * Karena itu jenisnya disimpulkan sistem, bukan ditanyakan ke CS yang belum tentu tahu
     * pesanan itu sudah tuntas atau belum.
     *
     * @return 'marketplace'|'biasa'
     */
    public function jenisRetur($doc): string
    {
        if (!$doc instanceof SalesInvoice) {
            return 'biasa';
        }

        return ($doc->customer?->is_marketplace && $doc->fee_at_settlement && $doc->remaining_amount >= 1)
            ? 'marketplace'
            : 'biasa';
    }

    /** Tujuan dana bawaan untuk sebuah dokumen — dipakai form & sebagai fallback posting. */
    public function tujuanDanaBawaan($doc): string
    {
        if ($this->jenisRetur($doc) === 'marketplace') {
            return 'hold';
        }

        return $doc->customer?->is_marketplace ? 'wallet' : 'credit';
    }

    /**
     * Pembagian uang sebuah retur.
     *
     * Satu aturan untuk semua kasus, dan urutannya yang membuatnya benar:
     *
     *  1. HAPUS TAGIHAN DULU sebesar piutang faktur yang masih terbuka. Selama faktur belum
     *     dibayar, membatalkan penjualan berarti menghapus tagihan — tidak ada uang bergerak.
     *  2. SISANYA adalah uang yang SUDAH kita terima, dan hanya bagian inilah yang benar-benar
     *     perlu dikembalikan ke suatu tempat.
     *  3. Untuk retur marketplace yang dananya masih ditahan, uang pembeli dilepas balik dari
     *     Saldo Ditahan ke Uang Muka — itu pasangan jurnal tersendiri, di luar dua langkah di
     *     atas, karena yang bergerak adalah titipan pembeli, bukan pendapatan kita.
     *
     * NILAI REFUND adalah hasil negosiasi, bukan turunan harga. Pada retur setelah pesanan
     * selesai, biaya admin marketplace sudah hangus dan tidak dikembalikan platform; yang
     * wajar dikembalikan adalah dana bersih yang kita terima. Selisih antara nilai jual yang
     * dibatalkan dan uang yang benar-benar keluar MEMBALIK beban admin — karena beban itu
     * akhirnya ditanggung pembeli, bukan kita. Pembalikan dibatasi sebesar fee yang memang
     * pernah dibebankan untuk porsi yang diretur; lebih dari itu bukan urusan biaya admin dan
     * ditolak, supaya selisih yang tak terjelaskan tidak diam-diam menumpang di sana.
     *
     * @return array{ar:float, cash:float, refund:float, fee:float, hold:float, target:string}
     */
    public function hitungUang($doc, float $amount, ?string $target = null, ?float $refundDiminta = null): array
    {
        $target = $target ?: $this->tujuanDanaBawaan($doc);
        $isInvoice = $doc instanceof SalesInvoice;

        // 1. Piutang yang masih terbuka pada faktur ini.
        $arOpen = $isInvoice ? max(0, (float) $doc->remaining_amount) : 0.0;
        $ar     = round(min($amount, $arOpen), 2);

        // 2. Sisanya = uang yang sudah kita terima.
        $cash = round($amount - $ar, 2);

        // 3. Titipan pembeli yang masih ditahan marketplace, dilepas balik.
        $hold = $target === 'hold' ? round(min($amount, $this->sisaDitahan($doc)), 2) : 0.0;

        // Fee yang pernah dibebankan untuk porsi yang diretur ini.
        $feeMax = 0.0;
        if ($isInvoice && $cash > 0 && (float) $doc->grand_total > 0) {
            $feeMax = round((float) ($doc->marketplace_fee ?? 0) * ($amount / (float) $doc->grand_total), 2);
        }

        // Bawaan: kembalikan dana BERSIH yang kita terima (fee-nya ditanggung pembeli).
        $refund = $refundDiminta !== null ? round($refundDiminta, 2) : round(max(0, $cash - $feeMax), 2);
        if ($refund < 0) {
            throw new Exception('Nilai pengembalian tidak boleh negatif.');
        }
        if ($refund > $cash + 0.005) {
            throw new Exception(
                'Nilai pengembalian ' . rupiah($refund) . ' melebihi dana yang pernah kita terima atas bagian ini (' . rupiah($cash) . ').'
            );
        }

        $fee = round($cash - $refund, 2);
        if ($fee > $feeMax + 0.005) {
            throw new Exception(
                'Selisih ' . rupiah($fee) . ' lebih besar daripada biaya admin yang pernah dibebankan untuk bagian ini (' . rupiah($feeMax) . '). '
                . 'Naikkan nilai pengembalian, atau catat selisihnya lewat dokumen tersendiri.'
            );
        }

        return compact('ar', 'cash', 'refund', 'fee', 'hold', 'target');
    }

    /** Saldo titipan pembeli yang masih tertahan untuk dokumen ini. */
    private function sisaDitahan($doc): float
    {
        $soId = $doc->sales_order_id ?? ($doc instanceof \App\Modules\Sales\Models\SalesOrder ? $doc->id : null);
        if (!$soId) {
            return 0.0;
        }

        $config = \App\Models\MarketplaceConfig::where('customer_id', $doc->customer_id)->first();
        $holdId = $config?->account_receivable_hold_id;
        if (!$holdId) {
            return 0.0;
        }

        $deposit = (float) \App\Modules\Sales\Models\SalesAdvance::where('sales_order_id', $soId)
            ->where('status', 'posted')
            ->where('bank_account_id', $holdId)
            ->sum('amount');

        // Retur sebelumnya sudah melepas sebagian — jangan melepasnya dua kali.
        $terpakai = (float) DB::table('journal_lines as jl')
            ->join('journals as j', 'j.id', '=', 'jl.journal_id')
            ->where('j.status', '!=', 'void')
            ->where('j.reference_type', 'sales_return')
            ->where('jl.account_id', $holdId)
            ->whereIn('j.reference_id', SalesReturn::where('status', 'posted')
                ->where(fn ($w) => $w->where('invoice_id', $doc->id ?? 0)
                    ->orWhere('sales_order_id', $soId))
                ->pluck('id'))
            ->selectRaw('SUM(jl.credit) - SUM(jl.debit) v')->value('v');

        return round(max(0, $deposit - $terpakai), 2);
    }

    /** Akun tujuan pengembalian dana. */
    private function akunTujuan($doc, string $target, ?int $refundAccountId): int
    {
        $config = \App\Models\MarketplaceConfig::where('customer_id', $doc->customer_id)->first();

        return match ($target) {
            'hold'   => (int) ($config?->account_receivable_hold_id ?: $this->getAccountId(AccountCodeEnum::CUSTOMER_OVERPAY)),
            'wallet' => (int) ($config?->account_wallet_id ?: $this->getAccountId(AccountCodeEnum::CASH)),
            'bank'   => (int) ($refundAccountId ?: throw new Exception('Pilih akun kas/bank untuk pengembalian dana.')),
            default  => (int) $this->getAccountId(AccountCodeEnum::CUSTOMER_OVERPAY),
        };
    }

    /**
     * Baris jurnal sisi UANG sebuah retur. Sisi barang (HPP/persediaan) terpisah di
     * getCogsReversalLines().
     */
    private function getRevenueReversalLines($dto, $doc, $amount, $isSO)
    {
        // Retur atas SO (belum ada faktur) — jalur lama, dipertahankan agar 32 dokumen lama
        // tetap bisa dibuka & di-void. Belum ada omzet yang diakui, jadi yang dibalik adalah
        // Uang Muka, bukan penjualan.
        if ($isSO) {
            $config = $doc->customer->is_marketplace
                ? \App\Models\MarketplaceConfig::where('customer_id', $doc->customer_id)->first()
                : null;
            $creditId = $config?->account_receivable_hold_id ?: $this->getAccountId(AccountCodeEnum::CUSTOMER_OVERPAY);

            return [
                new JournalLineDTO($this->getAccountId(AccountCodeEnum::SALES_ADVANCE), (float) $amount, 0, 'Sales Return Reversal - Advance'),
                new JournalLineDTO((int) $creditId, 0, (float) $amount, 'Pengembalian titipan pembeli', $doc->customer_id),
            ];
        }

        $uang = $this->hitungUang($doc, (float) $amount, $dto->refund_target, $dto->refund_amount);

        // Nilai JUAL yang dibatalkan → kontra-pendapatan 4004, bukan mendebit 4001 langsung.
        // Mendebit 4001 membuat omzet menyusut diam-diam: laporan tak bisa memisahkan
        // "jual berapa" dari "diretur berapa". Laba tidak berubah — 4004 sama-sama revenue.
        $lines = [
            new JournalLineDTO($this->getAccountId(AccountCodeEnum::SALES_RETURN), (float) $amount, 0, 'Retur penjualan - ' . $doc->invoice_number),
        ];

        if ($uang['ar'] > 0) {
            $lines[] = new JournalLineDTO(
                $this->getAccountId(AccountCodeEnum::AR_RECEIVABLE), 0, $uang['ar'],
                'Tagihan dihapus - ' . $doc->invoice_number, $doc->customer_id
            );
        }

        if ($uang['refund'] > 0) {
            $lines[] = new JournalLineDTO(
                $this->akunTujuan($doc, $uang['target'], $dto->refund_account_id), 0, $uang['refund'],
                'Pengembalian dana (' . (SalesReturn::REFUND_TARGETS[$uang['target']] ?? $uang['target']) . ')',
                $dto->refund_customer_id ?: $doc->customer_id
            );
        }

        if ($uang['fee'] > 0) {
            // Biaya admin yang TIDAK jadi kita tanggung: pembeli hanya menerima dana bersih.
            $config = \App\Models\MarketplaceConfig::where('customer_id', $doc->customer_id)->first();
            $lines[] = new JournalLineDTO(
                (int) ($config?->account_fee_id ?: $this->getAccountId(AccountCodeEnum::SALES_LOSS)), 0, $uang['fee'],
                'Biaya admin tidak dikembalikan ke pembeli'
            );
        }

        // Titipan pembeli yang masih ditahan marketplace dilepas balik — pasangan tersendiri.
        if ($uang['hold'] > 0) {
            $config = \App\Models\MarketplaceConfig::where('customer_id', $doc->customer_id)->first();
            $lines[] = new JournalLineDTO($this->getAccountId(AccountCodeEnum::SALES_ADVANCE), $uang['hold'], 0, 'Titipan pembeli dikembalikan');
            $lines[] = new JournalLineDTO((int) $config->account_receivable_hold_id, 0, $uang['hold'], 'Pelepasan saldo ditahan untuk retur', $doc->customer_id);
        }

        return $lines;
    }

    private function getCogsReversalLines($dto, $doc, $returnId)
    {
        $lines = [];
        $isSO = (bool) $dto->sales_order_id;
        $docNumber = $doc->invoice_number ?? $doc->order_number;

        foreach ($dto->items as $item) {
            $docItem = $doc->items->firstWhere('id', $item['invoice_item_id']);
            if (!$docItem) {
                continue;
            }

            $qty = (float) $docItem->qty;
            if ($qty <= 0) {
                continue;
            }

            $cogsTotal = (float) ($docItem->cogs_total ?? 0);

            if ($doc instanceof \App\Modules\Sales\Models\SalesOrder && $cogsTotal == 0) {
                $cogsTotal = $doc->deliveries()->where('status', 'posted')->with('items')->get()
                    ->flatMap->items
                    ->where('sales_order_item_id', $docItem->id)
                    ->sum('cogs_total');
            }

            $product = Product::find($docItem->product_id);

            if ($product && $product->sale_type === 'bundle' && $cogsTotal == 0 && $dto->invoice_id) {
                $delivery = $doc->delivery ?? null;
                if ($delivery && $delivery->items) {
                    $components = BundleComponent::where('bundle_product_id', $docItem->product_id)->get();
                    $qtyField = 'qty';
                    if ($components->isEmpty()) {
                        $components = ProductBundle::where('bundle_product_id', $docItem->product_id)->get();
                        $qtyField = 'qty_required';
                    }
                    foreach ($components as $comp) {
                        $deliveryItem = $delivery->items->firstWhere('product_id', $comp->component_product_id);
                        if ($deliveryItem) {
                            $cogsTotal += (float) $deliveryItem->cogs_total;
                        }
                    }
                }
            }

            // Bundle dengan kondisi PER KOMPONEN → tiap komponen dirutekan & di-COGS sendiri.
            $componentConditions = $this->normalizeComponentConditions($item['component_conditions'] ?? null);
            if ($product && $product->sale_type === 'bundle' && $componentConditions) {
                $lines = array_merge($lines, $this->bundleComponentCogsLines(
                    $doc, $docItem, $item, $componentConditions, $docNumber, $returnId, $isSO
                ));
                continue;
            }

            // `tidak_kembali`: barang hilang & dananya diganti → penjualannya sah. HPP tetap
            // di 5001, stok tidak dipulihkan, tidak ada baris jurnal sama sekali.
            if (($item['condition'] ?? null) === SalesReturn::CONDITION_NO_RETURN) {
                continue;
            }

            $unitCogs = $cogsTotal > 0 ? $cogsTotal / $qty : 0;
            $returnCogs = round($unitCogs * $item['qty'], 2);

            if ($item['condition'] === 'good' || $item['condition'] === 'repair') {
                $this->restoreReturnStock($dto, $doc, $docItem, $item, $unitCogs, $docNumber, $returnId);
            }

            if ($returnCogs <= 0) {
                continue;
            }

            $debitAccountCode = $this->getReturnConditionAccountCode($item['condition']);
            $debitAccountId = $this->getAccountId($debitAccountCode);
            $conditionLabel = $this->getReturnConditionLabel($item['condition']);

            $lines[] = new JournalLineDTO(
                account_id: $debitAccountId,
                debit: (float) $returnCogs,
                credit: 0,
                description: $isSO
                    ? "Retur SO - {$conditionLabel}"
                    : "Retur Invoice - {$conditionLabel}"
            );

            $lines[] = new JournalLineDTO(
                account_id: $this->getAccountId(AccountCodeEnum::COGS),
                debit: 0,
                credit: (float) $returnCogs,
                description: "{$conditionLabel} COGS Reversal"
            );
        }

        return $lines;
    }

    private function restoreReturnStock($dto, $doc, $docItem, array $item, float $unitCogs, string $docNumber, int $returnId): void
    {
        $product = Product::find($docItem->product_id);

        // Barang kondisi 'repair' masuk ke Gudang Perbaikan (non-jual, = akun 1131), BUKAN
        // gudang jual — supaya tidak ikut terjual & saldo 1131 cocok dengan stok fisik.
        // Kondisi 'good' tetap kembali ke gudang dokumen. (bundle: komponennya ikut aturan sama)
        $targetWarehouseId = $item['condition'] === 'repair'
            ? (Warehouse::repairId() ?? $doc->warehouse_id)
            : $doc->warehouse_id;

        if ($product && $product->sale_type === 'bundle') {
            $components = BundleComponent::where('bundle_product_id', $docItem->product_id)->get();
            $qtyField = 'qty';

            if ($components->isEmpty()) {
                $components = ProductBundle::where('bundle_product_id', $docItem->product_id)->get();
                $qtyField = 'qty_required';
            }

            $totalUnits = $components->sum(fn ($c) => $c->{$qtyField} ?? 1);

            foreach ($components as $comp) {
                $compQtyPerBundle = $comp->{$qtyField} ?? 1;
                $compQtyReturn = $compQtyPerBundle * $item['qty'];
                $compCostPerUnit = ($totalUnits > 0 && $unitCogs > 0)
                    ? $unitCogs * ($compQtyPerBundle / $totalUnits)
                    : 0;

                app(FifoService::class)->stockIn(
                    productId: $comp->component_product_id,
                    warehouseId: $targetWarehouseId,
                    type: 'sales_return',
                    reference: $docNumber,
                    qty: $compQtyReturn,
                    cost: $compCostPerUnit,
                    transactionId: $returnId
                );
            }

            return;
        }

        app(FifoService::class)->stockIn(
            productId: $docItem->product_id,
            warehouseId: $targetWarehouseId,
            type: 'sales_return',
            reference: $docNumber,
            qty: $item['qty'],
            cost: $unitCogs,
            transactionId: $returnId
        );
    }

    /** Kumpulkan item SJ (posted) untuk dokumen retur — sumber COGS per komponen. */
    private function deliveryItemsFor($doc)
    {
        if ($doc instanceof \App\Modules\Sales\Models\SalesOrder) {
            return $doc->deliveries()->where('status', 'posted')->with('items')->get()->flatMap->items;
        }
        return $doc->delivery?->items ?? collect();
    }

    /**
     * Bundle dengan kondisi PER KOMPONEN: rutekan stok & buat baris COGS untuk tiap komponen
     * sesuai kondisinya sendiri (good→persediaan, repair→Gudang Perbaikan, damaged→beban).
     * Pembalikan pendapatan tetap di level bundle (di getRevenueReversalLines), tak disentuh.
     */
    private function bundleComponentCogsLines($doc, $docItem, array $item, array $componentConditions, string $docNumber, int $returnId, bool $isSO): array
    {
        $components = BundleComponent::where('bundle_product_id', $docItem->product_id)->get();
        $qtyField = 'qty';
        if ($components->isEmpty()) {
            $components = ProductBundle::where('bundle_product_id', $docItem->product_id)->get();
            $qtyField = 'qty_required';
        }

        $deliveryItems     = $this->deliveryItemsFor($doc);
        $docQty            = (float) $docItem->qty;
        $retQty            = (float) $item['qty'];
        $repairWarehouseId = Warehouse::repairId();
        $fifo              = app(FifoService::class);
        $lines             = [];

        foreach ($components as $comp) {
            $cond = $componentConditions[(int) $comp->component_product_id] ?? 'good';

            // Komponen yang tidak kembali & dananya diganti: tak ada stok masuk, tak ada
            // pemindahan HPP — sama seperti baris non-bundle.
            if ($cond === SalesReturn::CONDITION_NO_RETURN) {
                continue;
            }
            $compQtyPerBundle = (float) ($comp->{$qtyField} ?? 1);
            if ($compQtyPerBundle <= 0) {
                continue;
            }

            $di            = $deliveryItems->firstWhere('product_id', $comp->component_product_id);
            $compDelivCogs = (float) ($di->cogs_total ?? 0);
            // Biaya 1 unit komponen (delivery cogs mencakup docQty × compQtyPerBundle unit).
            $compUnitCost   = ($docQty > 0) ? $compDelivCogs / ($docQty * $compQtyPerBundle) : 0;
            $compQtyReturn  = $compQtyPerBundle * $retQty;
            $compReturnCogs = round($compUnitCost * $compQtyReturn, 2);

            // Routing stok: good/repair masuk gudang (repair→Gudang Perbaikan); damaged tidak.
            if ($cond === 'good' || $cond === 'repair') {
                $warehouseId = $cond === 'repair'
                    ? ($repairWarehouseId ?? $doc->warehouse_id)
                    : $doc->warehouse_id;
                $fifo->stockIn(
                    productId: $comp->component_product_id,
                    warehouseId: $warehouseId,
                    type: 'sales_return',
                    reference: $docNumber,
                    qty: $compQtyReturn,
                    cost: $compUnitCost,
                    transactionId: $returnId
                );
            }

            if ($compReturnCogs <= 0) {
                continue;
            }

            $label = $this->getReturnConditionLabel($cond);
            $lines[] = new JournalLineDTO(
                account_id: $this->getAccountId($this->getReturnConditionAccountCode($cond)),
                debit: (float) $compReturnCogs,
                credit: 0,
                description: ($isSO ? 'Retur SO' : 'Retur Invoice') . " - {$label} (komponen)"
            );
            $lines[] = new JournalLineDTO(
                account_id: $this->getAccountId(AccountCodeEnum::COGS),
                debit: 0,
                credit: (float) $compReturnCogs,
                description: "{$label} COGS Reversal (komponen)"
            );
        }

        return $lines;
    }

    private function getReturnConditionAccountCode(string $condition): string
    {
        return match ($condition) {
            'good' => AccountCodeEnum::INVENTORY,
            'repair' => AccountCodeEnum::INVENTORY_REPAIR,
            'damaged' => AccountCodeEnum::SALES_LOSS,
            // tidak_kembali tak pernah sampai sini (dilewati di getCogsReversalLines).
            default => AccountCodeEnum::INVENTORY,
        };
    }

    private function getReturnConditionLabel(string $condition): string
    {
        return match ($condition) {
            'good' => 'Persediaan',
            'repair' => 'Persediaan Perbaikan',
            'damaged' => 'Beban Kerugian Retur',
            default => 'Persediaan',
        };
    }

    private function getDoc(SalesReturnDTO $dto)
    {
        if ($dto->invoice_id) {
            return SalesInvoice::with(['items.product', 'customer', 'delivery.items'])->findOrFail($dto->invoice_id);
        } elseif ($dto->sales_order_id) {
            return \App\Modules\Sales\Models\SalesOrder::with(['items', 'customer', 'deliveries.items'])->findOrFail($dto->sales_order_id);
        }

        throw new Exception('Either invoice_id atau sales_order_id harus diisi.');
    }

    private function validatePosting(SalesReturnDTO $dto, $doc, ?int $existingReturnId = null)
    {
        $docType = $dto->invoice_id ? 'invoice' : 'so';
        $docStatus = $doc->status instanceof \BackedEnum ? $doc->status->value : (string) $doc->status;

        if ($docType === 'invoice') {
            if (!in_array($docStatus, ['posted', 'partial'])) {
                throw new Exception("Invoice status '{$docStatus}' tidak dapat diretur. Harus posted atau partial.");
            }
        } else {
            if (!in_array($docStatus, ['confirmed', 'closed', 'partial'])) {
                throw new Exception("Sales Order status '{$docStatus}' tidak dapat diretur.");
            }

            $hasSJ = $doc->deliveries()->where('status', 'posted')->exists();
            if (!$hasSJ) {
                throw new Exception('Sales Order belum memiliki Pengiriman (SJ) yang diposting. Jika barang belum keluar, cukup lakukan VOID pada SO.');
            }

            $isPaid = $doc->payment_status === 'paid' || (($doc->paid_amount ?? 0) >= $doc->grand_total);
            if (!$isPaid) {
                throw new Exception('Sales Order belum lunas. Retur uang hanya berlaku untuk pesanan yang sudah terbayar penuh (Advance).');
            }
        }

        if (empty($dto->items)) {
            throw new Exception('Minimal harus ada 1 item yang diretur.');
        }

        // Satu produk kini bisa dipecah ke beberapa baris (kondisi berbeda: utuh/perbaikan/
        // rusak). Validasi returnable harus pakai TOTAL per item dokumen, bukan per baris —
        // kalau tidak, tiap baris lolos sendiri (mis. utuh 2 + rusak 2) padahal jumlahnya
        // melebihi qty dokumen.
        $requestedByRef = [];
        foreach ($dto->items as $item) {
            $refId = $item['invoice_item_id'];
            if (!$doc->items->firstWhere('id', $refId)) {
                throw new Exception("Item ID {$refId} tidak ditemukan pada dokumen");
            }
            if ($item['qty'] <= 0) {
                throw new Exception('Qty retur harus lebih besar dari 0');
            }
            $requestedByRef[$refId] = ($requestedByRef[$refId] ?? 0) + (float) $item['qty'];
        }

        foreach ($requestedByRef as $refId => $requestedQty) {
            $targetItem = $doc->items->firstWhere('id', $refId);

            // Qty yang SUDAH diretur (posted) untuk item dokumen ini, kecuali retur yang
            // sedang di-post. Cegah double-return: validasi pakai SISA, bukan qty penuh.
            $alreadyReturned = (float) SalesReturnItem::where('reference_item_id', $refId)
                ->whereHas('salesReturn', function ($q) use ($existingReturnId) {
                    $q->where('status', 'posted');
                    if ($existingReturnId) {
                        $q->where('id', '!=', $existingReturnId);
                    }
                })
                ->sum('qty');

            $returnable = (float) $targetItem->qty - $alreadyReturned;
            if ($requestedQty > $returnable + 0.00001) {
                throw new Exception(
                    "Total qty retur ({$requestedQty}) untuk " . ($targetItem->product?->name ?? "item #{$refId}") . " melebihi sisa yang bisa diretur ({$returnable}). "
                    . "Qty dokumen: {$targetItem->qty}, sudah diretur: {$alreadyReturned}."
                );
            }
        }
    }

    private function calculateTotals($dto, $doc)
    {
        $totalNet = 0;
        $totalReversed = 0;

        foreach ($dto->items as $item) {
            $docItem = $doc->items->firstWhere('id', $item['invoice_item_id']);

            if (!$docItem) {
                throw new Exception("Item ID {$item['invoice_item_id']} tidak ditemukan");
            }

            $docItemSubtotal = $docItem->subtotal ?? $docItem->line_total ?? 0;
            $ratio = $item['qty'] / $docItem->qty;
            $lineNet = $docItemSubtotal * $ratio;
            $totalNet += $lineNet;

            // Baris `tidak_kembali` dananya diganti marketplace → TIDAK mengurangi penjualan.
            if (($item['condition'] ?? null) !== SalesReturn::CONDITION_NO_RETURN) {
                $totalReversed += $lineNet;
            }
        }

        return [
            // Nilai kasus retur seutuhnya (dipakai sbg grand_total dokumen).
            'net'      => round($totalNet, 2),
            // Bagian yang benar-benar membalik penjualan.
            'reversed' => round($totalReversed, 2),
        ];
    }

    private function getAccountId($code)
    {
        $id = Account::where('code', $code)->value('id');
        if (!$id) {
            if ($code === AccountCodeEnum::INVENTORY_REPAIR || $code === AccountCodeEnum::INVENTORY_DAMAGED) {
                return $this->getAccountId(AccountCodeEnum::INVENTORY);
            }
            if ($code === AccountCodeEnum::SALES_LOSS) {
                return $this->getAccountId(AccountCodeEnum::COGS);
            }

            throw new Exception("Account with code {$code} not found.");
        }

        return $id;
    }
}
