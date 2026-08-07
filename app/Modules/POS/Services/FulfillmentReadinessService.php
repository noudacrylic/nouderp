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
    /**
     * Status kurir (internal, dari peta JubelioShipmentProvider) yang berarti paketnya SUDAH
     * di tangan kurir. Begitu salah satunya masuk, kartu tak perlu lagi menebak dari cetak resi.
     */
    private const STATUS_SUDAH_JALAN = ['picked_up', 'in_transit', 'delivered', 'returned'];

    private ?Collection $soRows = null;
    private ?Collection $warrantyRows = null;

    /** Kekurangan stok per sales_order_id — diisi rowsFor(), dibaca buildSoRow(). */
    private array $shortageMap = [];

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

        // Hanya pesanan prioritas — kurir instant ATAU ambil di toko (chip cepat di toolbar).
        // Keduanya sama-sama ada orang yang menunggu di tempat, bukan SLA harian.
        if (!empty($filters['prioritas'])) {
            $rows = $rows->filter(fn ($r) => !empty($r['is_urgent']));
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
        $rows = $rows->sortByDesc(fn ($r) => [(string) $r['date_sort'], $r['id'] ?? 0])->values();

        // Pesanan prioritas naik ke paling atas: ada orang menunggu di tempat, bukan SLA harian.
        // Urutannya kurir instant dulu (driver sudah di jalan), lalu ambil di toko (pembeli
        // menunggu di depan), baru sisanya. Di dalam tiap kelompok tetap terbaru-dulu.
        $instant = $rows->filter(fn ($r) => !empty($r['is_instant']))->values();
        $pickup  = $rows->filter(fn ($r) => empty($r['is_instant']) && !empty($r['is_pickup']))->values();
        if ($instant->isEmpty() && $pickup->isEmpty()) {
            return $rows;
        }

        return $instant
            ->concat($pickup)
            ->concat($rows->filter(fn ($r) => empty($r['is_urgent'])))
            ->values();
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
            'belum_bayar'    => $all->where('bucket', 'belum_bayar')->count(),
            'belum_siap'     => $all->where('bucket', 'belum_siap')->count(),
            'belum_lunas'    => $all->where('bucket', 'belum_lunas')->count(),
            'perlu_ukur'     => $all->where('bucket', 'perlu_ukur')->count(),
            // Badge tab induk "Perlu Diproses" = yang benar-benar bisa dikerjakan sekarang.
            // Yang tertahan punya badge sendiri di sub-tab-nya.
            'perlu_diproses' => $all->where('bucket', 'perlu_diproses')->count(),
            // Pesanan mendesak yang menunggu diproses — penanda kecil di sub-tab "Siap Proses"
            // supaya operator tahu tanpa harus membuka & memeriksa daftarnya.
            'instant'        => $all->where('bucket', 'perlu_diproses')->where('is_instant', true)->count(),
            'pickup'         => $all->where('bucket', 'perlu_diproses')
                                    ->filter(fn ($r) => empty($r['is_instant']) && !empty($r['is_pickup']))->count(),
            'telah_diproses' => $all->where('bucket', 'telah_diproses')->count(),
            'dikirim'        => $all->where('bucket', 'dikirim')->count(),
            'selesai'        => $all->where('bucket', 'selesai')->count(),
            // Retur pembeli yang perlu ditindak: HANYA yang draft retur-nya masih 'draft'
            // (perlu cek barang & di-post). Order diretur tanpa draft terbentuk / draft
            // sudah di-post / void tidak ikut dihitung — tab tetap menampilkan semuanya.
            'retur'          => $all->where('bucket', 'retur')->where('retur_status', 'draft')->count(),
            // Badge HANYA menghitung pembatalan yang masih perlu ditindak operator (batal di
            // marketplace / pembeli minta batal TAPI SO ERP belum di-void) — termasuk yang
            // butuh persetujuan seller di Seller Center. Pembatalan yang sudah di-void
            // (state 'void') = selesai → tidak ikut dihitung.
            'pembatalan'     => $this->pembatalanActionableQuery()->count(),
        ];
    }

    /**
     * Jumlah pesanan prioritas di sebuah bucket — instant & ambil di toko dipisah supaya
     * chip filter bisa memberi tahu ADA berapa dan JENISNYA apa tanpa operator membuka daftar.
     *
     * @return array{instant:int,pickup:int,total:int}
     */
    public function prioritasCounts(string $bucket): array
    {
        $rows = $this->soRows()->concat($this->warrantyRows())->concat($this->mpPendingRows())
            ->where('bucket', $bucket)
            ->reject(fn ($r) => $r['archived'] ?? false);

        $instant = $rows->where('is_instant', true)->count();
        $pickup  = $rows->filter(fn ($r) => empty($r['is_instant']) && !empty($r['is_pickup']))->count();

        return ['instant' => $instant, 'pickup' => $pickup, 'total' => $instant + $pickup];
    }

    /** Jumlah per status resi di "Telah Diproses" (badge sub-tab). */
    public function resiCounts(): array
    {
        $rows = $this->soRows()->concat($this->warrantyRows())->concat($this->mpPendingRows())
            ->where('bucket', 'telah_diproses')
            ->reject(fn ($r) => $r['archived'] ?? false);

        return [
            'semua'          => $rows->count(),
            'belum_generate' => $rows->where('resi_state', 'belum_generate')->count(),
            'belum_cetak'    => $rows->where('resi_state', 'belum_cetak')->count(),
            'sudah_cetak'    => $rows->where('resi_state', 'sudah_cetak')->count(),
        ];
    }

    /** Label manusiawi tiap bucket — dipakai kolom Status di tab "Semua". */
    public static function bucketLabel(string $bucket): string
    {
        return match ($bucket) {
            'belum_bayar'    => 'Belum Bayar',
            'belum_siap'     => 'Belum Siap',
            'belum_lunas'    => 'Belum Lunas',
            'perlu_ukur'     => 'Perlu Ukur',
            'perlu_diproses' => 'Siap Proses',
            'telah_diproses' => 'Telah Diproses',
            'dikirim'        => 'Dikirim',
            'retur'          => 'Retur',
            'selesai'        => 'Selesai',
            'dibatalkan'     => 'Dibatalkan',
            default          => ucfirst(str_replace('_', ' ', $bucket)),
        };
    }

    /** Tab tempat sebuah bucket tinggal + parameter sub-tab-nya (untuk tombol "lompat & sorot"). */
    public static function bucketRoute(string $bucket, string $number): ?string
    {
        $fokus = ['fokus' => $number];

        return match ($bucket) {
            'belum_bayar'    => route('pos.fulfillment.belum-bayar', $fokus),
            'belum_siap'     => route('pos.fulfillment.perlu-diproses', $fokus + ['tahap' => 'belum-siap']),
            'belum_lunas'    => route('pos.fulfillment.perlu-diproses', $fokus + ['tahap' => 'belum-lunas']),
            'perlu_ukur'     => route('pos.fulfillment.perlu-diproses', $fokus + ['tahap' => 'perlu-ukur']),
            'perlu_diproses' => route('pos.fulfillment.perlu-diproses', $fokus),
            'telah_diproses' => route('pos.fulfillment.telah-diproses', $fokus),
            'dikirim'        => route('pos.fulfillment.dikirim', $fokus),
            'retur'          => route('pos.fulfillment.retur', $fokus),
            'selesai'        => route('pos.fulfillment.selesai', $fokus),
            'dibatalkan'     => route('pos.fulfillment.pembatalan', $fokus),
            default          => null,
        };
    }

    /**
     * Tab "Semua": SELURUH Sales Order (termasuk yang sudah tuntas & void) dengan paginasi SQL.
     *
     * SENGAJA tidak lewat soRows(): mesin bucket menghidrasi semua baris ke memori sekaligus —
     * itu sebabnya ia menyaring riwayat marketplace yang sudah tuntas > 3 hari. Di sini justru
     * riwayat penuh yang dicari, jadi pengambilannya per halaman lalu status tiap baris baru
     * dihitung untuk halaman itu saja.
     *
     * @param array{channel?:string,status?:string,from?:string,to?:string} $filters
     */
    public function allPaginated(?string $search, array $filters, int $perPage)
    {
        $q = SalesOrder::query()->with([
            'customer:id,name,is_marketplace,phone,address,shipping_address,city',
            'items', 'items.product:id,name,sku,sale_type,lead_time_days,weight_gram,length_cm,width_cm,height_cm,preorder_stock',
            'deliveries' => fn ($d) => $d->where('status', '!=', 'void'),
            'deliveries.items',
            'invoices'   => fn ($i) => $i->where('status', '!=', 'void'),
        ]);

        $channel = $filters['channel'] ?? null;
        if ($channel === 'marketplace') {
            $q->whereHas('customer', fn ($c) => $c->where('is_marketplace', true));
        } elseif ($channel === 'non') {
            $q->whereHas('customer', fn ($c) => $c->where('is_marketplace', false));
        }

        // Status dokumen (bukan bucket): bucket dihitung di PHP jadi tak bisa jadi filter SQL.
        $status = $filters['status'] ?? null;
        if ($status === 'void') {
            $q->whereIn('status', ['void', 'cancelled']);
        } elseif ($status === 'draft') {
            $q->where('status', 'draft');
        } elseif ($status === 'aktif') {
            $q->whereNotIn('status', ['void', 'cancelled']);
        }

        if ($from = trim((string) ($filters['from'] ?? ''))) {
            $q->whereDate('order_date', '>=', $from);
        }
        if ($to = trim((string) ($filters['to'] ?? ''))) {
            $q->whereDate('order_date', '<=', $to);
        }

        if ($search = trim((string) $search)) {
            $like = '%' . $search . '%';
            $q->where(function ($w) use ($like) {
                $w->where('order_number', 'like', $like)
                  ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like))
                  ->orWhereHas('items', fn ($i) => $i->where('description', 'like', $like)
                      ->orWhereHas('product', fn ($p) => $p->where('name', 'like', $like)->orWhere('sku', 'like', $like)));
            });
        }

        $page = $q->orderByDesc('order_date')->orderByDesc('id')
            ->paginate($perPage)->withQueryString();

        $page->setCollection($this->rowsFor($page->getCollection()));

        return $page;
    }

    /**
     * Klasifikasi sekumpulan SO yang SUDAH ter-load jadi baris kartu (dipakai tab "Semua").
     * Relasi pendukung (produksi, link Jubelio, retur) diambil sekali untuk semua baris.
     */
    private function rowsFor(Collection $orders): Collection
    {
        $soIds = $orders->pluck('id')->all();

        $prodBySo = $soIds ? ProductionOrder::whereIn('sales_order_id', $soIds)
            ->whereNotIn('status', ['cancelled'])
            ->get(['sales_order_id', 'status'])->groupBy('sales_order_id') : collect();

        $linksBySo = $soIds ? \App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink::whereIn('sales_order_id', $soIds)
            ->get()->keyBy('sales_order_id') : collect();

        $returnsBySo = $soIds ? SalesReturn::whereIn('sales_order_id', $soIds)
            ->orderByDesc('id')->get()->groupBy('sales_order_id') : collect();

        // Kekurangan stok seluruh halaman dihitung sekali (logikanya milik SalesOrderStockCheck,
        // di sini hanya dipanggil versi batch-nya) lalu dibaca per baris lewat shortagesFor().
        $this->shortageMap = $soIds
            ? app(\App\Modules\Sales\Services\SalesOrderStockCheck::class)->shortagesForMany($orders)
            : [];

        return $orders->map(function (SalesOrder $so) use ($prodBySo, $linksBySo, $returnsBySo) {
            $returns = $returnsBySo->get($so->id);
            $retur   = $returns?->firstWhere('status', 'draft') ?? $returns?->first();
            $row = $this->buildSoRow($so, $prodBySo->get($so->id), $linksBySo->get($so->id), $retur);

            // SO yang sudah di-void tidak punya tempat di alur pemrosesan — beri bucket sendiri
            // supaya kolom Status jujur, bukan meminjam label bucket yang tidak ia tempati.
            if (in_array($so->status, ['void', 'cancelled'], true)) {
                $row['bucket']   = 'dibatalkan';
                $row['archived'] = false;
                $row['reason']   = 'Pesanan dibatalkan / di-void';
            }

            return $row;
        })->values();
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
        //
        // Riwayat marketplace yang sudah tuntas dibuang di SQL, bukan di PHP. Pesanan dengan
        // faktur diposting & tuntas di WMS > 3 hari lalu PASTI jatuh ke bucket 'selesai' lalu
        // ditandai archived — dan setiap konsumen soRows() membuang baris archived. Tanpa
        // saringan ini seluruh riwayat (ribuan SO beserta customer/items/deliveries/invoices)
        // dihidrasi hanya untuk langsung dibuang, sampai menembus memory_limit PHP.
        //
        // Order yang diretur DIKECUALIKAN dari saringan: di buildSoRow() bucket 'retur'
        // diperiksa SEBELUM invoice_posted, jadi baris ini belum tentu terarsip.
        // Kondisi ditulis sebagai OR "yang dipertahankan" (bukan NOT) supaya NULL pada
        // wms_completed_at/last_status tidak diam-diam menjatuhkan baris.
        $archiveCutoff = now()->subDays(3);
        $linkedSoIds = \App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink::whereNotNull('sales_order_id')
            ->where(function ($q) use ($archiveCutoff) {
                $q->where('invoice_posted', false)
                  ->orWhereNull('wms_completed_at')
                  ->orWhere('wms_completed_at', '>=', $archiveCutoff)
                  ->orWhere('last_status', 'returned')
                  ->orWhere('return_created', true);
            })
            ->pluck('sales_order_id')->all();

        $orders = SalesOrder::query()
            // Draft ikut ditarik supaya pesanan yang belum diposting tetap terlihat di
            // "Belum Bayar" — tapi kartunya tanpa aksi (belum mereservasi stok).
            ->whereIn('status', ['draft', 'confirmed'])
            ->where(function ($q) use ($linkedSoIds) {
                $q->whereHas('customer', fn ($c) => $c->where('is_marketplace', false));
                if ($linkedSoIds) {
                    $q->orWhereIn('id', $linkedSoIds);
                }
            })
            ->with([
                'customer:id,name,is_marketplace,phone,address,shipping_address,city',
                'items', 'items.product:id,name,sku,sale_type,lead_time_days,weight_gram,length_cm,width_cm,height_cm,preorder_stock',
                'deliveries' => fn ($q) => $q->where('status', '!=', 'void'),
                'deliveries.items',
                'invoices' => fn ($q) => $q->where('status', '!=', 'void'),
            ])
            ->get();

        // Produksi finalized, link Jubelio & retur per-SO diambil sekali di rowsFor().
        $this->soRows = $this->rowsFor($orders);

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

        $isInstant = $link
            ? (bool) $link->is_instant_courier
            : $this->looksInstant($so->shipping_service_name, $so->shipping_courier_code);

        // Pembayaran tempo: barang memang boleh jalan sebelum dibayar, jadi pesanannya melewati
        // gerbang "Belum Bayar" MAUPUN "Belum Lunas". Piutangnya tetap tercatat begitu faktur
        // diposting. Marketplace tak pernah tempo — uangnya diatur channel.
        $isTempo = !$link && (bool) $so->is_tempo;

        // Kekurangan stok fisik (ready stock) — dihitung untuk SEMUA pesanan, termasuk
        // marketplace. Marketplace dulu dikecualikan dengan alasan stoknya sudah dipotong di
        // sisi Jubelio; kenyataannya barang yang tidak ada di gudang tetap tidak bisa dipacking,
        // dan pesanannya lebih baik terlihat di "Belum Siap" daripada mengambang di antrean kerja.
        $shortages = $this->shortagesFor($so);

        // Paket belum ditimbang & diukur setelah dipacking → tertahan di sub-tab "Perlu Ukur".
        $needsMeasure = $this->needsMeasurement($so, $link);

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
                // Sudah dibayar di channel — tapi barangnya tetap harus ADA. Marketplace dulu
                // langsung masuk antrean kerja tanpa cek kesiapan sama sekali, sehingga pesanan
                // preorder yang produksinya masih jalan (atau stoknya 0/minus) nongol di "Siap
                // Proses" dan tim packing mengejar barang yang belum jadi.
                $bucket = ($isCustom && !$prodFinalized) || $shortages
                    ? 'belum_siap'
                    : 'perlu_diproses';
            } else {
                // Belum dibayar di channel-nya → "Belum Bayar", sejajar dengan pesanan non-
                // marketplace tanpa DP. "Belum Siap" khusus pesanan yang UANGNYA SUDAH MASUK
                // tapi barangnya belum ada — dua keadaan yang menuntut tindakan berbeda.
                $bucket = 'belum_bayar';
            }
        } elseif ($postedInvoice) {
            // Non-marketplace sudah diproses. Tiga tahap, sejajar dengan marketplace:
            //   belum diserahkan ke kurir (resi belum di-generate/dicetak) → Telah Diproses
            //   sudah diserahkan, paket masih di jalan                     → Dikirim
            //   sudah ditandai sampai di pembeli                           → Selesai
            // Ambil di toko lompat langsung ke Selesai: barangnya diserahkan ke pembeli
            // saat diproses, tidak ada paket yang perlu ditunggu.
            if ($so->isPickup()) {
                $bucket = 'selesai';
            } elseif (!$this->shipmentHandedOver($so)) {
                $bucket = 'telah_diproses';
            } else {
                $bucket = $this->shipmentDelivered($so) ? 'selesai' : 'dikirim';
            }
        } elseif ($so->status === 'draft') {
            // Draft belum mereservasi stok & belum bisa diproses apa pun — tampilkan di
            // "Belum Bayar" supaya tetap terlihat, tapi semua aksi dimatikan di kartunya.
            $bucket = 'belum_bayar';
        } elseif (!$hasPayment && !$isTempo) {
            $bucket = 'belum_bayar';
        } elseif ($isCustom && !$prodFinalized) {
            $bucket = 'belum_siap';   // sudah DP, barangnya masih diproduksi
        } elseif ($shortages) {
            $bucket = 'belum_siap';   // ready stock, tapi barangnya tidak cukup di gudang
        } elseif (!$isLunas && !$isTempo) {
            $bucket = 'belum_lunas';  // barang siap, tinggal menunggu pelunasan
        } elseif ($needsMeasure) {
            $bucket = 'perlu_ukur';   // lunas, tinggal menimbang kardusnya
        } else {
            $bucket = 'perlu_diproses';
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

        // Alasan tertahan (belum_bayar / belum_siap / belum_lunas / perlu_ukur)
        $reason = null;
        if ($bucket === 'belum_siap') {
            if ($isCustom && !$prodFinalized) {
                $reason = 'Produksi belum selesai';
            } elseif ($shortages) {
                // Sebut SKU-nya: "stok kurang" tanpa barangnya tak bisa ditindak siapa pun.
                $reason = 'Stok belum cukup — ' . collect($shortages)
                    ->take(2)
                    ->map(fn ($s) => $s['sku'] . ' kurang ' . rtrim(rtrim(number_format($s['short'], 2, ',', '.'), '0'), ','))
                    ->implode(', ') . (count($shortages) > 2 ? ' (+' . (count($shortages) - 2) . ' lagi)' : '');
            } else {
                $reason = 'Belum siap diproses';
            }
        } elseif ($bucket === 'belum_bayar') {
            $reason = match (true) {
                (bool) $link              => 'Menunggu pembayaran marketplace',
                $so->status === 'draft'   => 'Masih draft — perlu diposting dulu',
                default                   => 'Belum ada pembayaran / DP',
            };
        } elseif ($bucket === 'belum_lunas') {
            $reason = 'Menunggu pelunasan — sisa ' . rupiah($remaining);
        } elseif ($bucket === 'perlu_ukur') {
            $reason = 'Timbang & ukur kardusnya dulu';
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

            'is_draft'        => $so->status === 'draft',

            // Pengukuran paket (sub-tab "Perlu Ukur"). Nilai bawaan form: yang sudah tersimpan
            // di SO bila pernah diisi Cek Ongkir, jatuh ke taksiran dari master produk.
            'measured_at'     => $so->measured_at,
            'needs_measure'   => $needsMeasure,
            'measure'         => $this->measureDefaults($so),
            'shortages'       => $shortages,

            'is_tempo'        => $isTempo,
            'tempo_days'      => $so->tempo_days,
            'tempo_due_date'  => $so->tempo_due_date,
            'tempo_days_left' => $so->tempoDaysLeft(),
            'tempo_overdue'   => $so->isTempoOverdue(),

            // Kurir instant butuh perhatian jam-jam-an: marketplace punya penanda dari Jubelio,
            // non-marketplace ditebak dari nama layanan (kategori layanan belum disimpan di SO).
            'is_instant'  => $isInstant,
            // Prioritas = ada orang yang menunggu di tempat: kurir instant (driver sudah
            // dipesan) atau ambil di toko (pembeli datang sendiri). Keduanya naik ke atas
            // daftar & dihitung bersama di chip prioritas.
            'is_urgent'   => $isInstant || $so->isPickup(),

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
     * Waktu pesanan dianggap tuntas (dipakai untuk mengarsipkan tab "Selesai"), atau null bila
     * belum: ambil-toko → picked_up_at; kurir → saat paket TERAKHIR ditandai sampai.
     *
     * Hitungan arsip sengaja dimulai dari "sampai", bukan dari tanggal SJ: umur pesanan di tab
     * Selesai jadi menghitung sejak barangnya diterima pembeli.
     */
    private function shippedAt(SalesOrder $so): ?\Carbon\Carbon
    {
        if ($so->isPickup()) {
            return ($so->pickup_status === 'picked_up' && $so->picked_up_at)
                ? \Carbon\Carbon::parse($so->picked_up_at) : null;
        }

        $ship = $this->shippableDeliveries($so);
        if ($ship->isEmpty()) return null;
        if ($ship->contains(fn ($d) => $d->delivered_at === null)) return null; // masih ada paket di jalan

        return $ship->map(fn ($d) => \Carbon\Carbon::parse($d->delivered_at))->max();
    }

    /**
     * Paket SO (non-marketplace) sudah diserahkan ke jasa kirim? → keluar dari "Telah
     * Diproses", masuk "Dikirim".
     *  - Kurir Jubelio yang sudah DIAMBIL kurir (status dari webhook) → langsung diserahkan,
     *    tak perlu menunggu tebakan cetak-resi.
     *  - Kurir manual (tak menerbitkan resi) → dianggap diserahkan begitu SJ diposting.
     *  - Sisanya (status kurir belum masuk) → tebakan lama: resi sudah di-generate, sudah
     *    DICETAK, dan dicetak sebelum hari ini (masih bisa cetak ulang di hari yang sama).
     */
    private function shipmentHandedOver(SalesOrder $so): bool
    {
        $ship = $this->shippableDeliveries($so);
        if ($ship->isEmpty()) {
            return false;
        }
        $today = now()->startOfDay();
        foreach ($ship as $d) {
            if ($d->delivered_at) {
                continue; // sudah sampai → jelas sudah diserahkan
            }
            if (in_array($d->shipping_status, self::STATUS_SUDAH_JALAN, true)) {
                continue; // kurir sudah memegang paketnya (status resmi, bukan tebakan)
            }
            if (\App\Models\ManualCourier::isManualCode($d->shipping_courier_code)) {
                continue; // kurir manual: tak perlu resi
            }
            if (empty($d->tracking_number)) return false;                        // belum di-generate
            if (empty($d->resi_printed_at)) return false;                        // belum dicetak
            if (\Carbon\Carbon::parse($d->resi_printed_at)->gte($today)) return false; // dicetak hari ini → tunggu H+1
        }

        return true;
    }

    /**
     * Semua paket SO sudah SAMPAI di pembeli? → pindah dari "Dikirim" ke "Selesai".
     *
     * Ditandai manual (tombol "Sudah Sampai" di tab Dikirim, biasanya setelah operator
     * melihat hasil Lacak) — status kurir tidak ditarik otomatis. SO tanpa paket sama sekali
     * tidak pernah dianggap sampai; ia belum melewati gerbang shipmentHandedOver().
     */
    private function shipmentDelivered(SalesOrder $so): bool
    {
        $ship = $this->shippableDeliveries($so);

        return $ship->isNotEmpty() && $ship->every(fn ($d) => $d->delivered_at !== null);
    }

    /** Surat Jalan yang benar-benar dikirimkan (posted, bukan ambil di toko). */
    private function shippableDeliveries(SalesOrder $so)
    {
        return $so->deliveries->filter(
            fn ($d) => $d->status === 'posted' && $d->delivery_method !== 'ambil_toko'
        );
    }

    /**
     * Paket ini perlu ditimbang & diukur dulu sebelum boleh diproses?
     *
     * Hanya pesanan yang resinya nanti diterbitkan lewat agregator: marketplace dikecualikan
     * (ukuran & tarifnya sudah dikunci channel, tidak bisa diubah dari sini), begitu juga
     * ambil di toko dan kurir manual yang memang tidak menerbitkan resi sama sekali.
     */
    private function needsMeasurement(SalesOrder $so, ?\App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink $link): bool
    {
        if ($link || $so->isPickup()) {
            return false;
        }
        if (\App\Models\ManualCourier::isManualCode($so->shipping_courier_code)) {
            return false;
        }

        return $so->measured_at === null;
    }

    /**
     * Nilai bawaan form ukur: yang sudah tersimpan di SO bila ada, kalau kosong taksiran dari
     * master produk. Taksiran dimensi sengaja diambil yang TERBESAR per sumbu, bukan dijumlah —
     * angkanya cuma titik awal supaya operator mengoreksi, bukan mengetik dari nol.
     */
    private function measureDefaults(SalesOrder $so): array
    {
        $weight = (int) ($so->package_weight_gram ?? 0);
        $len    = (float) ($so->package_length ?? 0);
        $wid    = (float) ($so->package_width ?? 0);
        $hei    = (float) ($so->package_height ?? 0);

        if ($weight <= 0 || ($len <= 0 && $wid <= 0 && $hei <= 0)) {
            $estWeight = 0;
            $estL = $estW = $estH = 0.0;

            foreach ($so->items as $it) {
                $p = $it->product;
                if (!$p || in_array($p->sale_type ?? null, ['service', 'non_stock'], true)) continue;

                $qty = (float) $it->qty * (float) ($it->conversion_to_base ?? 1);
                $estWeight += (int) ($p->weight_gram ?? 0) * $qty;
                $estL = max($estL, (float) ($p->length_cm ?? 0));
                $estW = max($estW, (float) ($p->width_cm ?? 0));
                $estH = max($estH, (float) ($p->height_cm ?? 0));
            }

            if ($weight <= 0) $weight = (int) round($estWeight);
            if ($len <= 0 && $wid <= 0 && $hei <= 0) {
                $len = $estL;
                $wid = $estW;
                $hei = $estH;
            }
        }

        return [
            'weight_gram' => $weight ?: null,
            'length'      => $len ?: null,
            'width'       => $wid ?: null,
            'height'      => $hei ?: null,
        ];
    }

    /** Kekurangan stok SO ini, dari peta yang sudah dihitung sekali untuk seluruh halaman. */
    private function shortagesFor(SalesOrder $so): array
    {
        return $this->shortageMap[$so->id] ?? [];
    }

    /**
     * Layanan instant/sameday? Heuristik dari nama layanan & kode kurir.
     *
     * Jubelio memang punya `service_category_id` (4 = INSTANT, 5 = SAMEDAY), tapi SO kita belum
     * menyimpannya — yang tersimpan cuma nama layanan. Begitu kategori itu ikut disimpan saat
     * memilih kurir, fungsi ini tinggal diganti pembacaan kolom.
     */
    private function looksInstant(?string $serviceName, ?string $courierCode): bool
    {
        $hay = mb_strtolower(trim(($serviceName ?? '') . ' ' . ($courierCode ?? '')));
        if ($hay === '') return false;

        foreach (['instant', 'sameday', 'same day', 'lalamove', 'gosend', 'gojek', 'grab', 'borzo', 'deliveree'] as $needle) {
            if (str_contains($hay, $needle)) return true;
        }

        return false;
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
                // Belum jadi SO = belum dibayar di channel → sekelompok dengan "Belum Bayar".
                'bucket'      => 'belum_bayar',
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
