<?php

namespace App\Modules\POS\Services;

use App\Models\WarrantyOrder;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
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
            $rows = $rows->filter(function ($r) use ($needle) {
                if (str_contains(mb_strtolower((string) ($r['number'] ?? '')), $needle)) return true;
                if (str_contains(mb_strtolower((string) ($r['customer'] ?? '')), $needle)) return true;
                // Cocokkan juga nama produk / SKU pada baris pesanan (khusus SO; baris garansi/
                // marketplace-pending tak punya rincian produk).
                $lines = is_array($r['delivery'] ?? null) ? ($r['delivery']['lines'] ?? []) : [];
                foreach ($lines as $ln) {
                    if (str_contains(mb_strtolower((string) ($ln['name'] ?? '')), $needle)) return true;
                    if (str_contains(mb_strtolower((string) ($ln['sku'] ?? '')), $needle)) return true;
                }
                return false;
            });
        }

        // Terbaru di atas. order_date sering tanggal-saja (tanpa jam) → pesanan setanggal
        // seri; pakai id menurun sebagai tiebreaker (id lebih besar = dibuat lebih baru).
        return $rows->sortByDesc(fn ($r) => [(string) $r['date_sort'], $r['id'] ?? 0])->values();
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
            // Retur pembeli yang perlu ditindak (cek barang & post draft retur).
            'retur'          => $all->where('bucket', 'retur')->count(),
            // Badge HANYA menghitung pembatalan yang masih perlu ditindak operator (batal di
            // marketplace / pembeli minta batal TAPI SO ERP belum di-void) — termasuk yang
            // butuh persetujuan seller di Seller Center. Pembatalan yang sudah di-void
            // (state 'void') = selesai → tidak ikut dihitung.
            'pembatalan'     => $this->pembatalanActionableQuery()->count(),
        ];
    }

    // ───────────── Pembatalan (marketplace) ─────────────

    /**
     * Pembatalan yang masih AKTIF (perlu ditindak): batal di marketplace (last_status
     * 'canceled') atau pembeli minta batal (cancel_requested) TAPI SO ERP belum di-void/cancel.
     * Ini kelas yang sering butuh aksi seller (terima/tolak di Seller Center) + void manual.
     */
    private function pembatalanActionableQuery()
    {
        return \App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink::query()
            ->whereNotNull('sales_order_id')
            ->where(fn ($q) => $q->where('cancel_requested', true)->orWhere('last_status', 'canceled'))
            ->whereHas('salesOrder', fn ($s) => $s->whereNotIn('status', ['void', 'cancelled']));
    }

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
            ->with(['salesOrder' => fn ($q) => $q->with(['customer:id,name,phone', 'items.product:id,name,sku'])])
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
                // Teks produk (nama + SKU) untuk pencarian.
                'product_search' => $so->items->map(fn ($si) =>
                    trim(($si->description ?: ($si->product->name ?? '')) . ' ' . ($si->product->sku ?? '')))
                    ->implode(' | '),
            ];
        })->filter();

        if ($search = trim((string) $search)) {
            $needle = mb_strtolower($search);
            $rows = $rows->filter(fn ($r) =>
                str_contains(mb_strtolower($r['number']), $needle) ||
                str_contains(mb_strtolower((string) $r['jubelio_no']), $needle) ||
                str_contains(mb_strtolower($r['customer']), $needle) ||
                str_contains(mb_strtolower((string) $r['product_search']), $needle));
        }

        // Urutan: pembatalan yang masih perlu ditindak (jubelio_canceled / requested — sering
        // butuh persetujuan seller di Seller Center) di PALING ATAS agar mudah diproses operator;
        // pembatalan yang sudah di-void (selesai) di bawah. Dalam tiap grup: terbaru di atas
        // (tiebreaker id menurun untuk pesanan setanggal, lihat bucket()).
        $rows = $rows->sortByDesc(fn ($r) => [(string) $r['date_sort'], $r['id'] ?? 0])->values();
        $actionable = $rows->filter(fn ($r) => $r['state'] !== 'void')->values();
        $resolved   = $rows->filter(fn ($r) => $r['state'] === 'void')->values();
        return $actionable->concat($resolved)->values();
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

        // Retur per-SO (untuk tab "Retur": kartu link ke halaman edit draft retur). Terbaru dulu.
        $returnsBySo = SalesReturn::whereIn('sales_order_id', $soIds)
            ->orderByDesc('id')
            ->get()->groupBy('sales_order_id');

        $this->soRows = $orders->map(function (SalesOrder $so) use ($prodBySo, $linksBySo, $returnsBySo) {
            $returns = $returnsBySo->get($so->id);
            // Prioritaskan draft (yang masih perlu ditindak); jatuh ke retur terbaru bila semua sudah di-post.
            $retur   = $returns?->firstWhere('status', 'draft') ?? $returns?->first();
            return $this->buildSoRow($so, $prodBySo->get($so->id), $linksBySo->get($so->id), $retur);
        });

        return $this->soRows;
    }

    private function buildSoRow(SalesOrder $so, ?Collection $prodOrders, ?\App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink $link = null, ?SalesReturn $retur = null): array
    {
        $grand    = (float) $so->grand_total;
        $paid     = (float) $so->paid_amount;
        $remaining = max(0, round($grand - $paid, 2));
        $isLunas  = $remaining <= 0.01;
        $hasPayment = $paid > 0.01;

        $isCustom = $so->items->contains(fn ($i) => optional($i->product)->sale_type === 'preorder');

        // Batas waktu kirim. Order marketplace: pakai batas kirim ASLI dari Jubelio (due_date),
        // bukan estimasi lokal. Lainnya: ready stock = order_date + 1 hari; preorder = order_date +
        // lead_time_days terbesar dari item preorder (minimal 1 hari).
        if ($link && $link->mp_due_date) {
            $deadline  = $link->mp_due_date;
            $isOverdue = $deadline->isPast();
        } else {
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
        }

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
            //  last_status 'shipped' (benar-benar diserahkan ke kurir per Jubelio) → Dikirim.
            //     Berlaku baik untuk order yang kita proses sendiri (awb_requested) maupun
            //     yang diproses langsung di Jubelio — pemicu Dikirim = serah ke jasa kirim,
            //     bukan lagi heuristik H+1 cetak resi.
            //  resi/AWB sudah ada tapi belum diserahkan (awb_requested / 'processed' /
            //     sj_created) → Telah Diproses.
            //  sudah dibayar (DP)                          → Perlu Diproses.
            // Retur pembeli didahulukan: order diretur (Jubelio RETURNED) atau sudah dibuatkan
            // draft retur → tab "Retur", KELUAR dari "Telah Diproses". Cek return_created
            // menangkap order yang last_status-nya belum ter-refresh dari cron. Begitu draft
            // retur DI-POST → tuntas, pindah ke "Selesai" (badge "Retur" ikut self-clearing).
            $returPosted = $retur && $retur->status === 'posted';
            $isReturn    = $link->last_status === 'returned' || $link->return_created;
            if ($isReturn && !$returPosted) {
                $bucket = 'retur';
            } elseif ($isReturn) {
                $bucket = 'selesai';
            } elseif ($link->invoice_posted) {
                $bucket = 'selesai';
            } elseif ($link->last_status === 'shipped') {
                $bucket = 'dikirim';
            } elseif ($link->awb_requested || $link->last_status === 'processed' || $link->sj_created) {
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

            // Gagal proses: marketplace pakai jejak WMS Jubelio; non-marketplace pakai
            // kolom process_error SO (di-set saat Proses/Proses Massal gagal, dibersihkan saat sukses).
            'process_failed' => $link ? !empty($link->wms_last_error) : !empty($so->process_error),
            'process_error'  => $link ? $link->wms_last_error : $so->process_error,

            'delivery'    => $this->deliveryBreakdown($so),
            'invoice'     => $postedInvoice,
            'deliveries'  => $so->deliveries,

            // Retur (tab "Retur"): draft/terbaru untuk di-link ke halaman edit retur.
            'retur_id'     => $retur?->id,
            'retur_number' => $retur?->return_number,
            'retur_status' => $retur?->status,

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
        $anyShippable = false;
        foreach ($so->items as $si) {
            $product = $si->product;
            // Jasa/non-stok tetap ditampilkan agar lengkap, tapi tidak dihitung
            // sebagai barang yang harus dikirim (tak memengaruhi status pengiriman).
            $isService = $product && in_array($product->sale_type ?? null, ['service', 'non_stock'], true);
            $expected = (float) $si->qty * (float) ($si->conversion_to_base ?? 1);
            if ($isService) {
                $lines[] = [
                    'name'       => $si->description ?: ($product->name ?? '-'),
                    'sku'        => $product->sku ?? null,
                    'ordered'    => $expected,
                    'shipped'    => 0.0,
                    'remaining'  => 0.0,
                    'is_service' => true,
                ];
                continue;
            }
            $anyShippable = true;
            $shipped  = (float) ($deliveredByItem[$si->id] ?? 0);
            $remaining = max(0, round($expected - $shipped, 4));
            if ($shipped > 0.0001) $anyShipped = true;
            if ($remaining > 0.0001) $allFull = false;

            $lines[] = [
                'name'       => $si->description ?: ($product->name ?? '-'),
                'sku'        => $product->sku ?? null,
                'ordered'    => $expected,
                'shipped'    => $shipped,
                'remaining'  => $remaining,
                'is_service' => false,
            ];
        }

        $status = !$anyShippable ? 'terkirim' : (!$anyShipped ? 'belum' : ($allFull ? 'terkirim' : 'partial'));

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
