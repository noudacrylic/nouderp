<?php

namespace App\Modules\Sales\Services;

use App\Models\WarrantyOrder;
use App\Models\WarrantyOrderItem;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesDeliveryItem;
use App\Core\Inventory\InventoryEngine;
use App\Core\Inventory\FifoService; // used for warranty_receive stockIn
use App\Core\Journal\JournalPostingService;
use App\DTO\JournalEntryDTO;
use App\DTO\JournalLineDTO;
use App\Enums\AccountCodeEnum;
use App\Services\NumberGeneratorService;
use App\Core\Accounting\Account;
use Illuminate\Support\Facades\DB;
use Exception;

class WarrantyOrderService
{
    public function create(array $data): WarrantyOrder
    {
        return DB::transaction(function () use ($data) {
            $warranty = WarrantyOrder::create([
                'warranty_number'   => NumberGeneratorService::generate('GR'),
                'customer_id'       => $data['customer_id'],
                'invoice_id'        => $data['invoice_id'] ?? null,
                'sales_order_id'    => $data['sales_order_id'] ?? null,
                'warehouse_id'      => $data['warehouse_id'],
                'warranty_date'     => $data['warranty_date'],
                'type'              => $data['type'],
                'status'            => 'draft',
                'issue_description' => $data['issue_description'] ?? null,
                'notes'             => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                WarrantyOrderItem::create([
                    'warranty_order_id' => $warranty->id,
                    'product_id'        => $item['product_id'],
                    'qty'               => $item['qty'],
                    'issue_description' => $item['issue_description'] ?? null,
                ]);
            }

            return $warranty;
        });
    }

    public function update(int $id, array $data): WarrantyOrder
    {
        return DB::transaction(function () use ($id, $data) {
            $warranty = WarrantyOrder::findOrFail($id);

            if ($warranty->status !== 'draft') {
                throw new Exception('Hanya garansi berstatus draft yang dapat diubah.');
            }

            $warranty->update([
                'customer_id'       => $data['customer_id'],
                'invoice_id'        => $data['invoice_id'] ?? null,
                'sales_order_id'    => $data['sales_order_id'] ?? null,
                'warehouse_id'      => $data['warehouse_id'],
                'warranty_date'     => $data['warranty_date'],
                'type'              => $data['type'],
                'issue_description' => $data['issue_description'] ?? null,
                'notes'             => $data['notes'] ?? null,
            ]);

            $warranty->items()->delete();

            foreach ($data['items'] as $item) {
                WarrantyOrderItem::create([
                    'warranty_order_id' => $warranty->id,
                    'product_id'        => $item['product_id'],
                    'qty'               => $item['qty'],
                    'issue_description' => $item['issue_description'] ?? null,
                ]);
            }

            return $warranty;
        });
    }

    /**
     * Terima barang + posting garansi dalam 1 langkah:
     *   - FIFO stock-in ke 1131 (Persediaan Perbaikan)
     *   - Jurnal Dr 1131 / Cr 1132 Beban Stok Garansi
     *   - Status → 'posted'
     */
    public function receive(int $warrantyOrderId): void
    {
        DB::transaction(function () use ($warrantyOrderId) {
            $warranty = WarrantyOrder::with('items.product')->findOrFail($warrantyOrderId);

            // Terima 'draft' (alur normal) maupun 'received' (data lama yang belum diposting)
            if (!in_array($warranty->status, ['draft', 'received'], true)) {
                throw new Exception('Hanya garansi berstatus draft/diterima yang dapat diposting.');
            }

            // Guard idempoten: jangan dobel-post kalau jurnal warranty_post sudah ada
            if (\App\Core\Journal\Journal::where('reference_type', 'warranty_post')->where('reference_id', $warranty->id)->exists()) {
                $warranty->update(['status' => 'posted']);
                return;
            }

            if ($warranty->items->isEmpty()) {
                throw new Exception('Tidak ada item pada garansi ini.');
            }

            $totalCost = 0;

            foreach ($warranty->items as $item) {
                $cost = (float) ($item->product->last_cost ?? 0);
                app(FifoService::class)->stockIn(
                    productId:     $item->product_id,
                    warehouseId:   $warranty->warehouse_id,
                    type:          'warranty_receive',
                    reference:     $warranty->warranty_number,
                    qty:           $item->qty,
                    cost:          $cost,
                    transactionId: $warranty->id
                );
                $totalCost += $item->qty * $cost;
            }

            if ($totalCost > 0) {
                $inventoryRepairAccount = Account::where('code', AccountCodeEnum::INVENTORY_REPAIR)->firstOrFail();
                $warrantyStockExpense   = Account::where('code', AccountCodeEnum::WARRANTY_STOCK)->firstOrFail();

                app(JournalPostingService::class)->post(new JournalEntryDTO(
                    date:             $warranty->warranty_date->format('Y-m-d'),
                    reference_type:   'warranty_post',
                    reference_id:     $warranty->id,
                    description:      "Terima & Posting Garansi - {$warranty->warranty_number}",
                    lines: [
                        new JournalLineDTO(
                            account_id:  $inventoryRepairAccount->id,
                            debit:       (float) $totalCost,
                            credit:      0,
                            description: 'Nilai persediaan perbaikan dari garansi'
                        ),
                        new JournalLineDTO(
                            account_id:  $warrantyStockExpense->id,
                            debit:       0,
                            credit:      (float) $totalCost,
                            description: 'Beban stok garansi diakui'
                        ),
                    ],
                    reference_number: $warranty->warranty_number
                ));
            }

            $warranty->update(['status' => 'posted']);
        });
    }

    /**
     * [type=repair only] Tandai perbaikan selesai
     */
    public function markRepaired(int $warrantyOrderId, ?float $repairCost = null): void
    {
        $warranty = WarrantyOrder::findOrFail($warrantyOrderId);

        if ($warranty->type !== 'repair') {
            throw new Exception('Aksi ini hanya berlaku untuk garansi tipe Perbaikan.');
        }

        if ($warranty->status !== 'posted') {
            throw new Exception('Garansi harus diposting dulu (Terima & Posting → Dr 1131 / Cr 1132) sebelum ditandai selesai diperbaiki.');
        }

        $warranty->update([
            'status'      => 'repaired',
            'repair_cost' => $repairCost,
        ]);
    }

    /**
     * Auto-advance dari finalisasi OP Perbaikan (dipanggil ProductionOrderService::finalize).
     * Idempotent & tidak melempar exception: hanya menaikkan posted → repaired,
     * selain itu no-op (mis. belum diposting, sudah repaired/shipped, atau bukan perbaikan).
     */
    public function markRepairedFromProduction(int $warrantyOrderId, ?float $repairCost = null): bool
    {
        $warranty = WarrantyOrder::find($warrantyOrderId);
        if (!$warranty) return false;
        if ($warranty->type !== 'repair') return false;
        if ($warranty->status !== 'posted') return false;

        $warranty->update([
            'status'      => 'repaired',
            'repair_cost' => $repairCost ?? $warranty->repair_cost,
        ]);
        return true;
    }

    /**
     * Buat SJ Garansi (outbound delivery, draft)
     */
    public function createDelivery(int $warrantyOrderId, array $fees = [], ?string $date = null): SalesDelivery
    {
        return DB::transaction(function () use ($warrantyOrderId, $fees, $date) {
            $warranty = WarrantyOrder::with('items')->findOrFail($warrantyOrderId);

            $this->validateDeliveryCreation($warranty);

            // Guard: belum ada SJ
            if ($warranty->delivery()->exists()) {
                throw new Exception('SJ Garansi sudah dibuat untuk order ini.');
            }

            $date = $date ?: now()->toDateString();

            // Item SJ = "Barang Dilaporkan Bermasalah" (otomatis, tanpa pilih produk manual)
            $outboundItems = $warranty->items
                ->filter(fn($i) => (float) $i->qty > 0)
                ->map(fn($i) => ['product_id' => $i->product_id, 'qty' => (float) $i->qty])
                ->values();

            if ($outboundItems->isEmpty()) {
                throw new Exception('Tidak ada barang untuk dikirim.');
            }

            // Simpan biaya garansi (dipakai saat post SJ untuk jurnal)
            $warranty->update([
                'service_fee'         => $fees['service_fee'] ?? 0,
                'shipping_cost'       => $fees['shipping_cost'] ?? 0,
                'shipping_paid_by'    => ($fees['shipping_cost'] ?? 0) > 0 ? ($fees['shipping_paid_by'] ?? null) : null,
                'fee_cash_account_id' => $fees['fee_cash_account_id'] ?? null,
            ]);

            for ($i = 0; $i < 5; $i++) {
                try {
                    $delivery = SalesDelivery::create([
                        'delivery_number' => NumberGeneratorService::generate('DOGR'),
                        'reference_type'  => 'warranty_order',
                        'reference_id'    => $warranty->id,
                        'warehouse_id'    => $warranty->warehouse_id,
                        'delivery_date'   => $date,
                        'status'          => 'draft',
                    ]);

                    foreach ($outboundItems as $item) {
                        SalesDeliveryItem::create([
                            'sales_delivery_id' => $delivery->id,
                            'product_id'        => $item['product_id'],
                            'qty'               => $item['qty'],
                        ]);
                    }

                    return $delivery;

                } catch (\Illuminate\Database\QueryException $e) {
                    if ($e->errorInfo[1] == 1062) continue;
                    throw $e;
                }
            }

            throw new Exception('Gagal membuat nomor SJ Garansi.');
        });
    }

    /**
     * Post SJ Garansi → stock out + jurnal Dr 1132 + Dr 6106 / Cr 1130
     *
     * 1132 Beban Stok Garansi = HPP barang milik customer (numpang di buku kita)
     * 6106 Beban Garansi      = biaya perbaikan aktual (WIP total − HPP asli)
     */
    public function postDelivery(int $deliveryId): void
    {
        DB::transaction(function () use ($deliveryId) {
            $delivery = SalesDelivery::with(['items.product'])->lockForUpdate()->findOrFail($deliveryId);

            if ($delivery->status === 'posted') {
                throw new Exception('SJ sudah diposting.');
            }

            if ($delivery->reference_type !== 'warranty_order') {
                throw new Exception('SJ ini bukan SJ Garansi.');
            }

            $warranty = WarrantyOrder::findOrFail($delivery->reference_id);

            $totalCogs = 0;

            foreach ($delivery->items as $item) {
                if ($item->qty <= 0) continue;

                $cogs = app(InventoryEngine::class)->ship(
                    $item->product_id,
                    $delivery->warehouse_id,
                    $item->qty,
                    'warranty_delivery',
                    $delivery->id,
                    0
                );

                $item->update(['cogs_total' => $cogs]);
                $totalCogs += $cogs;
            }

            if ($totalCogs > 0) {
                // Ambil HPP asli dari jurnal warranty_post (Dr 1131 yg sudah diposting)
                $inventoryRepairAccount = Account::where('code', AccountCodeEnum::INVENTORY_REPAIR)->firstOrFail();
                $originalItemCost = (float) \App\Core\Journal\JournalLine::where('account_id', $inventoryRepairAccount->id)
                    ->whereHas('journal', fn($q) => $q
                        ->where('reference_type', 'warranty_post')
                        ->where('reference_id', $warranty->id)
                    )
                    ->sum('debit');

                // Pecah COGS jadi: HPP barang customer (1132) + biaya perbaikan (6106),
                // dengan total selalu = totalCogs agar jurnal seimbang.
                $stockExpense = min($originalItemCost, (float) $totalCogs); // HPP barang customer
                $repairCost   = (float) $totalCogs - $stockExpense;          // sisanya = biaya perbaikan

                $inventoryAccount       = Account::where('code', AccountCodeEnum::INVENTORY)->firstOrFail();
                $warrantyStockExpense   = Account::where('code', AccountCodeEnum::WARRANTY_STOCK)->firstOrFail();   // 1132
                $warrantyExpenseAccount = Account::where('code', AccountCodeEnum::WARRANTY_EXPENSE)->firstOrFail(); // 6106

                $lines = [
                    new JournalLineDTO(
                        account_id:  $inventoryAccount->id,
                        debit:       0,
                        credit:      (float) $totalCogs,
                        description: 'Barang garansi keluar dari persediaan'
                    ),
                ];

                if ($stockExpense > 0) {
                    $lines[] = new JournalLineDTO(
                        account_id:  $warrantyStockExpense->id,
                        debit:       $stockExpense,
                        credit:      0,
                        description: 'Beban stok garansi — HPP barang customer'
                    );
                }

                if ($repairCost > 0) {
                    $lines[] = new JournalLineDTO(
                        account_id:  $warrantyExpenseAccount->id,
                        debit:       $repairCost,
                        credit:      0,
                        description: 'Beban garansi — biaya perbaikan'
                    );
                }

                app(JournalPostingService::class)->post(new JournalEntryDTO(
                    date:             \Carbon\Carbon::parse($delivery->delivery_date)->format('Y-m-d'),
                    reference_type:   'warranty_delivery',
                    reference_id:     $delivery->id,
                    description:      "SJ Garansi - {$delivery->delivery_number} (Ref: {$warranty->warranty_number})",
                    lines:            $lines,
                    reference_number: $delivery->delivery_number
                ));
            }

            // Jurnal biaya garansi (jasa perbaikan + ongkir) — uang lewat Kas/Bank
            $this->postWarrantyFees($warranty, $delivery);

            $delivery->update(['status' => 'posted', 'posted_at' => now()]);
            $warranty->update(['status' => 'shipped']);
        });
    }

    /**
     * Jurnal biaya garansi saat SJ diposting (keduanya lewat Kas/Bank):
     *  - Jasa garansi      → Cr 4010 Pendapatan Jasa
     *  - Ongkir customer   → Cr 1203 Titipan Pengiriman (dibayar customer lewat kita)
     *  - Ongkir perusahaan → Dr 6106 Beban Garansi (kita yang tanggung)
     *  - Saldo bersih masuk/keluar Kas/Bank yang dipilih.
     */
    private function postWarrantyFees(WarrantyOrder $warranty, SalesDelivery $delivery): void
    {
        $serviceFee = (float) ($warranty->service_fee ?? 0);
        $shipping   = (float) ($warranty->shipping_cost ?? 0);
        $paidBy     = $warranty->shipping_paid_by;

        $customerShipping = ($shipping > 0 && $paidBy === 'customer') ? $shipping : 0.0;
        $companyShipping  = ($shipping > 0 && $paidBy === 'company')  ? $shipping : 0.0;

        if ($serviceFee <= 0 && $customerShipping <= 0 && $companyShipping <= 0) {
            return; // tidak ada biaya garansi
        }

        if (!$warranty->fee_cash_account_id) {
            throw new Exception('Akun Kas/Bank untuk biaya garansi belum dipilih.');
        }

        $lines = [];

        if ($serviceFee > 0) {
            $lines[] = new JournalLineDTO(
                account_id:  $this->getAccountId(AccountCodeEnum::SERVICE_REVENUE), // 4010
                debit:       0,
                credit:      $serviceFee,
                description: 'Pendapatan jasa perbaikan garansi'
            );
        }
        if ($customerShipping > 0) {
            $lines[] = new JournalLineDTO(
                account_id:  $this->getAccountId(AccountCodeEnum::SHIPPING_DEPOSIT), // 1203
                debit:       0,
                credit:      $customerShipping,
                description: 'Titipan ongkir dari customer'
            );
        }
        if ($companyShipping > 0) {
            $lines[] = new JournalLineDTO(
                account_id:  $this->getAccountId(AccountCodeEnum::WARRANTY_EXPENSE), // 6106
                debit:       $companyShipping,
                credit:      0,
                description: 'Beban garansi — ongkir ditanggung perusahaan'
            );
        }

        // Saldo Kas/Bank bersih: masuk = jasa + ongkir customer, keluar = ongkir perusahaan
        $cashAmount = $serviceFee + $customerShipping - $companyShipping;
        if ($cashAmount > 0) {
            $lines[] = new JournalLineDTO(
                account_id:  (int) $warranty->fee_cash_account_id,
                debit:       $cashAmount,
                credit:      0,
                description: 'Penerimaan biaya garansi dari customer'
            );
        } elseif ($cashAmount < 0) {
            $lines[] = new JournalLineDTO(
                account_id:  (int) $warranty->fee_cash_account_id,
                debit:       0,
                credit:      abs($cashAmount),
                description: 'Pembayaran ongkir garansi'
            );
        }

        app(JournalPostingService::class)->post(new JournalEntryDTO(
            date:             \Carbon\Carbon::parse($delivery->delivery_date)->format('Y-m-d'),
            reference_type:   'warranty_fee',
            reference_id:     $delivery->id,
            description:      "Biaya Garansi - {$delivery->delivery_number} (Ref: {$warranty->warranty_number})",
            lines:            $lines,
            reference_number: $delivery->delivery_number
        ));
    }

    public function delete(int $id): void
    {
        $warranty = WarrantyOrder::findOrFail($id);

        if ($warranty->status !== 'draft') {
            throw new Exception('Hanya garansi berstatus draft yang dapat dihapus.');
        }

        DB::transaction(function () use ($warranty) {
            $warranty->items()->delete();
            $warranty->delete();
        });
    }

    private function validateDeliveryCreation(WarrantyOrder $warranty): void
    {
        if ($warranty->status !== 'repaired') {
            throw new Exception('Perbaikan harus selesai dulu sebelum membuat SJ pengiriman balik.');
        }
    }

    private function getAccountId(string $code): int
    {
        return Account::where('code', $code)->firstOrFail()->id;
    }
}
