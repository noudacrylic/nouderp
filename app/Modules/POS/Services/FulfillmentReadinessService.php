<?php

namespace App\Modules\POS\Services;

use App\Models\WarrantyOrder;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Collection;

/**
 * Klasifikasi Sales Order + Garansi (non-marketplace) ke 3 bucket pemrosesan:
 *   belum_siap | perlu_diproses | telah_diproses.
 *
 * Dimuat sekali per-request (memoized), klasifikasi di PHP dari relasi yang sudah di-eager-load
 * (hindari subquery per-baris dari getInvoiceStatus/getDeliveryStatus/getTotalAdvancePaid).
 * Uang yang sudah dibayar = `sales_orders.paid_amount` (kanonik di seluruh app; paid_amount &
 * sales_advances merepresentasikan uang yang sama — jangan dijumlah).
 */
class FulfillmentReadinessService
{
    private ?Collection $soRows = null;
    private ?Collection $warrantyRows = null;

    /** Ambil baris untuk satu bucket (SO + garansi tergabung), opsional filter pencarian. */
    public function bucket(string $bucket, ?string $search = null): Collection
    {
        $rows = $this->soRows()->concat($this->warrantyRows())
            ->where('bucket', $bucket)
            ->reject(fn ($r) => $r['archived'] ?? false); // sembunyikan yg sudah dikirim/diambil > 3 hari

        if ($search = trim((string) $search)) {
            $needle = mb_strtolower($search);
            $rows = $rows->filter(fn ($r) =>
                str_contains(mb_strtolower($r['number']), $needle) ||
                str_contains(mb_strtolower($r['customer']), $needle));
        }

        return $rows->sortByDesc('date_sort')->values();
    }

    /** Hitung jumlah per-bucket (untuk badge tab). Baris terarsip tidak dihitung. */
    public function counts(): array
    {
        $all = $this->soRows()->concat($this->warrantyRows())
            ->reject(fn ($r) => $r['archived'] ?? false);
        return [
            'belum_siap'     => $all->where('bucket', 'belum_siap')->count(),
            'perlu_diproses' => $all->where('bucket', 'perlu_diproses')->count(),
            'telah_diproses' => $all->where('bucket', 'telah_diproses')->count(),
        ];
    }

    // ───────────── Sales Order ─────────────

    private function soRows(): Collection
    {
        if ($this->soRows !== null) return $this->soRows;

        $orders = SalesOrder::query()
            ->where('status', 'confirmed')
            ->whereHas('customer', fn ($q) => $q->where('is_marketplace', false))
            ->with([
                'customer:id,name,is_marketplace,phone,address,shipping_address,city',
                'items', 'items.product:id,name,sku,sale_type,lead_time_days',
                'deliveries' => fn ($q) => $q->where('status', '!=', 'void'),
                'deliveries.items',
                'invoices' => fn ($q) => $q->where('status', '!=', 'void'),
            ])
            ->get();

        // Produksi finalized per-SO dalam 1 query.
        $soIds = $orders->pluck('id')->all();
        $prodBySo = ProductionOrder::whereIn('sales_order_id', $soIds)
            ->whereNotIn('status', ['cancelled'])
            ->get(['sales_order_id', 'status'])
            ->groupBy('sales_order_id');

        $this->soRows = $orders->map(function (SalesOrder $so) use ($prodBySo) {
            return $this->buildSoRow($so, $prodBySo->get($so->id));
        });

        return $this->soRows;
    }

