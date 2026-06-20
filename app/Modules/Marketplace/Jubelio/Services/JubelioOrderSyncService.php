<?php

namespace App\Modules\Marketplace\Jubelio\Services;

use App\Core\Inventory\Product;
use App\DTO\SalesInvoiceDTO;
use App\DTO\SalesInvoiceItemDTO;
use App\DTO\SalesReturnDTO;
use App\Enums\SalesOrderStatus;
use App\Models\Customer;
use App\Models\MarketplaceConfig;
use App\Modules\Marketplace\Jubelio\Models\JubelioChannelMap;
use App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink;
use App\Modules\Marketplace\Jubelio\Models\JubelioSetting;
use App\Modules\Marketplace\Jubelio\Models\JubelioSyncLog;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Sales\Services\CustomerPaymentService;
use App\Modules\Sales\Services\SalesDeliveryService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SalesReturnService;
use App\Services\NumberGeneratorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sinkron pesanan Jubelio → dokumen penjualan ERP. Dipanggil oleh cron & webhook
 * (jalur kode tunggal). Idempotent per pesanan via tabel jubelio_order_links.
 *
 * Alur tahap (kumulatif, tiap tahap dijaga flag idempotensi):
 *   dibayar (is_paid)        → buat SO (posting) + Bayar DP
 *   terkirim (is_shipped)    → buat Surat Jalan (post)
 *   selesai (marked/received)→ buat Invoice (post) → MarketplaceEngine settle ke saldo MP
 *   retur                    → buat SalesReturn DRAFT dari SO (tidak di-post; tunggu cek barang)
 */
class JubelioOrderSyncService
{
    public function __construct(
        protected JubelioClient $client,
        protected SalesOrderService $orderService,
        protected CustomerPaymentService $paymentService,
        protected SalesDeliveryService $deliveryService,
        protected SalesInvoiceService $invoiceService,
        protected SalesReturnService $returnService,
    ) {}

    private function setting(): JubelioSetting
    {
        return JubelioSetting::singleton();
    }

    // ───────────────────────────── Entry points ─────────────────────────────

