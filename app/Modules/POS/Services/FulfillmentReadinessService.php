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

    /**
     * Ambil baris untuk satu bucket (SO + garansi tergabung).
     * @param array{channel?:string,courier?:string} $filters  channel: 'marketplace'|'non'; courier: nama kurir
     */
    public function bucket(string $bucket, ?string $search = null, array $filters = []): Collection
    {
        $rows = $this->soRows()->concat($this->warrantyRows())->concat($this->mpPendingRows())
            ->where('bucket', $bucket)
            ->reject(fn ($r) => $r['archived'] ?? false); // sembunyikan yg sudah dikirim/diambil > 3 hari

        // Filter channel: marketplace vs non-marketplace.
        $channel = $filters['channel'] ?? null;
        if ($channel === 'marketplace') {
            $rows = $rows->filter(fn ($r) => !empty($r['is_marketplace']));
        } elseif ($channel === 'non') {
            $rows = $rows->filter(fn ($r) => empty($r['is_marketplace']));
        }

        // Filter kurir (cocok persis nama kurir/layanan).
        if ($courier = trim((string) ($filters['courier'] ?? ''))) {
            $rows = $rows->filter(fn ($r) => ($r['courier'] ?? null) === $courier);
        }

        // Filter status resi (khusus "Telah Diproses").
        if ($resi = trim((string) ($filters['resi'] ?? ''))) {
            $rows = $rows->filter(fn ($r) => ($r['resi_state'] ?? null) === $resi);
        }

        if ($search = trim((string) $search)) {
            $needle = mb_strtolower($search);
            $rows = $rows->filter(fn ($r) =>
                str_contains(mb_strtolower($r['number']), $needle) ||
                str_contains(mb_strtolower($r['customer']), $needle));
        }

        return $rows->sortByDesc('date_sort')->values();
    }

    /** Daftar kurir unik yang ada di sebuah bucket (untuk dropdown filter). */
    public function courierOptions(string $bucket): Collection
    {
        return $this->soRows()->concat($this->warrantyRows())->concat($this->mpPendingRows())
            ->where('bucket', $bucket)
            ->reject(fn ($r) => $r['archived'] ?? false)
            ->pluck('courier')
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }

    /** Hitung jumlah per-bucket (untuk badge tab). Baris terarsip tidak dihitung. */
    public function counts(): array
    {
        $all = $this->soRows()->concat($this->warrantyRows())->concat($this->mpPendingRows())
            ->reject(fn ($r) => $r['archived'] ?? false);
        return [
            'belum_siap'     => $all->where('bucket', 'belum_siap')->count(),
            'perlu_diproses' => $all->where('bucket', 'perlu_diproses')->count(),
            'telah_diproses' => $all->where('bucket', 'telah_diproses')->count(),
            'dikirim'        => $all->where('bucket', 'dikirim')->count(),
            'selesai'        => $all->where('bucket', 'selesai')->count(),
            'pembatalan'     => $this->pembatalanQuery()->count(),
        ];
    }

    // ───────────── Pembatalan (marketplace) ─────────────

    /** Query dasar: link marketplace yang diminta batal pembeli ATAU SO-nya sudah di-void/cancel. */
    private function pembatalanQuery()
    {
        return \App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink::query()
            ->whereNotNull('sales_order_id')
            ->where(function ($q) {
                $q->where('cancel_requested', true)
                  ->orWhere('last_status', 'canceled')   // dibatalkan di Jubelio (auto-void / perlu manual)
                  ->orWhereHas('salesOrder', fn ($s) => $s->whereIn('status', ['void', 'cancelled']));
            });
    }

    /**
     * Baris untuk tab "Pembatalan": pesanan marketplace yang (a) pembeli minta batal,
     * atau (b) SO-nya sudah di-void/cancel. Opsional filter pencarian.
     */
    public function pembatalanRows(?string $search = null): Collection
    {
        $links = $this->pembatalanQuery()
            ->with(['salesOrder' => fn ($q) => $q->with('customer:id,name,phone')])
            ->get();

        $rows = $links->map(function ($link) {
            $so = $link->salesOrder;
            if (!$so) {
                return null;
            }
            $isVoid = in_array($so->status, ['void', 'cancelled'], true);
            // State: void (sudah dibatalkan) > jubelio_canceled (batal di Jubelio, SO masih aktif
            // → perlu void manual) > requested (pembeli minta batal).
            $state = $isVoid ? 'void' : ($link->last_status === 'canceled' ? 'jubelio_canceled' : 'requested');

            return [
                'id'             => $so->id,
                'number'         => $so->order_number,
                'jubelio_no'     => $link->jubelio_salesorder_no,
                'customer'       => $so->customer->name ?? '-',
                'phone'          => $so->customer->phone ?? null,
                'channel'        => $link->store ?: 'Marketplace',
                'grand_total'    => (float) $so->grand_total,
                'date'           => $so->order_date,
                'date_sort'      => (string) ($link->cancel_requested_at ?? $so->updated_at ?? $so->order_date ?? $so->created_at),
                'state'          => $state,
                'cancel_reason'  => $state === 'jubelio_canceled' ? ($link->last_error ?: 'Dibatalkan di Jubelio') : $link->cancel_reason,
                'requested_at'   => $link->cancel_requested_at,
                'invoice_posted' => (bool) $link->invoice_posted,
                'sj_created'     => (bool) $link->sj_created,
            ];
        })->filter();

        if ($search = trim((string) $search)) {
            $needle = mb_strtolower($search);
            $rows = $rows->filter(fn ($r) =>
                str_contains(mb_strtolower($r['number']), $needle) ||
                str_contains(mb_strtolower((string) $r['jubelio_no']), $needle) ||
                str_contains(mb_strtolower($r['customer']), $needle));
        }

        return $rows->sortByDesc('date_sort')->values();
    }

    // ───────────── Sales Order ─────────────

    private function soRows(): Collection
    {
        if ($this->soRows !== null) return $this->soRows;

        // SO toko biasa (non-marketplace) SEPERTI biasa, DITAMBAH SO marketplace yang punya
        // link Jubelio (dibuat oleh sinkron pesanan) agar bisa diproses via rantai WMS.
        $linkedSoIds = \App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink::whereNotNull('sales_order_id')
            ->pluck('sales_order_id')->all();

        $orders = SalesOrder::query()
            ->where('status', 'confirmed')
            ->where(function ($q) use ($linkedSoIds) {
                $q->whereHas('customer', fn ($c) => $c->where('is_marketplace', false));
                if ($linkedSoIds) {
                    $q->orWhereIn('id', $linkedSoIds);
                }
            })
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

        // Link Jubelio per-SO (untuk bucketing & badge channel pesanan marketplace).
        $linksBySo = \App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink::whereIn('sales_order_id', $soIds)
            ->get()->keyBy('sales_order_id');

        $this->soRows = $orders->map(function (SalesOrder $so) use ($prodBySo, $linksBySo) {
            return $this->buildSoRow($so, $prodBySo->get($so->id), $linksBySo->get($so->id));
        });

        return $this->soRows;
    }

    private function buildSoRow(SalesOrder $so, ?Collection $prodOrders, ?\App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink $link = null): array
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

        // Faktur DRAFT (belum diposting) dianggap TIDAK ADA di fulfillment — SO tetap
        // diklasifikasi normal sesuai kesiapan. HANYA faktur DIPOSTING yang menandai
        // SO "telah diproses". (Saat Proses, faktur draft yang ada akan diposting.)
        $invStatus = fn ($i) => $i->status instanceof \App\Enums\InvoiceStatusEnum ? $i->status->value : (string) $i->status;
        $postedInvoice = $so->invoices->first(fn ($i) => $invStatus($i) === 'posted');

        // Bucket (urut, mutually exclusive)
        if ($link) {
            //  completed (invoice_posted)                  → Selesai
            //  KITA proses (awb_requested): cetak resi     → Telah Diproses; resi dicetak + H+1 → Dikirim.
            //     (status Jubelio menyala terlalu dini, jadi pesanan yang kita proses sendiri
            //      pakai jejak cetak resi, bukan status Jubelio.)
            //  diproses langsung di Jubelio:
            //     last_status 'shipped' (benar-benar diserahkan ke kurir)  → Dikirim.
            //     resi terbit tapi belum diserahkan ('processed' / sj_created) → Telah Diproses.
            //  sudah dibayar (DP)                          → Perlu Diproses.
            if ($link->invoice_posted) {
                $bucket = 'selesai';
            } elseif ($link->awb_requested) {
                $bucket = $this->mpHandedOver($link) ? 'dikirim' : 'telah_diproses';
            } elseif ($link->last_status === 'shipped') {
                $bucket = 'dikirim';
            } elseif ($link->last_status === 'processed' || $link->sj_created) {
                $bucket = 'telah_diproses';
            } elseif ($link->dp_posted) {
                $bucket = 'perlu_diproses';
            } else {
                $bucket = 'belum_siap';
            }
        } elseif ($postedInvoice) {
            // Non-marketplace sudah diproses: masih perlu generate resi → telah diproses;
            // sudah ber-resi / tak perlu resi (ambil toko, kurir manual) → selesai.
            $bucket = $this->shipmentFinalized($so) ? 'selesai' : 'telah_diproses';
        } elseif ((!$isCustom && $hasPayment) || ($isCustom && $prodFinalized)) {
            $bucket = 'perlu_diproses';
        } else {
            $bucket = 'belum_siap';
        }

        // Arsip: pesanan yang SUDAH SELESAI > 3 hari lalu disembunyikan dari tab "Selesai"
        // (dokumen tetap dapat diakses via modul Sales). "Telah Diproses" = status antara,
        // tidak diarsipkan agar yang menunggu resi/penyelesaian selalu terlihat.
        $archived = false;
        if ($bucket === 'selesai') {
            $invDate = $postedInvoice
                ? \Carbon\Carbon::parse($postedInvoice->invoice_date ?? $postedInvoice->created_at)
                : null;
            $completedAt = $link
                ? ($link->wms_completed_at ?? $invDate)
                : ($this->shippedAt($so) ?? $invDate);
            $archived = $completedAt !== null && $completedAt->lt(now()->subDays(3));
        }

        // Alasan (belum_siap)
        $reason = null;
        if ($bucket === 'belum_siap') {
            if ($link) {
                $reason = 'Menunggu pembayaran marketplace';
            } elseif ($isCustom && !$prodFinalized) {
                $reason = 'Produksi belum selesai';
            } elseif (!$hasPayment) {
                $reason = 'Menunggu pembayaran / DP';
            } else {
                $reason = 'Belum siap diproses';
            }
        }

        $deliveryDisplay = $so->isPickup()
            ? 'Ambil di Toko'
            : ($so->shipping_service_name ?: ($so->shipping_courier_code ? strtoupper($so->shipping_courier_code) : $so->deliveryMethodLabel()));

        // Status resi (untuk filter di "Telah Diproses"): belum_generate / belum_cetak / sudah_cetak.
        $resiState = null;
        if ($bucket === 'telah_diproses') {
            $resiState = $link ? $this->mpResiState($link) : $this->nonMpResiState($so);
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
            'delivery_display' => $deliveryDisplay,
            // Kurir untuk filter: marketplace pakai nama shipper Jubelio bila ada.
            'courier'       => $link ? ($link->shipper ?: 'Marketplace') : $deliveryDisplay,
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
            'invoice'     => $postedInvoice,
            'deliveries'  => $so->deliveries,

            // Status resi (filter "Telah Diproses") + tanda cetak
            'resi_state'     => $resiState,
            'resi_printed'   => $link ? (bool) $link->resi_printed_at : $this->nonMpAllPrinted($so),

            // Marketplace (rantai WMS Jubelio)
            'is_marketplace' => (bool) $link,
            'channel'        => $link ? ($link->store ?: 'Marketplace') : null,
            'wms_stage'      => $link?->wmsStage(),
            'wms_stage_label'=> $link?->wmsStageLabel(),
            'wms_error'      => $link?->wms_last_error,
            'tracking_no'    => $link?->tracking_no,
            'shipper'        => $link?->shipper,
            'j_is_instant'   => (bool) ($link?->is_instant_courier),
            'j_link'         => $link,
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

    /**
     * Pengiriman SO (non-marketplace) sudah final? → masuk "Selesai", bukan "Telah Diproses".
     *  - Ambil di toko / kurir manual (tak perlu generate resi) → final saat diproses.
     *  - Kurir Biteship → final hanya bila resi sudah di-generate, sudah DICETAK, dan dicetak
     *    sebelum hari ini (pindah ke Selesai mulai hari berikutnya — masih bisa cetak ulang
     *    di hari yang sama). Selaras dengan "00:01 hari berikutnya masuk Selesai".
     */
    private function shipmentFinalized(SalesOrder $so): bool
    {
        if ($so->isPickup()) {
            return true;
        }
        $ship = $so->deliveries->filter(fn ($d) => $d->status === 'posted' && $d->delivery_method !== 'ambil_toko');
        if ($ship->isEmpty()) {
            return false;
        }
        $today = now()->startOfDay();
        foreach ($ship as $d) {
            if (\App\Models\ManualCourier::isManualCode($d->shipping_courier_code)) {
                continue; // kurir manual: tak perlu resi
            }
            if (empty($d->tracking_number)) return false;                        // belum di-generate
            if (empty($d->resi_printed_at)) return false;                        // belum dicetak
            if (\Carbon\Carbon::parse($d->resi_printed_at)->gte($today)) return false; // dicetak hari ini → tunggu H+1
        }

        return true;
    }

    /** Status resi marketplace (untuk filter): belum_generate / belum_cetak / sudah_cetak. */
    private function mpResiState(\App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink $link): string
    {
        return empty($link->tracking_no) ? 'belum_generate'
            : (empty($link->resi_printed_at) ? 'belum_cetak' : 'sudah_cetak');
    }

    /** Status resi non-marketplace (Biteship) untuk filter "Telah Diproses". */
    private function nonMpResiState(SalesOrder $so): ?string
    {
        $ship = $so->deliveries->filter(fn ($d) =>
            $d->status === 'posted' && $d->delivery_method !== 'ambil_toko'
            && !\App\Models\ManualCourier::isManualCode($d->shipping_courier_code));
        if ($ship->isEmpty()) {
            return 'belum_generate'; // belum ada SJ kurir API → masih perlu generate
        }
        if ($ship->contains(fn ($d) => empty($d->tracking_number)))     return 'belum_generate';
        if ($ship->contains(fn ($d) => empty($d->resi_printed_at)))     return 'belum_cetak';
        return 'sudah_cetak';
    }

    /** Semua SJ kurir API non-marketplace sudah dicetak? (untuk badge "sudah dicetak"). */
    private function nonMpAllPrinted(SalesOrder $so): bool
    {
        $ship = $so->deliveries->filter(fn ($d) =>
            $d->status === 'posted' && $d->delivery_method !== 'ambil_toko'
            && !\App\Models\ManualCourier::isManualCode($d->shipping_courier_code));
        return $ship->isNotEmpty() && $ship->every(fn ($d) => !empty($d->resi_printed_at));
    }

    /**
     * Marketplace dianggap sudah diserahkan ke kurir → "Dikirim" mulai H+1 setelah resi
     * DICETAK. Resi dicetak hari ini tetap di "Telah Diproses" (masih bisa cetak ulang /
     * batal serah). Tidak memakai status "shipped" Jubelio karena menyala terlalu dini.
     */
    private function mpHandedOver(\App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink $link): bool
    {
        return $link->resi_printed_at && $link->resi_printed_at->lt(now()->startOfDay());
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
                'shipped'  => 'selesai',       // garansi terkirim = selesai
                'repaired' => 'perlu_diproses',
                default    => 'belum_siap', // received | posted
            };

            // Garansi yang sudah dikirim > 3 hari lalu diarsipkan dari "Selesai".
            $archived = $bucket === 'selesai'
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
                'is_marketplace' => false,
                'courier'        => null,
            ];
        });

        return $this->warrantyRows;
    }

    // ───────────── Pesanan marketplace belum jadi SO (belum dibayar) ─────────────

    private ?Collection $mpPendingRows = null;

    /**
     * Kartu info pesanan marketplace yang BELUM jadi SO ERP (belum dibayar / gagal resolve
     * item). Ditarik dari link Jubelio bersnapshot, sales_order_id masih kosong & belum
     * dibatalkan. Read-only — masuk bucket "belum_siap" agar tim aware ada order masuk.
     */
    private function mpPendingRows(): Collection
    {
        if ($this->mpPendingRows !== null) return $this->mpPendingRows;

        $links = \App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink::query()
            ->whereNull('sales_order_id')
            ->where('last_status', '!=', 'canceled')
            ->get();

        $this->mpPendingRows = $links->map(function ($link) {
            $reason = $link->last_error ?: 'Menunggu pembayaran marketplace';

            return [
                'kind'        => 'mp_pending',
                'id'          => $link->id,
                'number'      => $link->jubelio_salesorder_no ?: ('JBL-' . $link->jubelio_salesorder_id),
                'customer'    => $link->snap_customer ?: '-',
                'date'        => $link->snap_order_date,
                'date_sort'   => (string) ($link->snap_order_date ?? $link->updated_at ?? $link->created_at),
                'bucket'      => 'belum_siap',
                'reason'      => $reason,
                'archived'    => false,
                'is_marketplace' => true,
                'channel'        => $link->store ?: 'Marketplace',
                'courier'        => $link->shipper ?: 'Marketplace',
                'grand_total'    => (float) ($link->snap_grand_total ?? 0),
                'item_count'     => (int) ($link->snap_item_count ?? 0),
                'jubelio_no'     => $link->jubelio_salesorder_no,
            ];
        });

        return $this->mpPendingRows;
    }
}