    private function buildSoRow(SalesOrder $so, ?Collection $prodOrders): array
    {
        $grand    = (float) $so->grand_total;
        $paid     = (float) $so->paid_amount;
        $remaining = max(0, round($grand - $paid, 2));
        $isLunas  = $remaining <= 0.01;
        $hasPayment = $paid > 0.01;

        $isCustom = $so->items->contains(fn ($i) => optional($i->product)->sale_type === 'preorder');

        // Batas waktu kirim: ready stock = order_date + 1 hari; preorder = order_date +
        // lead_time_days terbesar dari item preorder (minimal 1 hari).
        $leadDays = 1;
        if ($isCustom) {
            foreach ($so->items as $i) {
                if (optional($i->product)->sale_type === 'preorder') {
                    $leadDays = max($leadDays, (int) ($i->product->lead_time_days ?? 0));
                }
            }
        }
        $deadline  = $so->order_date ? \Carbon\Carbon::parse($so->order_date)->addDays($leadDays) : null;
        $isOverdue = $deadline ? $deadline->lt(\Carbon\Carbon::today()) : false;

        // Finalized: ada minimal 1 OP non-cancelled & SEMUA finalized.
        $prodFinalized = false;
        if ($prodOrders && $prodOrders->isNotEmpty()) {
            $prodFinalized = $prodOrders->every(fn ($p) => $p->status === 'finalized');
        }

        $hasInvoice = $so->invoices->isNotEmpty();

        // Bucket (urut, mutually exclusive)
        if ($hasInvoice) {
            $bucket = 'telah_diproses';
        } elseif ((!$isCustom && $hasPayment) || ($isCustom && $prodFinalized)) {
            $bucket = 'perlu_diproses';
        } else {
            $bucket = 'belum_siap';
        }

        // Arsip: pesanan yang sudah dikirim (resi tergenerate) / sudah diambil > 3 hari lalu
        // disembunyikan dari "Telah Diproses" (dokumen tetap dapat diakses via modul Sales).
        $archived = false;
        if ($bucket === 'telah_diproses') {
            $shippedAt = $this->shippedAt($so);
            $archived = $shippedAt !== null && $shippedAt->lt(now()->subDays(3));
        }

        // Alasan (belum_siap)
        $reason = null;
        if ($bucket === 'belum_siap') {
            if ($isCustom && !$prodFinalized) {
                $reason = 'Produksi belum selesai';
            } elseif (!$hasPayment) {
                $reason = 'Menunggu pembayaran / DP';
            } else {
                $reason = 'Belum siap diproses';
            }
        }

        return [
            'kind'        => 'so',
            'id'          => $so->id,
            'number'      => $so->order_number,
            'customer'    => $so->customer->name ?? '-',
            'customer_id' => $so->customer_id,
            'date'        => $so->order_date,
            'date_sort'   => (string) ($so->order_date ?? $so->created_at),
            'bucket'      => $bucket,
            'reason'      => $reason,
            'archived'    => $archived,

            'notes'         => $so->notes,
            'seller_notes'  => $so->seller_notes,
            'phone'         => $so->customer->phone ?? null,
            'address'       => $this->shortAddress($so->customer),
            'delivery_display' => $so->isPickup()
                ? 'Ambil di Toko'
                : ($so->shipping_service_name ?: ($so->shipping_courier_code ? strtoupper($so->shipping_courier_code) : $so->deliveryMethodLabel())),
            'deadline'     => $deadline,
            'is_overdue'   => $isOverdue,

            'delivery_method' => $so->delivery_method,
            'delivery_label'  => $so->deliveryMethodLabel(),
            'is_pickup'       => $so->isPickup(),
            'pickup_code'     => $so->pickup_code,
            'pickup_status'   => $so->pickup_status,
            'pickup_date'     => $so->pickup_date,

            'grand_total' => $grand,
            'paid'        => $paid,
            'remaining'   => $remaining,
            'is_lunas'    => $isLunas,
            'is_custom'   => $isCustom,

            'delivery'    => $this->deliveryBreakdown($so),
            'invoice'     => $hasInvoice ? $so->invoices->first() : null,
            'deliveries'  => $so->deliveries,
        ];
    }

    /**
     * Waktu pesanan dianggap "terkirim" untuk SO yang sudah selesai diproses, atau null bila
     * belum: ambil-toko → picked_up_at; kurir → tanggal terbaru SJ posted bila SEMUA SJ
     * kurir sudah punya nomor resi (resi tergenerate).
     */
    private function shippedAt(SalesOrder $so): ?\Carbon\Carbon
    {
        if ($so->isPickup()) {
            return ($so->pickup_status === 'picked_up' && $so->picked_up_at)
                ? \Carbon\Carbon::parse($so->picked_up_at) : null;
        }

        $ship = $so->deliveries->filter(fn ($d) => $d->status === 'posted' && $d->delivery_method !== 'ambil_toko');
        if ($ship->isEmpty()) return null;
        if ($ship->contains(fn ($d) => empty($d->tracking_number))) return null; // belum semua resi tergenerate

        return $ship
            ->map(fn ($d) => \Carbon\Carbon::parse($d->delivery_date ?? $d->updated_at ?? $d->created_at))
            ->max();
    }