    /** Poll pesanan siap-proses (dibayar) + pesanan selesai, proses tiap pesanan. */
    public function syncOrders(): array
    {
        $stats = ['processed' => 0, 'errors' => 0];
        if (!$this->client->isReady()) {
            return $stats;
        }

        foreach (['ready' => 'listReadyToProcess', 'completed' => 'listCompleted'] as $list) {
            $page = 1;
            do {
                $resp = $this->client->{$list}($page, 50);
                if (!$resp['success']) {
                    break;
                }
                $rows = $this->rows($resp['data']);
                foreach ($rows as $row) {
                    $id = (int) ($row['salesorder_id'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }
                    try {
                        $this->syncOrderById($id);
                        $stats['processed']++;
                    } catch (\Throwable $e) {
                        $stats['errors']++;
                        Log::error('Jubelio syncOrder error', ['salesorder_id' => $id, 'error' => $e->getMessage()]);
                    }
                }
                $page++;
            } while (count($rows) >= 50 && $page <= 40); // batas aman
        }

        return $stats;
    }

    /**
     * Backfill catatan pembeli untuk SO marketplace LAMA yang notes-nya masih berisi teks
     * identitas auto ("Pesanan Jubelio … — …"). Tarik ulang field "note" dari Jubelio lalu
     * timpa. Hanya menyentuh SO ber-pola lama agar catatan yang sudah diedit manual aman.
     *
     * @return array{scanned:int, updated:int, cleared:int, skipped:int, errors:int}
     */
    public function backfillBuyerNotes(): array
    {
        $stats = ['scanned' => 0, 'updated' => 0, 'cleared' => 0, 'skipped' => 0, 'errors' => 0];
        if (!$this->client->isReady()) {
            return $stats;
        }

        $links = JubelioOrderLink::whereNotNull('sales_order_id')
            ->whereNotNull('jubelio_salesorder_id')
            ->with('salesOrder')
            ->get();

        foreach ($links as $link) {
            $stats['scanned']++;
            $so = $link->salesOrder;

            // Lewati bila tak ada SO atau notes sudah bukan pola auto lama (jangan timpa
            // catatan pembeli yang sudah benar / yang diedit manual oleh CS).
            if (!$so || !str_starts_with((string) $so->notes, 'Pesanan Jubelio')) {
                $stats['skipped']++;
                continue;
            }

            $resp = $this->client->getOrder((int) $link->jubelio_salesorder_id);
            if (!$resp['success']) {
                $stats['errors']++;
                Log::warning('Backfill catatan pembeli: getOrder gagal', [
                    'so'  => $so->id,
                    'jbl' => $link->jubelio_salesorder_id,
                    'error' => $resp['error'] ?? null,
                ]);
                continue;
            }

            $note = trim((string) (data_get($resp, 'data.note') ?? '')) ?: null;
            $so->update(['notes' => $note]);
            $note === null ? $stats['cleared']++ : $stats['updated']++;
        }

        return $stats;
    }

    /**
     * Proses 1 pesanan Jubelio berdasarkan ID: ambil detail lalu jalankan tahap
     * yang sesuai status. Idempotent.
     */
    public function syncOrderById(int $jubelioSoId): ?JubelioOrderLink
    {
        $resp = $this->client->getOrder($jubelioSoId);
        if (!$resp['success']) {
            Log::warning('Jubelio getOrder gagal', ['id' => $jubelioSoId, 'error' => $resp['error']]);
            return null;
        }
        $detail = $resp['data'];

        $link = JubelioOrderLink::firstOrNew(['jubelio_salesorder_id' => $jubelioSoId]);
        $link->jubelio_salesorder_no = $detail['salesorder_no'] ?? $link->jubelio_salesorder_no;
        $link->store = $this->storeName($detail) ?: $link->store;

        // Info kurir dari pesanan (nama layanan + flag instant) — ditarik lebih awal agar
        // operator bisa lihat kurir & tandai pesanan instant di "Pemrosesan Pesanan",
        // sebelum diproses. Nama kurir hanya diisi bila belum ada (resi/AWB lebih otoritatif).
        if (empty($link->shipper)) {
            $link->shipper = $this->extractShipper($detail) ?: $link->shipper;
        }
        $link->is_instant_courier = $this->isInstantCourier($detail, $link->shipper);

        // Resi/AWB sering terbit BELAKANGAN (kurir async): pesanan yang sudah kita proses
        // (awb_requested) bisa berakhir tanpa tracking_no bila saat request-awb resi belum
        // keluar. request-awb TIDAK pernah dipanggil ulang (flag), jadi tangkap nomornya
        // begitu tersedia di detail Jubelio agar order tak macet di "belum generate resi" —
        // dgn begini cron 5 menit menyelesaikannya sendiri tanpa klik manual.
        $tn = trim((string) ($detail['tracking_no'] ?? $detail['tracking_number'] ?? ''));
        if ($tn !== '' && empty($link->tracking_no)) {
            $link->tracking_no = $tn;
            if ($link->awb_requested && empty($link->wms_completed_at)) {
                $link->wms_completed_at = now();
            }
        }

        // Ringkasan pesanan untuk kartu info "Belum Siap" (pesanan belum jadi SO/belum dibayar).
        $link->snap_customer    = trim((string) ($detail['customer_name'] ?? $detail['contact_name'] ?? '')) ?: $link->snap_customer;
        $link->snap_grand_total = (float) ($detail['grand_total'] ?? $link->snap_grand_total);
        $link->snap_item_count  = is_array($detail['items'] ?? null) ? count($detail['items']) : $link->snap_item_count;
        $link->snap_order_date  = $this->orderDate($detail);
        // Batas kirim (ship-by) marketplace — jangan timpa dgn null bila detail tak menyertakannya.
        $link->mp_due_date      = $this->dueDate($detail) ?: $link->mp_due_date;

        // Pesanan dibatalkan di Jubelio → auto-void SO bila aman (belum ada faktur/SJ);
        // bila sudah ada faktur/SJ → tandai untuk ditangani manual (tab Pembatalan).
        if ($this->isCanceled($detail)) {
            $link->cancel_reason = $this->cancelReason($detail) ?: $link->cancel_reason;
            $this->cancelOrderFromJubelio($link);
            return $link;
        }

        // TAHAP A — dibayar → SO + DP.
        // (Aman dari duplikasi via unique constraint jubelio_salesorder_id + transaksi
        // internal; API resolveItems sengaja di luar lock.)
        if ($this->isPaid($detail) && !$link->dp_posted) {
            $this->ensureSalesOrderAndDp($detail, $link);
        }

        // TAHAP B & C — SJ & Invoice. Keduanya murni DB → bungkus dalam satu transaksi
        // dgn lockForUpdate pada baris link + re-cek flag, supaya webhook & cron tidak
        // memproses tahap yang sama bersamaan (SJ/Invoice dobel → stok keluar dobel).
        // SJ (stok keluar) dibuat saat resi/AWB sudah TERBIT di Jubelio (hasResi) — bukan saat
        // benar-benar dikirim — karena pada titik itu barang sudah dipick/dipack. Timing ini
        // sama dgn perilaku lama (dulu isShipped menyala pada tracking_no), jadi stok tak berubah.
        $needB = $link->sales_order_id && $this->hasResi($detail)     && !$link->sj_created;
        $needC = $link->sales_order_id && $this->isCompleted($detail) && !$link->invoice_posted;
        if ($needB || $needC) {
            DB::transaction(function () use ($link, $detail) {
                $locked = JubelioOrderLink::where('id', $link->id)->lockForUpdate()->first();
                if (!$locked) {
                    return;
                }

                if ($this->hasResi($detail) && !$locked->sj_created) {
                    $this->ensureDelivery($locked);
                }
                if ($locked->sales_order_id && $this->isCompleted($detail) && !$locked->invoice_posted) {
                    $this->ensureInvoice($detail, $locked);
                }

                // Sinkronkan flag hasil ke instance luar agar save() metadata di bawah
                // tidak me-revert flag. (Re-run idempotent: ensureInvoice cek exists,
                // ensureDelivery via alreadyDelivered, jadi clobber pun aman.)
                $link->sj_created         = $locked->sj_created;
                $link->invoice_posted     = $locked->invoice_posted;
                $link->jubelio_invoice_id = $locked->jubelio_invoice_id;
            });
        }

        $link->last_status = $this->statusLabel($detail);
        $link->save();

        return $link;
    }

    /** Poll retur belum diproses → buat draft SalesReturn dari SO. */
    public function syncReturns(): array
    {
        $stats = ['created' => 0, 'skipped' => 0];
        if (!$this->client->isReady()) {
            return $stats;
        }

        $resp = $this->client->listUnprocessedReturns(1, 100);
        if (!$resp['success']) {
            return $stats;
        }

        // Kelompokkan baris retur per pesanan Jubelio (salesorder_id).
        $byOrder = [];
        foreach ($this->rows($resp['data']) as $row) {
            $soId = (int) ($row['salesorder_id'] ?? 0);
            if ($soId > 0) {
                $byOrder[$soId][] = $row;
            }
        }

        foreach ($byOrder as $soId => $rows) {
            $link = JubelioOrderLink::where('jubelio_salesorder_id', $soId)->first();
            // Retur dibuat dari SO; butuh SO ERP yang sudah terbentuk & belum punya draft retur.
            if (!$link || !$link->sales_order_id || $link->return_created) {
                $stats['skipped']++;
                continue;
            }

            // Klaim atomik return_created agar webhook & cron tak membuat draft retur
            // dobel (createReturnDraft punya API call, jadi tak dibungkus lock penuh).
            $claimed = JubelioOrderLink::where('id', $link->id)
                ->where('return_created', false)
                ->update(['return_created' => true]);
            if (!$claimed) {
                $stats['skipped']++;
                continue;
            }

            try {
                $created = $this->createReturnDraft($link, $rows);
                if ($created) {
                    $stats['created']++;
                    JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::OK, 'Retur pesanan ' . ($link->jubelio_salesorder_no ?: $soId), [
                        'reference'             => $link->jubelio_salesorder_no,
                        'jubelio_salesorder_id' => $soId,
                        'message'               => 'Draft Retur Penjualan dibuat (menunggu cek barang).',
                    ]);
                } else {
                    // Tak ada draft dibuat (SO hilang / item tak terpetakan) → lepas klaim
                    // agar bisa dicoba lagi nanti.
                    JubelioOrderLink::where('id', $link->id)->update(['return_created' => false]);
                    $stats['skipped']++;
                }
            } catch (\Throwable $e) {
                JubelioOrderLink::where('id', $link->id)->update(['return_created' => false]);
                $stats['skipped']++;
                Log::error('Jubelio createReturnDraft error', ['salesorder_id' => $soId, 'error' => $e->getMessage()]);
            }
        }

        return $stats;
    }

