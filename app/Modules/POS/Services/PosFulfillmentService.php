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
        if (SalesInvoice::where('sales_order_id', $so->id)->whereNotIn('status', ['void'])->exists()) {
            throw new DomainException('Sales Order ini sudah memiliki invoice.');
        }
        $remaining = round((float) $so->grand_total - (float) $so->paid_amount, 2);
        if ($remaining > 0.01) {
            throw new DomainException('Pesanan belum lunas. Sisa Rp ' . number_format($remaining, 0, ',', '.') . ' harus dibayar dulu.');
        }

        // Custom/preorder: produksi wajib finalized (cegah error COGS mentah saat posting).
        $isCustom = $so->items->contains(fn ($i) => optional($i->product)->sale_type === 'preorder');
        if ($isCustom && !$this->productionFinalized($so->id)) {
            throw new DomainException('Produksi pesanan custom belum selesai (finalisasi OP dulu).');
        }

        // Ambil di toko: verifikasi kode booking.
        if ($so->isPickup()) {
            $code = strtoupper(trim((string) $pickupCode));
            if ($code === '' || $code !== strtoupper((string) $so->pickup_code)) {
                throw new DomainException('Kode pengambilan (booking) salah atau kosong.');
            }
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
            $items,
        );

        return DB::transaction(function () use ($so, $dto) {
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