    /** Alamat ringkas customer untuk kartu (shipping_address diutamakan), + kota. */
    private function shortAddress(?\App\Models\Customer $customer): string
    {
        if (!$customer) return '';
        $base = trim((string) ($customer->shipping_address ?: $customer->address));
        $city = trim((string) ($customer->city ?? ''));
        return trim($base . ($city ? ($base ? ', ' : '') . $city : ''));
    }

    /**
     * Rincian kirim per-baris SO (untuk panel "sudah dikirim vs belum") dari data ter-load.
     * shipped per sales_order_item_id = Σ qty delivery item; expected = qty × conversion_to_base.
     */
    private function deliveryBreakdown(SalesOrder $so): array
    {
        $deliveredByItem = [];
        foreach ($so->deliveries as $d) {
            foreach ($d->items as $it) {
                $key = $it->sales_order_item_id;
                $deliveredByItem[$key] = ($deliveredByItem[$key] ?? 0) + (float) $it->qty;
            }
        }

        $lines = [];
        $anyShipped = false;
        $allFull = true;
        foreach ($so->items as $si) {
            $product = $si->product;
            if ($product && in_array($product->sale_type ?? null, ['service', 'non_stock'], true)) {
                continue; // jasa/non-stok tidak dikirim
            }
            $expected = (float) $si->qty * (float) ($si->conversion_to_base ?? 1);
            $shipped  = (float) ($deliveredByItem[$si->id] ?? 0);
            $remaining = max(0, round($expected - $shipped, 4));
            if ($shipped > 0.0001) $anyShipped = true;
            if ($remaining > 0.0001) $allFull = false;

            $lines[] = [
                'name'      => $si->description ?: ($product->name ?? '-'),
                'sku'       => $product->sku ?? null,
                'ordered'   => $expected,
                'shipped'   => $shipped,
                'remaining' => $remaining,
            ];
        }

        $status = empty($lines) ? 'terkirim' : (!$anyShipped ? 'belum' : ($allFull ? 'terkirim' : 'partial'));

        return ['status' => $status, 'lines' => $lines];
    }

    // ───────────── Garansi ─────────────

    private function warrantyRows(): Collection
    {
        if ($this->warrantyRows !== null) return $this->warrantyRows;

        $warranties = WarrantyOrder::query()
            ->whereIn('status', ['received', 'posted', 'repaired', 'shipped'])
            ->whereHas('customer', fn ($q) => $q->where('is_marketplace', false))
            ->with(['customer:id,name,is_marketplace', 'delivery'])
            ->get();

        $this->warrantyRows = $warranties->map(function (WarrantyOrder $w) {
            $bucket = match ($w->status) {
                'shipped'  => 'telah_diproses',
                'repaired' => 'perlu_diproses',
                default    => 'belum_siap', // received | posted
            };

            // Garansi yang sudah dikirim > 3 hari lalu diarsipkan dari "Telah Diproses".
            $archived = $bucket === 'telah_diproses'
                && $w->status === 'shipped'
                && \Carbon\Carbon::parse($w->updated_at)->lt(now()->subDays(3));

            return [
                'kind'         => 'garansi',
                'id'           => $w->id,
                'number'       => $w->warranty_number,
                'customer'     => $w->customer->name ?? '-',
                'date'         => $w->warranty_date,
                'date_sort'    => (string) ($w->warranty_date ?? $w->created_at),
                'bucket'       => $bucket,
                'reason'       => $bucket === 'belum_siap' ? 'Belum selesai diperbaiki' : null,
                'archived'     => $archived,
                'status'       => $w->status,
                'status_label' => $w->status_label,
                'delivery'     => $w->delivery,
            ];
        });

        return $this->warrantyRows;
    }
}