    /**
     * Tarik daftar pesanan yang PEMBELI minta batalkan dari Jubelio, lalu tandai
     * flag `cancel_requested` (+ alasan) pada link yang cocok. Permintaan yang sudah
     * ditarik kembali (tak lagi di daftar) dibersihkan flag-nya. SO TIDAK auto-void —
     * keputusan batal tetap manual lewat tab "Pembatalan".
     */
    public function syncCancellationRequests(): array
    {
        $stats = ['flagged' => 0, 'cleared' => 0];
        if (!$this->client->isReady()) {
            return $stats;
        }

        // Kumpulkan semua permintaan batal (id → alasan).
        $reasons = [];
        $page = 1;
        do {
            $resp = $this->client->listRequestCancel($page, 100);
            if (!$resp['success']) {
                break;
            }
            $rows = $this->rows($resp['data']);
            foreach ($rows as $row) {
                $id = (int) ($row['salesorder_id'] ?? 0);
                if ($id > 0) {
                    $reasons[$id] = trim((string) ($row['cancel_reason'] ?? $row['cancel_reason_detail'] ?? $row['note'] ?? '')) ?: null;
                }
            }
            $page++;
        } while (count($rows) >= 100 && $page <= 40);

        // Set flag pada link yang diminta batal.
        foreach ($reasons as $jubelioSoId => $reason) {
            $link = JubelioOrderLink::where('jubelio_salesorder_id', $jubelioSoId)->first();
            if (!$link) {
                continue;
            }
            if (!$link->cancel_requested) {
                $link->cancel_requested_at = now();
            }
            $link->cancel_requested = true;
            $link->cancel_reason = $reason;
            $link->save();
            $stats['flagged']++;
        }

        // Bersihkan flag pada link yang TAK lagi di daftar (permintaan ditarik / sudah ditangani).
        $stillRequested = array_keys($reasons);
        $stale = JubelioOrderLink::where('cancel_requested', true)
            ->when($stillRequested, fn ($q) => $q->whereNotIn('jubelio_salesorder_id', $stillRequested))
            ->get();
        foreach ($stale as $link) {
            $link->forceFill(['cancel_requested' => false, 'cancel_reason' => null, 'cancel_requested_at' => null])->save();
            $stats['cleared']++;
        }

        return $stats;
    }

    /**
     * Segarkan status pesanan yang BELUM jadi SO (belum dibayar / gagal resolve item).
     * Yang sudah dibayar akan otomatis dibuat SO-nya; yang dibatalkan ditandai. Dipakai
     * tombol manual "Tarik Pesanan Baru" sebagai cadangan webhook.
     *
     * @return array{refreshed:int, promoted:int}
     */
    public function refreshPendingLinks(): array
    {
        $stats = ['refreshed' => 0, 'promoted' => 0];
        if (!$this->client->isReady()) {
            return $stats;
        }

        $links = JubelioOrderLink::whereNull('sales_order_id')
            ->where('last_status', '!=', 'canceled')
            ->get();

        foreach ($links as $link) {
            try {
                $fresh = $this->syncOrderById((int) $link->jubelio_salesorder_id);
                $stats['refreshed']++;
                if ($fresh && $fresh->sales_order_id) {
                    $stats['promoted']++; // sudah dibayar → jadi SO
                }
            } catch (\Throwable $e) {
                Log::warning('Jubelio refreshPending error', ['id' => $link->jubelio_salesorder_id, 'error' => $e->getMessage()]);
            }
        }

        return $stats;
    }

