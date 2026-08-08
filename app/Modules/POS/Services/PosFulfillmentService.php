<?php

namespace App\Modules\POS\Services;

use App\DTO\SalesInvoiceDTO;
use App\DTO\SalesInvoiceItemDTO;
use App\Models\SalesInvoice;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Services\InvoicePostingService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * "Proses Pesanan": dari Sales Order siap → bikin 1 invoice (lock 1:1) lalu post
 * (otomatis membuat Surat Jalan untuk sisa qty, settle COGS, apply uang muka).
 * Reuse penuh SalesInvoiceService + InvoicePostingService — tidak ada akuntansi baru.
 */
class PosFulfillmentService
{
    public function __construct(
        private SalesInvoiceService $invoiceService,
        private InvoicePostingService $postingService,
    ) {}

    public function createInvoiceFromSalesOrder(SalesOrder $so, ?string $pickupCode = null): SalesInvoice
    {
        $so->loadMissing(['items', 'customer']);

        // ── Guards ──
        if ($so->status !== 'confirmed') {
            throw new DomainException('Sales Order harus berstatus confirmed.');
        }
        if ($so->customer && $so->customer->is_marketplace) {
            throw new DomainException('Pesanan marketplace tidak diproses di modul ini (ditangani Jubelio).');
        }
        // Faktur DIPOSTING → sudah benar-benar diproses. Faktur DRAFT TIDAK memblokir
        // (ditangani di bawah: cukup diposting). Faktur draft dianggap "belum diproses".
        if (SalesInvoice::where('sales_order_id', $so->id)->where('status', 'posted')->exists()) {
            throw new DomainException('Sales Order ini sudah memiliki invoice yang diposting.');
        }
        // Belum lunas → tertahan, KECUALI pesanannya memang tempo — kesepakatan yang
        // ditetapkan admin di form SO (bukan keputusan bagian packing).
        $remaining = round((float) $so->grand_total - (float) $so->paid_amount, 2);
        if ($remaining > 0.01 && !$so->is_tempo) {
            throw new DomainException('Pesanan belum lunas. Sisa Rp ' . number_format($remaining, 0, ',', '.') . ' harus dibayar dulu.');
        }

        // Preorder DIBUAT KHUSUS per pesanan: produksi wajib finalized (cegah error COGS mentah
        // saat posting). Dikecualikan bila pesanannya di-waive — barang yang fisiknya sudah ada
        // tanpa lewat produksi ERP (mis. masuk via Stock Opname) tak akan pernah punya OP
        // finalized. Stoknya tetap dijaga jalur FIFO saat posting faktur.
        //
        // Preorder berspesifikasi tetap TIDAK lewat sini: barangnya bisa datang dari stok yang
        // sudah ada (termasuk sisa pesanan yang batal), jadi menuntut OP finalized berarti
        // menahan pesanan yang barangnya jelas-jelas siap. Kecukupan stoknya dijaga
        // SalesOrderStockCheck + jalur FIFO saat posting.
        $isCustom = $so->items->contains(fn ($i) => optional($i->product)->sale_type === 'preorder'
            && optional($i->product)->made_to_order);
        if ($isCustom && !$so->production_waived_at && !$this->productionFinalized($so->id)) {
            throw new DomainException('Produksi pesanan custom belum selesai (finalisasi OP dulu).');
        }

        // Ambil di toko: verifikasi kode booking.
        if ($so->isPickup()) {
            $code = strtoupper(trim((string) $pickupCode));
            if ($code === '' || $code !== strtoupper((string) $so->pickup_code)) {
                throw new DomainException('Kode pengambilan (booking) salah atau kosong.');
            }
        }

        // Faktur DRAFT sudah ada (mis. dibuat manual) → cukup POSTING faktur itu; jangan
        // buat faktur baru (qty_invoiced SO sudah terpakai). DP & ongkir dihitung saat posting.
        $existingDraft = SalesInvoice::where('sales_order_id', $so->id)->where('status', 'draft')->first();
        if ($existingDraft) {
            return DB::transaction(function () use ($so, $existingDraft) {
                SalesOrder::where('id', $so->id)->lockForUpdate()->first();
                if (SalesInvoice::where('sales_order_id', $so->id)->where('status', 'posted')->exists()) {
                    throw new DomainException('Sales Order ini sudah memiliki invoice yang diposting.');
                }
                if ($so->isPickup()) {
                    $so->update(['pickup_status' => 'picked_up', 'picked_up_at' => now()]);
                }
                $this->postingService->post($existingDraft);
                return $existingDraft;
            });
        }

        // ── Build DTO dari SELURUH SO (sisa qty per baris) ──
        $items = [];
        foreach ($so->items as $si) {
            $qty = (float) $si->qty - (float) ($si->qty_invoiced ?? 0);
            if ($qty <= 0) continue;
            $items[] = new SalesInvoiceItemDTO(
                $si->id,
                $si->product_id,
                (string) ($si->description ?? ''),
                'product',
                $qty,
                (float) $si->unit_price,
                $si->discount_type ?: 'nominal',
                (float) ($si->discount_value ?? 0),
                0, // discount_amount diabaikan (dihitung ulang oleh service)
                (float) ($so->ppn_percent ?? 0),
                (float) ($so->pph_percent ?? 0),
            );
        }
        if (empty($items)) {
            throw new DomainException('Tidak ada barang yang bisa ditagihkan dari SO ini.');
        }

        $shippingNet = $so->isPickup() ? 0.0 : (float) ($so->shipping_cost ?? 0); // shipping_cost SO = net

        $dto = new SalesInvoiceDTO(
            $so->id,
            (int) $so->customer_id,
            (int) $so->warehouse_id,
            now()->toDateString(),
            $so->global_discount_type,
            (float) ($so->global_discount_value ?? 0),
            (float) ($so->ppn_percent ?? 0),
            (float) ($so->pph_percent ?? 0),
            $shippingNet,
            (float) ($so->additional_fee ?? 0),
            0, // advance_applied dihitung ulang dari sales_advances posted
            $so->notes,
            0, // marketplace_fee (POS bukan marketplace)
            $items,
        );

        return DB::transaction(function () use ($so, $dto) {
            // Lock SO + re-cek existence DI DALAM lock: cegah double-invoice dari
            // double-click tombol Proses / proses massal paralel (TOCTOU pada guard
            // exists() di atas yang berjalan tanpa lock).
            SalesOrder::where('id', $so->id)->lockForUpdate()->first();
            // Re-cek di dalam lock (cegah double-invoice dari double-click / proses paralel).
            if (SalesInvoice::where('sales_order_id', $so->id)->whereNotIn('status', ['void'])->exists()) {
                throw new DomainException('Sales Order ini sudah memiliki invoice.');
            }

            if ($so->isPickup()) {
                $so->update(['pickup_status' => 'picked_up', 'picked_up_at' => now()]);
            }

            $invoice = $this->invoiceService->createDraft($dto);

            // Salin detail pengiriman dari SO (mirror DebugInvoiceController::store).
            $invoice->update([
                'delivery_method'         => $so->delivery_method ?: 'kurir',
                'shipping_gross'          => $so->isPickup() ? 0 : $so->shipping_gross,
                'shipping_discount_type'  => $so->shipping_discount_type,
                'shipping_discount_value' => $so->isPickup() ? 0 : $so->shipping_discount_value,
                'shipping_courier_code'   => $so->shipping_courier_code,
                'shipping_service_code'   => $so->shipping_service_code,
                'shipping_service_name'   => $so->shipping_service_name,
                'package_length'          => $so->package_length,
                'package_width'           => $so->package_width,
                'package_height'          => $so->package_height,
            ]);

            // Posting → otomatis bikin/klaim Surat Jalan untuk sisa qty + settle COGS + apply uang muka.
            $this->postingService->post($invoice);

            return $invoice;
        });
    }

    private function productionFinalized(int $soId): bool
    {
        $ops = ProductionOrder::where('sales_order_id', $soId)
            ->whereNotIn('status', ['cancelled'])
            ->pluck('status');
        return $ops->isNotEmpty() && $ops->every(fn ($s) => $s === 'finalized');
    }
}