    /**
     * Tarik pesanan marketplace yang BELUM DIBAYAR (is_paid=false & belum batal) → buat/refresh
     * link pending (sales_order_id NULL) lewat jalur kanonik syncOrderById, agar tampil sebagai
     * kartu info di tab "Belum Siap". Endpoint ready-to-process hanya memuat yang sudah dibayar,
     * jadi tanpa pass ini pesanan menunggu-bayar tak pernah terlihat di ERP. Begitu dibayar,
     * tahap A (ready-to-process) otomatis mempromosikannya jadi SO.
     *
     * @return array{seen:int, pending:int, errors:int}
     */
    public function syncUnpaidOrders(): array
    {
        $stats = ['seen' => 0, 'pending' => 0, 'errors' => 0];
        if (!$this->client->isReady()) {
            return $stats;
        }

        $page = 1;
        do {
            $resp = $this->client->listAllOrders($page, 50);
            if (!$resp['success']) {
                break;
            }
            $rows = $this->rows($resp['data']);
            foreach ($rows as $row) {
                $stats['seen']++;
                // Hanya proses yang BELUM dibayar & belum batal — sisanya ditangani jalur lain.
                if (!empty($row['is_paid']) || $this->isCanceled($row)) {
                    continue;
                }
                $id = (int) ($row['salesorder_id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                try {
                    $link = $this->syncOrderById($id);
                    if ($link && !$link->sales_order_id) {
                        $stats['pending']++;
                    }
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    Log::warning('Jubelio syncUnpaid error', ['id' => $id, 'error' => $e->getMessage()]);
                }
            }
            $page++;
        } while (count($rows) >= 50 && $page <= 40); // batas aman

        return $stats;
    }

    /**
     * Cek-ulang pesanan marketplace yang masih "in-flight" (sudah jadi SO, belum di-invoice
     * & SO belum void) terhadap detail terkini Jubelio. Menutup celah penting: pesanan yang
     * DIBATALKAN di channel hilang dari daftar ready-to-process & completed, sehingga
     * syncOrders() tak pernah melihatnya lagi → pembatalan pasca-bayar tak terdeteksi dan
     * SO/DP menggantung. Idempotent (lewat syncOrderById; auto-void bila aman, atau tandai
     * manual bila sudah ada Faktur/SJ).
     *
     * @return array{checked:int, canceled:int, errors:int}
     */
    public function reconcileActiveOrders(): array
    {
        $stats = ['checked' => 0, 'canceled' => 0, 'errors' => 0];
        if (!$this->client->isReady()) {
            return $stats;
        }

        $links = JubelioOrderLink::whereNotNull('sales_order_id')
            ->where('invoice_posted', false)
            ->whereNotIn('last_status', ['canceled', 'completed'])
            ->whereHas('salesOrder', fn ($s) => $s->whereNotIn('status', ['void', 'cancelled']))
            ->get();

        foreach ($links as $link) {
            try {
                $fresh = $this->syncOrderById((int) $link->jubelio_salesorder_id);
                $stats['checked']++;
                if ($fresh && $fresh->last_status === 'canceled') {
                    $stats['canceled']++;
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::warning('Jubelio reconcileActive error', ['id' => $link->jubelio_salesorder_id, 'error' => $e->getMessage()]);
            }
        }

        return $stats;
    }

    /**
     * Pesanan dibatalkan di Jubelio → batalkan di ERP.
     *  - Belum ada SO / SO sudah void → cukup catat.
     *  - Sudah ada Faktur/Surat Jalan aktif → JANGAN auto-void (berisiko stok/akuntansi);
     *    tandai agar ditangani manual di tab Pembatalan.
     *  - Aman (hanya DP/belum dikirim) → void DP + void SO otomatis.
     *
     * Logika void mengikuti SalesOrderController::void & PaymentController::void (sumber kebenaran).
     */
    private function cancelOrderFromJubelio(JubelioOrderLink $link): void
    {
        $ref = $link->jubelio_salesorder_no ?: (string) $link->jubelio_salesorder_id;
        $link->last_status = 'canceled';

        $so = $link->sales_order_id ? SalesOrder::find($link->sales_order_id) : null;

        if (!$so || in_array($so->status, ['void', 'cancelled'], true)) {
            $link->save();
            JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::OK, 'Pesanan ' . $ref, [
                'reference' => $link->jubelio_salesorder_no, 'jubelio_salesorder_id' => $link->jubelio_salesorder_id,
                'message'   => 'Dibatalkan di Jubelio (tidak ada SO aktif untuk di-void).',
            ]);
            return;
        }

        // Guard: ada Faktur/Surat Jalan aktif → jangan auto-void.
        $activeInvoice = \App\Models\SalesInvoice::where('sales_order_id', $so->id)
            ->whereNotIn('status', ['void', 'cancelled'])->exists();
        $activeDelivery = \App\Modules\Sales\Models\SalesDelivery::where('sales_order_id', $so->id)
            ->whereNotIn('status', ['void', 'cancelled'])->exists();
        if ($activeInvoice || $activeDelivery) {
            $link->last_error = 'Dibatalkan di Jubelio, tetapi sudah ada Faktur/Surat Jalan — perlu void manual.';
            $link->save();
            JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::FAIL, 'Pesanan ' . $ref, [
                'reference' => $link->jubelio_salesorder_no, 'jubelio_salesorder_id' => $link->jubelio_salesorder_id,
                'message'   => 'Batal di Jubelio tapi sudah ada Faktur/SJ — tangani manual di tab Pembatalan.',
            ]);
            return;
        }

        try {
            DB::transaction(function () use ($so, $link) {
                // 1. Void DP/uang muka (bila ada) — mirror PaymentController::void.
                if ($link->customer_payment_id) {
                    $payment = \App\Models\CustomerPayment::with('allocations.salesOrder')->find($link->customer_payment_id);
                    if ($payment && $payment->status === 'posted') {
                        $this->voidAdvancePaymentInternal($payment);
                    }
                }

                // 2. Void SO — mirror SalesOrderController::void (subset aman: tanpa Faktur/SJ aktif).
                \App\Core\Inventory\StockReservation::where('sales_order_id', $so->id)->update(['status' => 'cancelled']);
                $this->cancelAutoPreorderProductions($so);
                $so->status = 'void';
                $so->save();

                if ($so->quotation_id) {
                    $stillRef = SalesOrder::where('quotation_id', $so->quotation_id)
                        ->whereNotIn('status', ['void', 'cancelled'])->where('id', '!=', $so->id)->exists();
                    if (!$stillRef) {
                        \App\Models\SalesQuotation::where('id', $so->quotation_id)
                            ->where('status', 'converted')->update(['status' => 'draft']);
                    }
                }

                $link->save();
            });

            JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::OK, 'Pesanan ' . $ref, [
                'reference' => $link->jubelio_salesorder_no, 'jubelio_salesorder_id' => $link->jubelio_salesorder_id,
                'message'   => "SO {$so->order_number} di-void otomatis (dibatalkan di Jubelio).",
            ]);
        } catch (\Throwable $e) {
            $link->last_error = 'Gagal auto-void: ' . $e->getMessage();
            $link->save();
            JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::FAIL, 'Pesanan ' . $ref, [
                'reference' => $link->jubelio_salesorder_no, 'jubelio_salesorder_id' => $link->jubelio_salesorder_id,
                'message'   => 'Gagal auto-void SO: ' . $e->getMessage() . ' — tangani manual.',
            ]);
            Log::error('Jubelio cancelOrder auto-void gagal', ['id' => $link->jubelio_salesorder_id, 'error' => $e->getMessage()]);
        }
    }

    /** Void DP/uang muka (mirror PaymentController::void). Dipanggil di dalam transaksi. */
    private function voidAdvancePaymentInternal(\App\Models\CustomerPayment $payment): void
    {
        foreach ($payment->allocations as $alloc) {
            if ($alloc->salesOrder) {
                $alloc->salesOrder->paid_amount = max(0, (float) $alloc->salesOrder->paid_amount - (float) $alloc->amount);
                $alloc->salesOrder->save();
            }
            if ($alloc->invoice) {
                $alloc->invoice->paid_amount = max(0, (float) $alloc->invoice->paid_amount - (float) $alloc->amount);
                $alloc->invoice->save();
            }
        }

        // Saldo lebih bayar yang dibuat payment ini tak boleh sudah terpakai transaksi lain.
        $customerId = $payment->customer_id;
        $balance = (float) \App\Models\CustomerOverpayment::where('customer_id', $customerId)->sum('amount');
        $thisNet = (float) \App\Models\CustomerOverpayment::where('customer_id', $customerId)
            ->where('reference', $payment->payment_number)->sum('amount');
        if (($balance - $thisNet) < -0.01) {
            throw new \Exception('Saldo lebih bayar dari DP ini sudah terpakai transaksi lain — batalkan transaksi itu dulu.');
        }
        \App\Models\CustomerOverpayment::where('reference', $payment->payment_number)->delete();
        \App\Modules\Sales\Models\SalesAdvance::where('advance_number', 'ADV-' . $payment->payment_number)->delete();
        \App\Core\Journal\Journal::where('reference_type', 'customer_payment')
            ->where('reference_id', $payment->id)->update(['status' => 'void', 'voided_at' => now()]);

        $payment->status = 'void';
        $payment->save();
    }

    /** PO produksi auto-preorder draft → cancel (mirror SalesOrderController). */
    private function cancelAutoPreorderProductions(SalesOrder $so): void
    {
        \App\Modules\Production\Models\ProductionOrder::where('sales_order_id', $so->id)
            ->where('created_via', 'auto_preorder')
            ->where('status', 'draft')
            ->update(['status' => 'cancelled']);
    }

    // ───────────────────────────── Tahap A: SO + DP ─────────────────────────────

    private function ensureSalesOrderAndDp(array $detail, JubelioOrderLink $link): void
    {
        $setting = $this->setting();

        $store      = $this->storeName($detail);
        $storeId    = (int) ($detail['store_id'] ?? 0) ?: null;
        $channelId  = (int) ($detail['channel_id'] ?? 0) ?: null;
        $customerId = JubelioChannelMap::resolveCustomerId($store, $storeId, $channelId) ?: $setting->default_customer_id;
        $warehouseId = $setting->default_warehouse_id;

        if (!$customerId || !$warehouseId) {
            $this->fail($link, 'Customer/gudang default Jubelio belum diatur (Settings → Jubelio).');
            return;
        }

        // Resolusi semua item dulu — jangan buat SO sebagian bila ada item tak dikenal.
        $resolved = $this->resolveItems($detail['items'] ?? []);
        if ($resolved === null) {
            $this->fail($link, 'Sebagian item pesanan tidak cocok dengan produk ERP (SKU belum sinkron).');
            return;
        }
        if (empty($resolved)) {
            $this->fail($link, 'Pesanan tanpa item yang dapat diproses.');
            return;
        }

        DB::transaction(function () use ($detail, $link, $customerId, $warehouseId, $resolved, $store) {
            $poNumber = $detail['salesorder_no'] ?? ('JBL-' . $link->jubelio_salesorder_id);

            $subtotal = 0.0;
            foreach ($resolved as $r) {
                $subtotal += $r['line_total'];
            }
            $shipping   = (float) ($detail['shipping_cost'] ?? 0);
            $grandTotal = (float) ($detail['grand_total'] ?? ($subtotal + $shipping));

            // Rekonsiliasi potongan marketplace (lihat resolveMarketplaceFee): bila Jubelio
            // tidak melaporkan potongan (mis. TikTok Tokopedia), pakai estimasi dari setting.
            $fees           = $this->resolveMarketplaceFee($subtotal, $shipping, $grandTotal, $customerId);
            $grandTotal     = $fees['grand_total'];
            $marketplaceFee = $fees['fee'];
            $expense        = $fees['expense'];

            $so = SalesOrder::create([
                'order_number'          => NumberGeneratorService::forCustomer('SO', $customerId, $poNumber),
                'customer_id'           => $customerId,
                'customer_po_number'    => $poNumber,
                'warehouse_id'          => $warehouseId,
                'delivery_method'       => 'kurir',
                'order_date'            => $this->orderDate($detail),
                // Catatan pembeli ASLI dari Jubelio (field "note", mis. "Tlg packingan aman ya").
                // Kosongkan bila pembeli tak menulis catatan — jangan isi teks identitas pesanan
                // (channel & nomor PO sudah tampil di kartu + tersimpan di customer_po_number).
                'notes'                 => trim((string) ($detail['note'] ?? '')) ?: null,
                'status'                => SalesOrderStatus::DRAFT->value,
                'subtotal'              => $subtotal,
                'discount_total'        => 0,
                'global_discount_type'  => 'nominal',
                'global_discount_value' => 0,
                'global_discount_amount'=> 0,
                'shipping_cost'         => $shipping,
                'additional_fee'        => $expense,
                'marketplace_fee'       => $marketplaceFee,
                'grand_total'           => $grandTotal,
            ]);

            foreach ($resolved as $r) {
                SalesOrderItem::create([
                    'sales_order_id'     => $so->id,
                    'product_id'         => $r['product']->id,
                    'description'        => $r['description'],
                    'unit_name'          => $r['product']->base_unit,
                    'conversion_to_base' => 1,
                    'qty'                => $r['qty'],
                    'unit_price'         => $r['unit_price'],
                    'discount_type'      => 'nominal',
                    'discount_value'     => 0,
                    'discount_per_unit'  => 0,
                    'net_unit_price'     => $r['unit_price'],
                    'line_subtotal'      => $r['line_total'],
                    'line_discount'      => 0,
                    'line_total'         => $r['line_total'],
                ]);
            }

            // Posting SO (reservasi stok + auto-produksi preorder mengikuti pola existing).
            $this->orderService->confirm($so->id);

            $link->sales_order_id = $so->id;
            $link->store = $store ?: $link->store;

            // Bayar DP = grand_total. Untuk marketplace, kas = akun Titipan/Hold marketplace
            // sehingga settlement (Hold→Wallet) saat invoice menutup dengan rapi.
            $this->postAdvance($so, $customerId, $link);

            // Persist progres tahap A di dalam transaksi agar konsisten dengan SO yang dibuat.
            $link->last_error = null;
            $link->save();

            JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::OK, 'Pesanan ' . ($link->jubelio_salesorder_no ?: $link->jubelio_salesorder_id), [
                'reference'             => $link->jubelio_salesorder_no,
                'jubelio_salesorder_id' => $link->jubelio_salesorder_id,
                'message'               => 'Sales Order ' . $so->order_number . ' dibuat + DP diposting' . ($store ? " ({$store})" : ''),
                'meta'                  => ['sales_order_id' => $so->id, 'grand_total' => (float) $so->grand_total],
            ]);
        });
    }

    /**
     * Posting uang muka (DP) sebesar grand_total ke akun hold marketplace.
     * Bila customer bukan marketplace / tak ada akun hold, DP dilewati (alur AR biasa).
     */
    private function postAdvance(SalesOrder $so, int $customerId, JubelioOrderLink $link): void
    {
        $config = MarketplaceConfig::where('customer_id', $customerId)->where('is_active', true)->first();
        $cashAccountId = $config?->account_receivable_hold_id;

        if (!$cashAccountId) {
            Log::info('Jubelio DP dilewati (tanpa akun hold marketplace)', ['so' => $so->id, 'customer' => $customerId]);
            $link->dp_posted = true; // tandai agar tidak dicoba ulang; invoice nanti pakai AR biasa
            return;
        }

        $payment = $this->paymentService->create([
            'customer_id'     => $customerId,
            'date'            => $so->order_date,
            'cash_account_id' => $cashAccountId,
            'amount'          => (float) $so->grand_total,
            'payment_type'    => 'advance',
            'sales_order_id'  => $so->id,
            'notes'           => 'DP Jubelio ' . $so->customer_po_number,
        ]);

        $this->paymentService->post($payment->id, null, [], [$so->id], false);

        $link->dp_posted = true;
        $link->customer_payment_id = $payment->id;
    }

    // ───────────────────────────── Tahap B: Surat Jalan ─────────────────────────────

    private function ensureDelivery(JubelioOrderLink $link): void
    {
        $so = SalesOrder::find($link->sales_order_id);
        if (!$so) {
            return;
        }
        $delivery = $this->deliveryService->createFromOrder($so, 'kurir');
        if ($delivery) {
            $this->deliveryService->post($delivery->id);
        }
        $link->sj_created = true;
        $link->save();
        JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::OK, 'Pesanan ' . ($link->jubelio_salesorder_no ?: $link->jubelio_salesorder_id), [
            'reference'             => $link->jubelio_salesorder_no,
            'jubelio_salesorder_id' => $link->jubelio_salesorder_id,
            'message'               => 'Surat Jalan dibuat untuk SO ' . $so->order_number . ' (stok keluar).',
        ]);
    }

    // ───────────────────────────── Tahap C: Invoice ─────────────────────────────

    private function ensureInvoice(array $detail, JubelioOrderLink $link): void
    {
        $so = SalesOrder::with('items')->find($link->sales_order_id);
        if (!$so) {
            return;
        }

        // Idempotensi tambahan: bila SO sudah punya invoice, cukup tandai.
        if (\App\Models\SalesInvoice::where('sales_order_id', $so->id)->exists()) {
            $link->invoice_posted = true;
            $link->save();
            return;
        }

        $shipping   = (float) ($detail['shipping_cost'] ?? $so->shipping_cost ?? 0);
        $grandTotal = (float) ($detail['grand_total'] ?? $so->grand_total);

        $items = [];
        $subtotal = 0.0;
        foreach ($so->items as $soItem) {
            $remaining = (float) $soItem->qty - (float) $soItem->qty_invoiced;
            if ($remaining <= 0) {
                continue;
            }
            $unit = (float) $soItem->net_unit_price ?: (float) $soItem->unit_price;
            $subtotal += $unit * $remaining;
            $items[] = new SalesInvoiceItemDTO(
                sales_order_item_id: $soItem->id,
                product_id: $soItem->product_id,
                description: (string) ($soItem->description ?? ''),
                item_type: 'product',
                qty: $remaining,
                unit_price: $unit,
                discount_type: 'nominal',
                discount_value: 0,
                discount_amount: 0,
                ppn_percent: 0,
                pph_percent: 0,
            );
        }

        if (empty($items)) {
            $link->invoice_posted = true;
            $link->save();
            return;
        }

        // Rekonsiliasi grand_total (sama seperti SO): potongan marketplace → marketplace_fee
        // (dibukukan ke akun fee saat posting), bukan diskon. Selisih − = biaya tambahan.
        // Tanpa potongan dari Jubelio → estimasi dari setting (resolveMarketplaceFee).
        $fees           = $this->resolveMarketplaceFee($subtotal, $shipping, $grandTotal, $so->customer_id);
        $marketplaceFee = $fees['fee'];
        $additionalFee  = $fees['expense'];

        $dto = new SalesInvoiceDTO(
            sales_order_id: $so->id,
            customer_id: $so->customer_id,
            warehouse_id: $so->warehouse_id,
            invoice_date: now()->toDateString(),
            global_discount_type: 'nominal',
            global_discount_value: 0,
            ppn_percent: 0,
            pph_percent: 0,
            shipping_cost: $shipping,
            additional_fee: $additionalFee,
            advance_applied: 0, // dihitung otomatis oleh createDraft dari SalesAdvance
            notes: 'Invoice otomatis Jubelio ' . $so->customer_po_number,
            items: $items,
            marketplace_fee: $marketplaceFee,
        );

        $invoice = $this->invoiceService->createDraft($dto);
        app(\App\Services\InvoicePostingService::class)->post($invoice);

        $link->invoice_posted = true;
        $link->jubelio_invoice_id = $detail['invoice_id'] ?? null;
        $link->save();
        JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::OK, 'Pesanan ' . ($link->jubelio_salesorder_no ?: $link->jubelio_salesorder_id), [
            'reference'             => $link->jubelio_salesorder_no,
            'jubelio_salesorder_id' => $link->jubelio_salesorder_id,
            'message'               => 'Invoice ' . ($invoice->invoice_number ?? '') . ' dibuat & diposting untuk SO ' . $so->order_number . '.',
            'meta'                  => ['invoice_id' => $invoice->id ?? null],
        ]);
    }

    // ───────────────────────────── Retur draft ─────────────────────────────

    /** @return bool true bila draft retur benar-benar dibuat. */
    private function createReturnDraft(JubelioOrderLink $link, array $rows): bool
    {
        $so = SalesOrder::with('items')->find($link->sales_order_id);
        if (!$so) {
            return false;
        }

        // Map tiap baris retur Jubelio (item_id, qty) ke SO item ERP.
        $items = [];
        foreach ($rows as $row) {
            $itemId = (int) ($row['item_id'] ?? 0);
            $qty    = (float) ($row['qty'] ?? $row['qty_in_base'] ?? 0);
            if ($itemId <= 0 || $qty <= 0) {
                continue;
            }
            $product = $this->resolveProduct($itemId);
            if (!$product) {
                continue;
            }
            $soItem = $so->items->firstWhere('product_id', $product->id);
            if (!$soItem) {
                continue;
            }
            $items[] = [
                'invoice_item_id' => $soItem->id, // getDoc(SO) mencari by SO item id
                'qty'             => min($qty, (float) $soItem->qty),
                'condition'       => 'good', // default; dikoreksi manual saat cek barang
            ];
        }

        if (empty($items)) {
            return false;
        }

        $dto = new SalesReturnDTO(
            customer_id: $so->customer_id,
            items: $items,
            date: now()->toDateString(),
            sales_order_id: $so->id,
        );

        $this->returnService->saveDraft($dto); // DRAFT — tidak di-post
        // Flag return_created di-set oleh pemanggil (klaim atomik) — lihat syncReturns.
        return true;
    }

    // ───────────────────────────── Helpers ─────────────────────────────

    /**
     * Resolusi item pesanan → produk ERP. Return null bila ADA item tak dikenal
     * (supaya SO tidak dibuat sebagian); array kosong bila semua item non-fisik.
     * @return array<int,array{product:Product,qty:float,unit_price:float,line_total:float,description:string}>|null
     */
    private function resolveItems(array $jubelioItems): ?array
    {
        $out = [];
        foreach ($jubelioItems as $it) {
            $itemId = (int) ($it['item_id'] ?? 0);
            $qty    = (float) ($it['qty'] ?? $it['qty_in_base'] ?? 0);
            if ($itemId <= 0 || $qty <= 0) {
                continue;
            }
            // Baris pesanan Jubelio sudah membawa item_code (SKU varian) langsung.
            $skuHint = $it['item_code'] ?? $it['sku'] ?? null;
            $product = $this->resolveProduct($itemId, $skuHint);
            if (!$product) {
                return null; // ada item tak dikenal → batalkan
            }
            $amount = (float) ($it['amount'] ?? ((float) ($it['price'] ?? 0) * $qty));
            $netUnit = $qty > 0 ? round($amount / $qty, 2) : 0.0;
            // Nama line item: deskripsi marketplace lebih diutamakan (sering custom).
            $desc = trim((string) ($it['description'] ?? '')) ?: $product->name;

            $out[] = [
                'product'     => $product,
                'qty'         => $qty,
                'unit_price'  => $netUnit,
                'line_total'  => round($netUnit * $qty, 2),
                'description' => $desc,
            ];
        }
        return $out;
    }

    /** item_id Jubelio → Product ERP (cache di products.jubelio_item_id; fallback via SKU). */
    private function resolveProduct(int $itemId, ?string $skuHint = null): ?Product
    {
        $product = Product::where('jubelio_item_id', $itemId)->first();
        if ($product) {
            return $product;
        }

        // 1) SKU dari baris pesanan (Jubelio sudah mengirim item_code di item order).
        $sku = trim((string) ($skuHint ?? '')) ?: null;

        // 2) Fallback: ambil item-group; SKU varian ada di product_skus[], BUKAN di top-level.
        if (!$sku) {
            $resp = $this->client->getItem($itemId);
            if (!$resp['success']) {
                return null;
            }
            $sku = $this->extractSku($resp['data'], $itemId);
        }
        if (!$sku) {
            return null;
        }
        $product = Product::where('sku', $sku)->first();
        if ($product) {
            $product->forceFill(['jubelio_item_id' => $itemId])->save();
        }
        return $product;
    }

    /** SKU varian dari respons item-group Jubelio: utamakan baris product_skus[] yang item_id-nya cocok. */
    private function extractSku(array $data, int $itemId): ?string
    {
        if (!empty($data['product_skus']) && is_array($data['product_skus'])) {
            foreach ($data['product_skus'] as $row) {
                if ((int) ($row['item_id'] ?? 0) === $itemId) {
                    return $row['item_code'] ?? null;
                }
            }
            return $data['product_skus'][0]['item_code'] ?? null;
        }
        return $data['item_code'] ?? $data['sku'] ?? null;
    }

    private function rows($data): array
    {
        if (!is_array($data)) {
            return [];
        }
        if (isset($data['data']) && is_array($data['data'])) {
            return $data['data'];
        }
        // beberapa endpoint membungkus di 'list' / 'items'
        foreach (['list', 'items', 'orders'] as $k) {
            if (isset($data[$k]) && is_array($data[$k])) {
                return $data[$k];
            }
        }
        return array_is_list($data) ? $data : [];
    }

    /**
     * Tentukan potongan/biaya marketplace dari selisih nilai pesanan vs grand_total Jubelio.
     *
     *  diff = (subtotal + ongkir) − grand_total
     *   • diff > 0 → Jubelio MELAPORKAN potongan (biaya admin/layanan) → marketplace_fee.
     *   • diff < 0 → ada biaya tambahan dibebankan ke order → additional_fee/expense.
     *   • diff = 0 → Jubelio TIDAK melaporkan potongan (mis. TikTok Tokopedia). Pakai
     *               ESTIMASI dari setting integrasi (MarketplaceConfig: admin_fee_percent +
     *               admin_fee_fixed) sbg marketplace_fee, lalu turunkan grand_total agar
     *               DP/hold = nilai bersih. Selisih estimasi vs aktual direkonsiliasi nanti
     *               saat settlement (akun fee_diff).
     *
     * @return array{fee: float, expense: float, grand_total: float}
     */
    private function resolveMarketplaceFee(float $subtotal, float $shipping, float $grandTotal, int $customerId): array
    {
        $diff    = round($subtotal + $shipping - $grandTotal, 2);
        $fee     = $diff > 0 ? $diff : 0.0;
        $expense = $diff < 0 ? -$diff : 0.0;

        if ($fee <= 0 && $expense <= 0) {
            $config = MarketplaceConfig::where('customer_id', $customerId)->where('is_active', true)->first();
            if ($config) {
                // Bulatkan estimasi ke rupiah penuh: grand_total disimpan sebagai bilangan
                // bulat (createDraft membulatkan), jadi fee dgn pecahan sen (mis. 5555.41)
                // membuat jurnal invoice tak balance sebesar pecahannya. Rupiah tak bersen.
                $est = round(($subtotal + $shipping) * (float) ($config->admin_fee_percent ?? 0) / 100
                           + (float) ($config->admin_fee_fixed ?? 0), 0);
                if ($est > 0) {
                    $fee        = $est;
                    $grandTotal = round($subtotal + $shipping - $est, 0);
                }
            }
        }

        return ['fee' => $fee, 'expense' => $expense, 'grand_total' => $grandTotal];
    }

    private function storeName(array $detail): ?string
    {
        $s = $detail['store_name'] ?? $detail['store'] ?? $detail['source_name'] ?? null;
        return $s ? trim((string) $s) : null;
    }

    /**
     * Nama kurir/layanan kirim dari pesanan Jubelio (mis. "J&T REG", "Grab Instant").
     * Cek beberapa kemungkinan key di level pesanan, lalu fallback ke baris item
     * (beberapa respons WMS menaruh shipper per-item).
     */
    private function extractShipper(array $detail): ?string
    {
        foreach (['shipper', 'courier', 'courier_name', 'shipping_provider', 'shipping_provider_type'] as $k) {
            $v = trim((string) ($detail[$k] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        foreach ((array) ($detail['items'] ?? $detail['order_items'] ?? []) as $it) {
            $v = trim((string) ($it['shipper'] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        return null;
    }

    /**
     * Apakah pesanan memakai instant courier. Utamakan flag eksplisit Jubelio
     * (is_instant_courier, bisa berupa bool / "true" / 1), fallback deteksi dari
     * nama kurir (gosend/grab/instant/sameday/sicepat instant).
     */
    private function isInstantCourier(array $detail, ?string $shipper = null): bool
    {
        $flag = $detail['is_instant_courier'] ?? null;
        if ($flag === null) {
            foreach ((array) ($detail['items'] ?? $detail['order_items'] ?? []) as $it) {
                if (array_key_exists('is_instant_courier', $it)) {
                    $flag = $it['is_instant_courier'];
                    break;
                }
            }
        }
        if ($flag !== null) {
            return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
        }

        $name = strtolower(trim((string) ($shipper ?? $this->extractShipper($detail) ?? '')));
        if ($name === '') {
            return false;
        }
        foreach (['instant', 'gosend', 'gojek', 'grab', 'sameday', 'same day'] as $kw) {
            if (str_contains($name, $kw)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Pesanan dibatalkan di Jubelio. Penting: pembatalan dari sisi MARKETPLACE/channel
     * (mis. SPX/Shopee batal otomatis) TIDAK menyalakan flag `is_canceled` — yang terisi
     * justru channel_status/wms_status/internal_status = "CANCELED" + internal_cancel_date.
     * Flag `is_canceled` hanya menyala saat dokumen di-void manual di Jubelio. Cek semua.
     */
    private function isCanceled(array $d): bool
    {
        if (!empty($d['is_canceled']) || !empty($d['internal_cancel_date'])) {
            return true;
        }
        foreach (['channel_status', 'wms_status', 'internal_status'] as $k) {
            $v = strtoupper(trim((string) ($d[$k] ?? '')));
            if ($v === 'CANCELED' || $v === 'CANCELLED') {
                return true;
            }
        }
        return false;
    }

    /** Alasan pembatalan dari berbagai field Jubelio (channel vs internal). */
    private function cancelReason(array $d): ?string
    {
        foreach (['cancel_reason_detail', 'cancel_reason', 'mp_cancel_reason'] as $k) {
            $v = trim((string) ($d[$k] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        return null;
    }

    private function isPaid(array $d): bool
    {
        return !empty($d['is_paid']) || !empty($d['payment_date']);
    }

    /**
     * Resi/AWB sudah TERBIT di Jubelio (pesanan dipick/dipack — status "PROCESSED"/"Ready To
     * Ship"). Ini BUKAN tanda sudah dikirim: Jubelio membuat nomor resi (tn_created_date) saat
     * diproses, jauh sebelum diserahkan ke kurir. Dipakai untuk membuat Surat Jalan & menandai
     * "Telah Diproses".
     */
    private function hasResi(array $d): bool
    {
        return !empty($d['tn_created_date']) || !empty($d['tracking_number']) || !empty($d['tracking_no']);
    }

    /**
     * Pesanan BENAR-BENAR sudah diserahkan ke jasa kirim / dalam pengiriman. Patokan andal =
     * status Jubelio: internal_status SHIPPED/DELIVERED atau channel_status SHIPPED/IN_TRANSIT/
     * DELIVERED. CATATAN: `is_shipped` sering NULL & resi (tracking_no) terbit terlalu dini saat
     * diproses, jadi keduanya TIDAK dipakai sebagai pemicu utama (hanya shipped_date sbg cadangan).
     */
    private function isShipped(array $d): bool
    {
        $in = strtoupper(trim((string) ($d['internal_status'] ?? '')));
        if (in_array($in, ['SHIPPED', 'DELIVERED'], true)) {
            return true;
        }
        $ch = strtoupper(trim((string) ($d['channel_status'] ?? '')));
        if (in_array($ch, ['SHIPPED', 'IN_TRANSIT', 'IN TRANSIT', 'DELIVERED'], true)) {
            return true;
        }
        return !empty($d['shipped_date']) || filter_var($d['is_shipped'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function isCompleted(array $d): bool
    {
        return !empty($d['marked_as_complete']) || !empty($d['received_date'])
            || strtoupper((string) ($d['wms_status'] ?? '')) === 'COMPLETED';
    }

    private function statusLabel(array $d): string
    {
        if ($this->isCompleted($d)) return 'completed';
        if ($this->isShipped($d))   return 'shipped';   // benar-benar diserahkan ke kurir
        if ($this->hasResi($d))     return 'processed';  // resi terbit, belum diserahkan → Telah Diproses
        if ($this->isPaid($d))      return 'paid';
        return 'pending';
    }

    private function orderDate(array $d): string
    {
        $raw = $d['transaction_date'] ?? $d['created_date'] ?? null;
        try {
            // Jubelio mengirim waktu dalam UTC (akhiran "Z"). Konversi ke zona app (WIB)
            // dulu sebelum ambil tanggal — tanpa ini, order yg masuk 17:00–23:59 WIB
            // (= hari sebelumnya dalam UTC) tercatat mundur 1 hari.
            return $raw
                ? \Carbon\Carbon::parse($raw)->timezone(config('app.timezone'))->toDateString()
                : now()->toDateString();
        } catch (\Throwable) {
            return now()->toDateString();
        }
    }

    /**
     * Batas kirim (ship-by) marketplace dari `due_date` order Jubelio. Jubelio mengirim UTC
     * (akhiran "Z") → konversi ke zona app (WIB). null bila tak ada.
     */
    private function dueDate(array $d): ?\Carbon\Carbon
    {
        $raw = $d['due_date'] ?? null;
        if (empty($raw)) {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($raw)->timezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function fail(JubelioOrderLink $link, string $msg): void
    {
        $link->last_error = $msg;
        $link->save();
        JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::FAIL, 'Pesanan ' . ($link->jubelio_salesorder_no ?: $link->jubelio_salesorder_id), [
            'reference'             => $link->jubelio_salesorder_no,
            'jubelio_salesorder_id' => $link->jubelio_salesorder_id,
            'message'               => $msg,
        ]);
        Log::warning('Jubelio order belum dapat diproses', ['id' => $link->jubelio_salesorder_id, 'reason' => $msg]);
    }
}
