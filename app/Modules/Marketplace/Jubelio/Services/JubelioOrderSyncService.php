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
use App\Modules\Notifications\Services\WebPushNotifier;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Sales\Models\SalesReturn;
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
    /**
     * Cutoff auto-faktur Jubelio. Order yang dikirim langsung oleh channel (tanpa lewat
     * tombol "Proses Pesanan"/WMS) tidak punya Faktur Jubelio → Jubelio menahan reservasi
     * sehingga "stok tersedia"-nya minus. Sejak tanggal ini, cron membuatkan Faktur Jubelio
     * otomatis saat order selesai. Order LAMA (backlog) yang masuk sebelum tanggal ini
     * SENGAJA dilewati — fakturnya dibuat manual oleh user.
     */
    private const JUBELIO_INVOICE_AUTOCREATE_SINCE = '2026-06-24 12:50:00';

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

        // Pass tambahan: order yang SUDAH kita proses (awb_requested) tapi belum diserahkan
        // ke kurir tidak selalu muncul di daftar ready-to-process maupun completed, sehingga
        // status Jubelio-nya tak ter-refresh. Tarik ulang detailnya agar last_status naik ke
        // 'shipped' begitu pesanan benar-benar diserahkan ke jasa kirim → pindah ke "Dikirim".
        $inFlight = JubelioOrderLink::query()
            ->whereNotNull('jubelio_salesorder_id')
            ->where('awb_requested', true)
            // Dulu disaring `invoice_posted = false` sebagai arti "belum tuntas". Sejak faktur
            // terbit saat PENGIRIMAN, flag itu menyala di semua order yang sudah diproses,
            // sehingga pass ini tak lagi menemukan apa pun dan status mereka tak pernah naik
            // ke 'shipped' — pesanan mandek di "Telah Diproses". Status terminal di bawah
            // sudah cukup jadi saringannya.
            // NULL-safe: `NULL NOT IN (...)` = NULL mengeksklusi baris ber-last_status kosong (lihat reconcileActiveOrders).
            ->where(fn ($q) => $q->whereNull('last_status')->orWhereNotIn('last_status', ['shipped', 'completed', 'canceled']))
            ->pluck('jubelio_salesorder_id');
        foreach ($inFlight as $jid) {
            try {
                $this->syncOrderById((int) $jid);
                $stats['processed']++;
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::error('Jubelio refresh in-flight error', ['salesorder_id' => $jid, 'error' => $e->getMessage()]);
            }
        }

        return $stats;
    }

    /**
     * Tarik CEPAT pesanan baru siap-proses — untuk tombol manual "Tarik Pesanan Baru".
     * HANYA memproses daftar ready-to-process (order dibayar & siap fulfill), TIDAK
     * re-sync completed/in-flight/pending (itu tugas sinkron terjadwal 5 menit), supaya
     * request cepat & tidak "muter" lama. Order yang baru dibayar pun ikut tertangkap
     * karena muncul di ready-to-process.
     *
     * @return array{created:int, processed:int, errors:int}
     */
    public function pullNewOrders(): array
    {
        $stats = ['created' => 0, 'processed' => 0, 'errors' => 0];
        if (!$this->client->isReady()) {
            return $stats;
        }

        // Snapshot order Jubelio yang SUDAH jadi SO sebelum tarik — untuk hitung yang benar-benar baru.
        $before = JubelioOrderLink::whereNotNull('sales_order_id')
            ->pluck('jubelio_salesorder_id')->flip();

        $page = 1;
        do {
            $resp = $this->client->listReadyToProcess($page, 50);
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
                    $link = $this->syncOrderById($id);
                    $stats['processed']++;
                    if ($link && $link->sales_order_id && !$before->has($id)) {
                        $stats['created']++;
                    }
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    Log::error('Jubelio pullNewOrders error', ['salesorder_id' => $id, 'error' => $e->getMessage()]);
                }
            }
            $page++;
        } while (count($rows) >= 50 && $page <= 40);

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
     * Isi `mp_completed_at` untuk pesanan yang SUDAH tuntas sebelum kolomnya ada.
     *
     * Perlu perintah sendiri karena sinkron rutin SENGAJA melewati pesanan yang
     * statusnya sudah terminal ('completed'/'canceled') — kalau tidak, tiap
     * putaran menarik ulang seluruh riwayat. Akibatnya pesanan lama tidak akan
     * pernah kebagian tanggal selesainya, dan grafik Penjualan jatuh balik ke
     * cadangannya (tanggal barang keluar gudang) untuk selamanya.
     *
     * Aman diulang: hanya menyentuh baris yang kolomnya masih kosong. Yang
     * detailnya tak menyebut `received_date` diisi dari `wms_completed_at`
     * supaya tetap punya tanggal yang stabil, bukan dibiarkan menggantung.
     *
     * @return array{scanned:int,updated:int,fallback:int,skipped:int,errors:int}
     */
    public function backfillTanggalSelesai(): array
    {
        $stats = ['scanned' => 0, 'updated' => 0, 'fallback' => 0, 'skipped' => 0, 'errors' => 0];

        if (!$this->client->isReady()) {
            return $stats;
        }

        $links = JubelioOrderLink::query()
            ->where('last_status', 'completed')
            ->whereNull('mp_completed_at')
            ->whereNotNull('jubelio_salesorder_id')
            ->get();

        foreach ($links as $link) {
            $stats['scanned']++;

            $resp = $this->client->getOrder((int) $link->jubelio_salesorder_id);

            if (!$resp['success']) {
                $stats['errors']++;
                Log::warning('Backfill tanggal selesai: getOrder gagal', [
                    'link'  => $link->id,
                    'jbl'   => $link->jubelio_salesorder_id,
                    'error' => $resp['error'] ?? null,
                ]);
                continue;
            }

            $tanggal = $this->tanggalSelesaiMarketplace((array) ($resp['data'] ?? []));

            if ($tanggal) {
                $stats['updated']++;
            } elseif ($link->wms_completed_at) {
                $tanggal = $link->wms_completed_at;
                $stats['fallback']++;
            } else {
                $stats['skipped']++;
                continue;
            }

            $link->forceFill(['mp_completed_at' => $tanggal])->save();
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

        // Persist link lebih dulu agar punya id — ensureSalesOrder/ensureDp mengunci baris link
        // (lockForUpdate) untuk anti-duplikasi; pesanan baru (firstOrNew) belum tersimpan.
        $link->save();

        // TAHAP A — buat Sales Order SEDINI MUNGKIN, bahkan untuk pesanan yang BELUM dibayar,
        // agar stok ERP ikut ter-reserve (Dipesan) sejajar dengan reservasi Jubelio. Tanpa ini,
        // pesanan belum-bayar memotong "stok tersedia" di Jubelio tapi tidak di ERP → selisih
        // stok sulit dilacak. SO dibuat status confirmed (reservasi stok) TANPA memicu produksi
        // preorder — pemicu produksi tetap di posting DP, sehingga order yang batal/tak jadi
        // bayar tidak meninggalkan OP. Idempotent: link di-lock + cek-ulang di dalam.
        if (!$link->sales_order_id) {
            $this->ensureSalesOrder($detail, $link);

            // Pesanan INSTANT baru (SPX Instant/GoSend/Grab/SameDay dll) → beri tahu tim
            // packing lewat notifikasi web agar segera diproses & dikirim, sesuai kebijakan
            // marketplace yang menuntut pesanan instant dikirim cepat. Hanya di titik ini
            // (SO baru saja dibuat) supaya pesanan lama tak memicu notifikasi saat re-sync.
            if ($link->sales_order_id && $link->is_instant_courier) {
                $this->notifyPackingNewInstant($link);
            }
        }

        // TAHAP A2 — dibayar → posting DP/uang muka (memicu settlement Hold→Wallet saat invoice
        // & produksi preorder via SalesAdvanceObserver). Dipisah agar SO bisa dibuat lebih dulu.
        if ($link->sales_order_id && $this->isPaid($detail) && !$link->dp_posted) {
            $this->ensureDp($link);
        }

        // TAHAP B & C — SJ & Invoice. Keduanya murni DB → bungkus dalam satu transaksi
        // dgn lockForUpdate pada baris link + re-cek flag, supaya webhook & cron tidak
        // memproses tahap yang sama bersamaan (SJ/Invoice dobel → stok keluar dobel).
        // SJ (stok keluar) dibuat saat barang sudah keluar di Jubelio. Pemicu (salah satu):
        //  - resi/AWB TERBIT di Jubelio (hasResi) — pada titik ini barang sudah dipick/dipack;
        //  - order benar-benar dikirim (isShipped);
        //  - Jubelio SUDAH menerbitkan Faktur-nya (j_invoice_done → stok dipotong di Jubelio).
        // Cakupan j_invoice_done WAJIB: order yang diproses lewat WMS Jubelio (bukan tombol
        // "Proses Pesanan" ERP) atau dikirim langsung channel sering TIDAK memunculkan resi di
        // detail order, sehingga hasResi tetap false selamanya → SJ ERP tak pernah dibuat →
        // stok ERP tak terpotong & reservasi menggantung (reservasi hantu, available minus,
        // ERP dorong stok basi ke Jubelio). j_invoice_done adalah sinyal andal "stok sudah keluar".
        $shipOut = fn($l, $d) => $this->hasResi($d) || $this->isShipped($d) || (bool) $l->j_invoice_done;
        $sjWasCreated = (bool) $link->sj_created; // deteksi SJ yang BARU terbentuk run ini (untuk push stok seketika)
        $needB = $link->sales_order_id && $shipOut($link, $detail)    && !$link->sj_created;
        // FAKTUR terbit segera setelah barang keluar gudang, BUKAN menunggu pesanan selesai.
        // Alasannya: pesanan marketplace yang berakhir retur / paket hilang tidak pernah
        // berstatus "selesai", sehingga fakturnya dulu tak pernah terbit — omzet tak diakui,
        // HPP & Persediaan tak pernah masuk buku besar, dan rekonsiliasi tak punya pasangan.
        $needInv = $link->sales_order_id && $link->sj_created && !$link->invoice_posted;

        // SETTLEMENT (lepas saldo ditahan + bebankan biaya admin) menunggu pesanan SELESAI,
        // karena di situlah potongan marketplace baru diketahui.
        $needC = $link->sales_order_id && $this->isCompleted($detail);
        if ($needB || $needInv || $needC) {
            DB::transaction(function () use ($link, $detail, $shipOut) {
                $locked = JubelioOrderLink::where('id', $link->id)->lockForUpdate()->first();
                if (!$locked) {
                    return;
                }

                if ($shipOut($locked, $detail) && !$locked->sj_created) {
                    $this->ensureDelivery($locked);
                }
                // Barang sudah keluar → terbitkan fakturnya sekarang juga.
                if ($locked->sales_order_id && $locked->sj_created && !$locked->invoice_posted) {
                    $this->ensureInvoice($detail, $locked);
                }

                // Pesanan SELESAI → lepas Saldo Ditahan ke Wallet & bebankan biaya admin,
                // untuk faktur gaya baru yang terbit saat pengiriman tanpa fee.
                if ($locked->sales_order_id && $this->isCompleted($detail)) {
                    $this->ensureSettlement($detail, $locked);
                }

                // Sinkronkan flag hasil ke instance luar agar save() metadata di bawah
                // tidak me-revert flag. (Re-run idempotent: ensureInvoice cek exists,
                // ensureDelivery via alreadyDelivered, jadi clobber pun aman.)
                $link->sj_created         = $locked->sj_created;
                $link->invoice_posted     = $locked->invoice_posted;
                $link->jubelio_invoice_id = $locked->jubelio_invoice_id;
            });
        }

        // TAHAP D — Faktur Jubelio. Order yang dikirim langsung oleh channel tidak melewati
        // tombol "Proses Pesanan" (WMS), sehingga Faktur Jubelio tak pernah terbit & Jubelio
        // menahan reservasi-hantu (stok tersedia minus). Begitu order selesai & Faktur ERP
        // diposting, buat juga Faktur Jubelio. IDEMPOTEN: SO yang sudah difaktur balas id yang
        // sama (tak dobel). DIBATASI cutoff: hanya order yang masuk sejak tanggal cutoff —
        // backlog lama dibuat manual oleh user (lihat JUBELIO_INVOICE_AUTOCREATE_SINCE).
        // Digantungkan pada BARANG SUDAH KELUAR (sj_created), bukan pada faktur ERP.
        // Dulu keduanya seiring karena faktur ERP juga terbit saat pesanan selesai; kini
        // faktur ERP terbit saat pengiriman, jadi menggantungkannya pada faktur ERP hanya
        // menyamarkan maksud aslinya: begitu stok keluar, Jubelio wajib punya faktur agar
        // stok di sana ikut terpotong & reservasi-hantu tak menumpuk.
        if ($link->sales_order_id
            && $link->sj_created
            && !$link->j_invoice_done
            && $link->created_at
            && $link->created_at->gte(self::JUBELIO_INVOICE_AUTOCREATE_SINCE)
        ) {
            $resp = $this->client->postCreateInvoice((int) $link->jubelio_salesorder_id);
            if ($resp['success']) {
                $invId = (int) (data_get($resp, 'data.id') ?: 0);
                $link->forceFill([
                    'j_invoice_done' => true,
                    'j_invoice_id'   => $invId ?: $link->j_invoice_id,
                ])->save();
                JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::OK, 'Pesanan ' . ($link->jubelio_salesorder_no ?: $link->jubelio_salesorder_id), [
                    'reference'             => $link->jubelio_salesorder_no,
                    'jubelio_salesorder_id' => $link->jubelio_salesorder_id,
                    'message'               => 'Faktur Jubelio dibuat otomatis (id ' . ($invId ?: '?') . ').',
                    'meta'                  => ['j_invoice_id' => $invId],
                ]);
            } else {
                Log::warning('Jubelio auto-faktur gagal', ['link' => $link->id, 'so' => $link->jubelio_salesorder_id, 'error' => $resp['error'] ?? null]);
                JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::FAIL, 'Pesanan ' . ($link->jubelio_salesorder_no ?: $link->jubelio_salesorder_id), [
                    'reference'             => $link->jubelio_salesorder_no,
                    'jubelio_salesorder_id' => $link->jubelio_salesorder_id,
                    'message'               => 'Gagal membuat Faktur Jubelio otomatis: ' . ($resp['error'] ?? 'unknown') . '. Akan dicoba lagi run berikutnya.',
                ]);
            }
        }

        $link->last_status = $this->statusLabel($detail);

        /*
         * Tanggal marketplace menyatakan pesanan SELESAI — dicatat sekali,
         * saat pertama kali terlihat, lalu tidak pernah digeser lagi.
         *
         * BUKAN `wms_completed_at`: kolom itu menyala saat KITA selesai
         * memproses pesanan (picking → faktur → resi), alias kira-kira barang
         * keluar gudang. Pesanan yang baru diterima pembeli sepuluh hari
         * kemudian sudah punya `wms_completed_at` yang lama menyala, dan
         * grafik penjualan yang memakainya menaruh omzet di minggu yang salah.
         */
        if ($link->last_status === 'completed' && empty($link->mp_completed_at)) {
            $link->mp_completed_at = $this->tanggalSelesaiMarketplace($detail) ?? now();
        }

        $link->save();

        // Push stok SEKETIKA bila SJ BARU terbentuk run ini (stok komponen sudah keluar):
        // bundle terjual → komponen + bundle terkait langsung berkurang di marketplace tanpa
        // menunggu cron 5 menit (menutup celah oversell komponen). Di LUAR transaksi stok inti,
        // sinkron; pushProductsNow menangkap errornya sendiri sehingga tak mengganggu sync order.
        if (!$sjWasCreated && $link->sj_created && $link->sales_order_id) {
            try {
                $productIds = SalesOrderItem::where('sales_order_id', $link->sales_order_id)->pluck('product_id')->all();
                app(JubelioStockSyncService::class)->pushProductsNow($productIds);
            } catch (\Throwable $e) {
                Log::warning('Jubelio push stok seketika pasca-SJ gagal', ['link' => $link->id, 'error' => $e->getMessage()]);
            }
        }

        return $link;
    }

    /** Poll retur belum diproses → buat draft SalesReturn dari SO. */
    public function syncReturns(): array
    {
        $stats = ['created' => 0, 'skipped' => 0];
        if (!$this->client->isReady()) {
            return $stats;
        }

        // WAJIB telusuri SEMUA halaman. Daftar ini terurut dari yang PALING LAMA dan
        // baris hanya hilang bila retur di-accept/reject di Jubelio — yang tak pernah
        // dilakukan. Jadi baris lama menyumbat halaman 1 permanen dan retur baru selalu
        // mendarat di halaman berikutnya. Dulu hanya halaman 1 yang dibaca → ERP buta
        // terhadap seluruh retur sejak daftar menembus 100 baris (5 Agu 2026, 53 pesanan).
        $byOrder = [];
        $page = 1;
        do {
            $resp = $this->client->listUnprocessedReturns($page, 100);
            if (!$resp['success']) {
                break;
            }

            $rows = $this->rows($resp['data']);
            foreach ($rows as $row) {
                $soId = (int) ($row['salesorder_id'] ?? 0);
                if ($soId > 0) {
                    $byOrder[$soId][] = $row;
                }
            }

            $page++;
        } while (count($rows) >= 100 && $page <= 50);

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

        // NULL-safe: `last_status != 'canceled'` juga = NULL untuk baris NULL → ter-eksklusi. Link
        // pending baru sering ber-last_status NULL; harus tetap disegarkan agar bisa promote ke SO.
        $links = JubelioOrderLink::whereNull('sales_order_id')
            ->where(fn ($q) => $q->whereNull('last_status')->orWhere('last_status', '!=', 'canceled'))
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

        // CATATAN NULL: `last_status` bisa NULL (link tersimpan dgn SO sebelum sync penuh
        // menetapkan statusnya). Di SQL `NULL NOT IN (...)` = NULL → baris ter-eksklusi diam-diam,
        // sehingga pesanan yang dibatalkan channel SEBELUM last_status terisi lolos selamanya dari
        // rekonsiliasi (SO/reservasi menggantung di "Dipesan"). NULL bukan status terminal → ikut sertakan.
        // Saringan cukup pada status terminal. `invoice_posted = false` tidak lagi berarti
        // "belum tuntas" sejak faktur terbit saat pengiriman — memakainya membuat pesanan
        // yang sudah dikirim tapi belum selesai lolos dari rekonsiliasi pembatalan.
        $links = JubelioOrderLink::whereNotNull('sales_order_id')
            ->where(fn ($q) => $q->whereNull('last_status')->orWhereNotIn('last_status', ['canceled', 'completed']))
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
     *  - BARANG SUDAH KELUAR (ada Surat Jalan posted) → JANGAN void; buka kasus RETUR.
     *  - Barang belum keluar → void DP + Faktur + SO otomatis.
     *
     * Void hanya sah selama barang belum keluar gudang: batal bayar (jendela bayar habis)
     * atau sudah bayar lalu dibatalkan sebelum dikirim. Begitu barang dikirim, pembatalan di
     * marketplace berarti paket hilang / dikembalikan pembeli — itu kasus retur, bukan void.
     * Mem-void-nya akan membalik omzet & memasukkan kembali stok yang sebenarnya tak pernah
     * kembali (stok hantu), lalu membunuh faktur yang justru dibutuhkan agar dana kompensasi
     * marketplace bisa dicocokkan saat rekonsiliasi.
     *
     * Logika void mengikuti SalesOrderController::void & PaymentController::void (sumber kebenaran).
     */
    private function cancelOrderFromJubelio(JubelioOrderLink $link): void
    {
        $ref = $link->jubelio_salesorder_no ?: (string) $link->jubelio_salesorder_id;
        $link->last_status = 'canceled';

        // SO yang belum sempat dapat DP = belum pernah dibayar → alasan eksplisit "Belum dibayar"
        // (marketplace umumnya membatalkan order belum-bayar saat jendela bayar habis).
        $wasUnpaid = !$link->dp_posted;

        $so = $link->sales_order_id ? SalesOrder::find($link->sales_order_id) : null;

        if (!$so || in_array($so->status, ['void', 'cancelled'], true)) {
            $link->save();
            JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::OK, 'Pesanan ' . $ref, [
                'reference' => $link->jubelio_salesorder_no, 'jubelio_salesorder_id' => $link->jubelio_salesorder_id,
                'message'   => 'Dibatalkan di Jubelio (tidak ada SO aktif untuk di-void).',
            ]);
            return;
        }

        // Barang sudah keluar gudang → bukan pembatalan, melainkan retur/klaim. Dokumen
        // dibiarkan utuh (omzet tetap diakui, stok tetap keluar) dan kasusnya dibuka sebagai
        // Retur tahap `baru` untuk ditindaklanjuti manual.
        $hasShipped = \App\Modules\Sales\Models\SalesDelivery::where('sales_order_id', $so->id)
            ->where('status', 'posted')
            ->exists();

        if ($hasShipped) {
            $this->openReturnCaseInsteadOfVoid($so, $link, $ref);
            return;
        }

        // Auto-void PENUH saat Jubelio sinyal batal (operator cukup terima/tolak di Seller
        // Center). Faktur yang sudah terbit ikut di-void otomatis: jurnal Faktur dibalik via
        // service void yang sama dgn jalur manual (sumber kebenaran).
        // Bila ada dependency yang menghalangi (Payment/Retur/Garansi/Billing aktif) atau error
        // lain → catch di bawah menandai 'perlu tangani manual' (tak ada perubahan separuh
        // jalan karena seluruhnya dalam satu transaksi).
        try {
            DB::transaction(function () use ($so, $link, $wasUnpaid) {
                // Catat alasan batal agar tampil di tab Pembatalan. Order belum-bayar → "Belum
                // dibayar"; bila Jubelio sudah memberi alasan spesifik, hormati alasan itu.
                if ($wasUnpaid && empty($link->cancel_reason)) {
                    $link->cancel_reason = 'Belum dibayar';
                }

                // 1. Void DP/uang muka (bila ada) — mirror PaymentController::void.
                if ($link->customer_payment_id) {
                    $payment = \App\Models\CustomerPayment::with('allocations.salesOrder')->find($link->customer_payment_id);
                    if ($payment && $payment->status === 'posted') {
                        $this->voidAdvancePaymentInternal($payment);
                    }
                }

                // 1b. Void Faktur ERP aktif (bila ada) — membalik jurnal Faktur + jurnal
                //     marketplace, mengembalikan qty_invoiced/uang muka, DAN ikut void Surat
                //     Jalan terkait + balik stoknya (lihat SalesInvoiceService::voidPosted).
                $activeInvoices = \App\Models\SalesInvoice::where('sales_order_id', $so->id)
                    ->whereNotIn('status', ['void', 'cancelled'])->get();
                foreach ($activeInvoices as $inv) {
                    $this->invoiceService->voidPosted($inv);
                }

                // (Tak ada penanganan Surat Jalan di sini: SO dengan SJ posted sudah dialihkan
                //  ke kasus retur di atas dan tak pernah sampai ke titik ini.)

                // 2. Void SO — mirror SalesOrderController::void.
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

                $link->last_error = null; // bersihkan flag "perlu void manual" bila sebelumnya ada
                $link->save();
            });

            JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::OK, 'Pesanan ' . $ref, [
                'reference' => $link->jubelio_salesorder_no, 'jubelio_salesorder_id' => $link->jubelio_salesorder_id,
                'message'   => $wasUnpaid
                    ? "SO {$so->order_number} di-void otomatis (belum dibayar / dibatalkan di marketplace) — reservasi stok dilepas."
                    : "SO {$so->order_number} di-void otomatis (dibatalkan di Jubelio sebelum barang keluar) — Faktur ikut di-void.",
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

    /**
     * Pembatalan marketplace atas pesanan yang barangnya SUDAH keluar → buka kasus Retur
     * tahap `baru`, tanpa menyentuh SO/Faktur/Surat Jalan sama sekali.
     *
     * Tak ada jurnal & tak ada pergerakan stok di sini: retur lahir sebagai draft di tahap
     * `baru`, jadi omzet tetap diakui dan barang tetap tercatat keluar sampai admin
     * mendefinisikan kasusnya.
     *
     * Kondisi item default `damaged` = anggapan paling hati-hati: barang hilang DAN dananya
     * tidak diganti. Saat admin memilih Jenis Retur "Paket Hilang", form mengubahnya jadi
     * `tidak_kembali` (dana diganti marketplace, penjualan tidak dibalik); kalau klaimnya
     * ternyata ditolak, kondisinya dikembalikan ke `damaged`. Bila barangnya justru benar-benar
     * dikembalikan pembeli, admin memilih `good`/`repair` setelah memeriksa paketnya.
     */
    private function openReturnCaseInsteadOfVoid(SalesOrder $so, JubelioOrderLink $link, string $ref): void
    {
        // Klaim atomik return_created — flag yang sama dipakai syncReturns, supaya kasus ini
        // tak berlipat bila Jubelio kemudian juga menampilkannya di daftar retur.
        $claimed = JubelioOrderLink::where('id', $link->id)
            ->where('return_created', false)
            ->update(['return_created' => true]);

        if (!$claimed) {
            $link->save();
            JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::OK, 'Pesanan ' . $ref, [
                'reference' => $link->jubelio_salesorder_no, 'jubelio_salesorder_id' => $link->jubelio_salesorder_id,
                'message'   => "SO {$so->order_number} dibatalkan di Jubelio setelah barang keluar — kasus retur sudah ada, dilewati.",
            ]);
            return;
        }

        try {
            $return = $this->createShippedCancellationReturn($so);

            if (!$return) {
                // Tak ada item yang bisa dipetakan → lepas klaim agar bisa dicoba lagi.
                JubelioOrderLink::where('id', $link->id)->update(['return_created' => false]);
                $link->last_error = 'Batal setelah kirim, tapi kasus retur gagal dibuat (item tak terpetakan) — tangani manual.';
                $link->save();
                JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::FAIL, 'Pesanan ' . $ref, [
                    'reference' => $link->jubelio_salesorder_no, 'jubelio_salesorder_id' => $link->jubelio_salesorder_id,
                    'message'   => "SO {$so->order_number} dibatalkan setelah barang keluar, tapi item tak terpetakan — buat retur manual.",
                ]);
                return;
            }

            $link->last_error = null;
            $link->save();

            JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::OK, 'Pesanan ' . $ref, [
                'reference' => $link->jubelio_salesorder_no, 'jubelio_salesorder_id' => $link->jubelio_salesorder_id,
                'message'   => "SO {$so->order_number} dibatalkan di Jubelio SETELAH barang keluar — tidak di-void. "
                    . "Kasus retur {$return->return_number} dibuka (tahap Retur Baru); SO/Faktur/Surat Jalan dibiarkan utuh.",
            ]);
        } catch (\Throwable $e) {
            JubelioOrderLink::where('id', $link->id)->update(['return_created' => false]);
            $link->last_error = 'Gagal membuka kasus retur: ' . $e->getMessage();
            $link->save();
            JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::FAIL, 'Pesanan ' . $ref, [
                'reference' => $link->jubelio_salesorder_no, 'jubelio_salesorder_id' => $link->jubelio_salesorder_id,
                'message'   => 'Gagal membuka kasus retur: ' . $e->getMessage() . ' — tangani manual.',
            ]);
            Log::error('Jubelio openReturnCase gagal', ['id' => $link->jubelio_salesorder_id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Draft retur dari barang yang BENAR-BENAR terkirim (baris Surat Jalan posted), bukan dari
     * qty pesanan — pengiriman sebagian hanya boleh melahirkan retur sebesar yang terkirim.
     *
     * Retur ditautkan ke Faktur bila ada yang aktif (agar pembalikan omzet mengenai akun
     * Penjualan), selain itu ke SO (mengenai Uang Muka Penjualan).
     */
    private function createShippedCancellationReturn(SalesOrder $so): ?SalesReturn
    {
        $shippedQty = [];
        $deliveries = \App\Modules\Sales\Models\SalesDelivery::with('items')
            ->where('sales_order_id', $so->id)
            ->where('status', 'posted')
            ->get();

        foreach ($deliveries as $delivery) {
            foreach ($delivery->items as $di) {
                $qty = (float) $di->qty;
                if ($di->product_id && $qty > 0) {
                    $shippedQty[$di->product_id] = ($shippedQty[$di->product_id] ?? 0) + $qty;
                }
            }
        }

        if (empty($shippedQty)) {
            return null;
        }

        $invoice = \App\Models\SalesInvoice::with('items')
            ->where('sales_order_id', $so->id)
            ->whereNotIn('status', ['void', 'cancelled'])
            ->latest('id')
            ->first();

        $doc = $invoice ?: $so->loadMissing('items');

        $items = [];
        foreach ($shippedQty as $productId => $qty) {
            $docItem = $doc->items->firstWhere('product_id', $productId);
            if (!$docItem) {
                continue;
            }
            $items[] = [
                'invoice_item_id' => $docItem->id,
                'qty'             => min($qty, (float) $docItem->qty),
                // Anggapan paling hati-hati sampai admin memeriksa kasusnya: barang hilang
                // DAN dananya tidak diganti. Lihat docblock openReturnCaseInsteadOfVoid.
                'condition'       => 'damaged',
            ];
        }

        if (empty($items)) {
            return null;
        }

        $dto = new SalesReturnDTO(
            customer_id: $so->customer_id,
            items: $items,
            date: now()->toDateString(),
            invoice_id: $invoice?->id,
            sales_order_id: $invoice ? null : $so->id,
        );

        // DRAFT tahap `baru` — belum ada jurnal & stok sampai diputuskan manual.
        return $this->returnService->saveDraft($dto, 'baru');
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

    /**
     * OP auto-preorder milik SO yang di-void → batalkan (mirror SalesOrderController).
     *
     * Dulu hanya status 'draft' yang dibatalkan, padahal OP preorder LAHIR 'confirmed' —
     * akibatnya pesanan yang dibatalkan pembeli (sering hanya 1 menit setelah masuk) tetap
     * diproduksi dan barangnya jadi kelebihan stok. ProductionOrderService::cancel() menolak
     * OP yang sudah mulai dikerjakan / terlibat penggabungan; yang gagal cukup dicatat —
     * void SO tidak boleh batal karena produksinya sudah jalan.
     */
    private function cancelAutoPreorderProductions(SalesOrder $so): void
    {
        $pos = \App\Modules\Production\Models\ProductionOrder::where('sales_order_id', $so->id)
            ->where('created_via', 'auto_preorder')
            ->whereNotIn('status', ['cancelled', 'finalized'])
            ->get();

        foreach ($pos as $po) {
            try {
                app(\App\Modules\Production\Services\ProductionOrderService::class)->cancel($po->id);
                $po->refresh()->forceFill([
                    'notes' => trim(($po->notes ?? '') . "\n[Auto-cancel: SO {$so->order_number} dibatalkan di Jubelio]"),
                ])->save();
            } catch (\Throwable $e) {
                Log::warning('OP auto_preorder tidak bisa dibatalkan saat SO Jubelio di-void', [
                    'production_order_id' => $po->id,
                    'order_number'        => $po->order_number,
                    'status'              => $po->status,
                    'sales_order_id'      => $so->id,
                    'message'             => $e->getMessage(),
                ]);
            }
        }
    }

    // ───────────────────────────── Tahap A: Sales Order (reservasi stok) ─────────────────────────────

    /**
     * Buat Sales Order ERP dari pesanan Jubelio + posting (confirm) agar stok ter-reserve.
     * Dijalankan bahkan untuk pesanan BELUM dibayar — tujuannya menyamakan reservasi stok ERP
     * dengan Jubelio supaya selisih stok mudah dideteksi. TIDAK memposting DP (lihat ensureDp),
     * jadi produksi preorder belum terpicu untuk order yang mungkin batal/tidak jadi bayar.
     *
     * Idempotent: resolusi item & fee di luar lock (ada API call); pembuatan SO di dalam
     * transaksi dgn lock baris link + cek-ulang sales_order_id agar webhook+cron tak membuat
     * SO (dan reservasi stok) dobel.
     */
    private function ensureSalesOrder(array $detail, JubelioOrderLink $link): void
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
            // Lock baris link + cek-ulang: webhook & cron bisa konkuren membuat SO untuk pesanan
            // yang sama → reservasi stok dobel. Lock memastikan hanya satu proses yang membuat.
            $locked = JubelioOrderLink::where('id', $link->id)->lockForUpdate()->first();
            if (!$locked || $locked->sales_order_id) {
                if ($locked) {
                    $link->sales_order_id = $locked->sales_order_id; // sinkron utk dispatch DP di luar
                }
                return;
            }

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
            $marketplaceFee = $fees['fee'];
            $expense        = $fees['expense'];

            // grand_total SO = nilai KOTOR (sebelum biaya admin marketplace), SENGAJA tidak
            // memakai $fees['grand_total'] yang sudah bersih. Aturan alur marketplace:
            // biaya admin di SO hanya ESTIMASI, pemotongan sesungguhnya terjadi di FAKTUR.
            //
            // Konsekuensinya DP = kotor, dan itulah yang membuat retur menutup rapi: retur atas
            // SO menjurnal Dr Uang Muka / Cr Saldo Ditahan sebesar nilai KOTOR (SalesReturnService
            // ::getRevenueReversalLines). Ketika DP masih bersih, selisih sebesar biaya admin
            // menggantung selamanya di 2105 & hold. Nilai kotor juga yang dibayarkan marketplace
            // saat klaim paket hilang menang, sehingga rekonsiliasi ikut cocok.
            //
            // Faktur TIDAK terpengaruh: ensureInvoice() menghitung fee-nya sendiri dari detail
            // Jubelio, tidak membaca grand_total SO — jadi faktur tetap bersih & beban admin
            // tetap dibebankan SEKALI di jurnal faktur. Selisih kotor↔bersih di akun hold
            // ditutup oleh baris reklas $gap di MarketplaceEngineService.
            //
            // Rumusnya sengaja identik dengan SO manual ERP (SalesOrderService::applyItemsAndTotals)
            // dan dengan resolveGross() di rekonsiliasi: kotor = faktur.grand_total + fee.
            $grandTotal = round($subtotal + $shipping + $expense, 2);

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

            // Posting SO → reservasi stok. Produksi preorder TIDAK terpicu di sini (pemicunya
            // posting DP — lihat ensureDp), agar order belum-bayar tak meninggalkan OP.
            $this->orderService->confirm($so->id);

            $locked->sales_order_id = $so->id;
            $locked->store = $store ?: $locked->store;
            $locked->last_error = null;
            $locked->save();

            // Sinkron ke instance luar agar save() metadata di syncOrderById tak me-revert,
            // dan agar dispatch DP (ensureDp) di luar transaksi melihat sales_order_id.
            $link->sales_order_id = $locked->sales_order_id;
            $link->store = $locked->store;
            $link->last_error = null;

            JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::OK, 'Pesanan ' . ($locked->jubelio_salesorder_no ?: $locked->jubelio_salesorder_id), [
                'reference'             => $locked->jubelio_salesorder_no,
                'jubelio_salesorder_id' => $locked->jubelio_salesorder_id,
                'message'               => 'Sales Order ' . $so->order_number . ' dibuat — stok ter-reserve, menunggu pembayaran' . ($store ? " ({$store})" : ''),
                'meta'                  => ['sales_order_id' => $so->id, 'grand_total' => (float) $so->grand_total],
            ]);
        });
    }

    /**
     * Notifikasi web ke tim packing untuk pesanan marketplace INSTANT yang baru masuk.
     *
     * Klaim atomik pada packing_notified_at memastikan hanya SATU proses yang mengirim,
     * walau webhook & cron memproses pesanan baru yang sama nyaris bersamaan. Kegagalan
     * kirim (VAPID belum diatur / push error) tidak boleh mengganggu alur sinkron.
     */
    private function notifyPackingNewInstant(JubelioOrderLink $link): void
    {
        $claimed = JubelioOrderLink::where('id', $link->id)
            ->whereNull('packing_notified_at')
            ->update(['packing_notified_at' => now()]);
        if (!$claimed) {
            return; // sudah diberitahu oleh proses lain
        }
        $link->packing_notified_at = now();

        $store = $link->store ?: 'Marketplace';
        $parts = [];
        if ($link->snap_customer)   { $parts[] = $link->snap_customer; }
        if ($link->snap_item_count) { $parts[] = $link->snap_item_count . ' item'; }
        if ($link->shipper)         { $parts[] = $link->shipper; }
        $detail = $parts ? ' — ' . implode(' • ', $parts) : '';

        try {
            app(WebPushNotifier::class)->notifyErpUsers(
                "⚡ Pesanan Instant Baru — {$store}",
                "Segera proses & kirim{$detail}",
                [
                    'url' => route('pos.fulfillment.perlu-diproses'),
                    'tag' => 'jubelio-instant-' . $link->id,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('Notifikasi packing (pesanan instant) gagal: ' . $e->getMessage());
        }
    }

    /**
     * Posting DP/uang muka untuk SO marketplace yang SUDAH dibayar di channel. Dipisah dari
     * pembuatan SO supaya SO bisa dibuat lebih dulu (reservasi stok) sejak order belum dibayar.
     * Memicu settlement Hold→Wallet (saat invoice) & produksi preorder (SalesAdvanceObserver).
     * Idempotent: lock baris link + cek-ulang dp_posted agar webhook+cron tak posting DP dobel.
     */
    private function ensureDp(JubelioOrderLink $link): void
    {
        DB::transaction(function () use ($link) {
            $locked = JubelioOrderLink::where('id', $link->id)->lockForUpdate()->first();
            if (!$locked || !$locked->sales_order_id || $locked->dp_posted) {
                if ($locked) {
                    $link->dp_posted = $locked->dp_posted;
                    $link->customer_payment_id = $locked->customer_payment_id;
                }
                return;
            }

            $so = SalesOrder::find($locked->sales_order_id);
            if (!$so) {
                return;
            }

            // Bayar DP = grand_total SO. Untuk marketplace, kas = akun Titipan/Hold marketplace
            // sehingga settlement (Hold→Wallet) saat invoice menutup dengan rapi.
            $this->postAdvance($so, (int) $so->customer_id, $locked);
            $locked->save();

            // Sinkron ke instance luar agar save() metadata di syncOrderById tak me-revert flag.
            $link->dp_posted = $locked->dp_posted;
            $link->customer_payment_id = $locked->customer_payment_id;

            JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::OK, 'Pesanan ' . ($locked->jubelio_salesorder_no ?: $locked->jubelio_salesorder_id), [
                'reference'             => $locked->jubelio_salesorder_no,
                'jubelio_salesorder_id' => $locked->jubelio_salesorder_id,
                'message'               => 'DP/uang muka diposting untuk SO ' . $so->order_number . ' (pesanan dibayar).',
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

    /**
     * Buat Surat Jalan ERP SEGERA saat "Proses Pesanan" marketplace berhasil (Faktur
     * Jubelio terbit → stok dipotong di Jubelio), supaya stok ERP keluar di waktu yang
     * sama, bukan menunggu cron. Idempoten via flag sj_created + lock baris link; cron
     * Tahap B (cek !sj_created) otomatis melewatinya & hanya memindah tab saat shipped.
     * Return true bila SJ baru dibuat.
     */
    public function createDeliveryOnProcess(JubelioOrderLink $link): bool
    {
        return DB::transaction(function () use ($link) {
            $locked = JubelioOrderLink::where('id', $link->id)->lockForUpdate()->first();
            if (!$locked || !$locked->sales_order_id || $locked->sj_created) {
                return false;
            }
            $this->ensureDelivery($locked);

            // Faktur menyusul barang keluar, dalam transaksi & kunci yang sama. Tanpa ini,
            // menekan "Proses Pesanan" hanya menghasilkan Surat Jalan dan fakturnya baru
            // muncul saat cron menyinkron pesanan itu lagi — omzet & HPP tertunda tanpa
            // alasan, dan operator melihat pesanan terproses tapi tak berfaktur.
            if ($locked->sj_created && !$locked->invoice_posted) {
                $this->ensureInvoice([], $locked);
            }

            return true;
        });
    }

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

    // ──────────────────── Faktur (terbit saat barang keluar gudang) ────────────────────

    /**
     * Terbitkan faktur sebesar barang yang BENAR-BENAR sudah dikirim, mengikuti Sales Order
     * persis: nilai KOTOR, tanpa biaya admin marketplace.
     *
     * KENAPA saat pengiriman, bukan saat pesanan selesai. Pesanan marketplace yang berakhir
     * retur atau paket hilang tidak pernah berstatus "selesai" di Jubelio, jadi dulu fakturnya
     * tak pernah terbit. Akibatnya permanen: omzetnya tak pernah diakui, HPP & Persediaan tak
     * pernah masuk buku besar (Surat Jalan tidak menjurnal apa pun), saldo ditahan mengendap,
     * dan baris settlement-nya tak punya faktur untuk dicocokkan saat rekonsiliasi.
     *
     * KENAPA tanpa biaya admin. Saat barang keluar, marketplace belum memotong apa pun —
     * angkanya masih taksiran. Fee dibebankan nanti oleh MarketplaceEngineService saat pesanan
     * selesai (lihat ensureSettlement), dan selisihnya terhadap potongan sebenarnya dikoreksi
     * lagi saat rekonsiliasi settlement. Faktur ditandai `fee_at_settlement` supaya rekonsiliasi
     * tahu grand_total-nya sudah kotor.
     *
     * QTY diambil dari Surat Jalan yang sudah posted, bukan dari sisa qty SO — supaya
     * pengiriman bertahap menghasilkan faktur bertahap yang sepadan.
     */
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

        $invoice = $this->terbitkanFakturPengiriman(
            $so,
            (float) ($detail['shipping_cost'] ?? $so->shipping_cost ?? 0)
        );

        $link->invoice_posted = true;
        // Jangan timpa dgn null bila dipanggil tanpa payload Jubelio (jalur tombol Proses).
        $link->jubelio_invoice_id = $detail['invoice_id'] ?? $link->jubelio_invoice_id;
        $link->save();

        if (!$invoice) {
            return; // tak ada baris terkirim yang belum difakturkan
        }

        JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::OK, 'Pesanan ' . ($link->jubelio_salesorder_no ?: $link->jubelio_salesorder_id), [
            'reference'             => $link->jubelio_salesorder_no,
            'jubelio_salesorder_id' => $link->jubelio_salesorder_id,
            'message'               => 'Invoice ' . ($invoice->invoice_number ?? '') . ' dibuat & diposting untuk SO ' . $so->order_number . '.',
            'meta'                  => ['invoice_id' => $invoice->id ?? null],
        ]);
    }

    /**
     * Terbitkan & posting faktur pengiriman untuk sebuah SO marketplace.
     *
     * Dipakai dua tempat dengan aturan yang sama persis: sinkron Jubelio (saat Surat Jalan
     * terbit) dan command faktur susulan untuk pesanan lama. Sengaja satu implementasi supaya
     * keduanya tak pernah menyimpang.
     *
     * @return SalesInvoice|null null bila tak ada baris terkirim yang belum difakturkan.
     */
    public function terbitkanFakturPengiriman(SalesOrder $so, ?float $shippingOverride = null): ?\App\Models\SalesInvoice
    {
        $so->loadMissing('items.product');
        $shipping = $shippingOverride !== null ? (float) $shippingOverride : (float) ($so->shipping_cost ?? 0);

        // Qty yang sudah KELUAR lewat Surat Jalan posted, per PRODUK untuk seluruh SO — bukan
        // per baris SO. `sales_order_item_id` di baris SJ tak bisa dipercaya untuk menghitung:
        //  - bundle dikirim sebagai KOMPONEN: satu baris SO = beberapa baris SJ;
        //  - SJ menggabung per produk (SalesDeliveryService::addNeeded), jadi pesanan Jubelio
        //    yang memecah "qty 5" jadi 5 baris SO @1 keluar sebagai SATU baris SJ qty 5 yang
        //    menunjuk baris SO pertama saja.
        // Maka qty terkirim dikumpulkan per produk lalu dibagikan ulang ke baris-baris SO.
        $sjPosted = \App\Modules\Sales\Models\SalesDelivery::query()
            ->where('sales_order_id', $so->id)->where('status', 'posted')->pluck('id');
        $stokKeluar = \App\Modules\Sales\Models\SalesDeliveryItem::query()
            ->whereIn('sales_delivery_id', $sjPosted)
            ->selectRaw('product_id, SUM(qty) q')
            ->groupBy('product_id')
            ->pluck('q', 'product_id')
            ->map(fn ($q) => (float) $q)
            ->all();

        $items = [];
        $subtotal = 0.0;
        foreach ($so->items as $soItem) {
            // Sebesar yang sudah dikirim & belum difakturkan — bukan sisa qty pesanan. Jasa /
            // non-stok tak pernah lewat SJ: ikut penuh begitu ada pengiriman.
            $dikirim = in_array($soItem->product?->sale_type, ['service', 'non_stock'], true)
                ? ($sjPosted->isNotEmpty() ? (float) $soItem->qty : 0.0)
                : $this->ambilDariStokKeluar($soItem, $stokKeluar);
            $remaining = $dikirim - (float) $soItem->qty_invoiced;
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
            return null;
        }

        // Biaya admin marketplace SENGAJA 0 di sini — lihat docblock. Yang ikut hanyalah biaya
        // tambahan yang sudah melekat di SO (mis. biaya layanan yang menambah nilai pesanan),
        // supaya nilai faktur sama persis dengan nilai kotor SO.
        $marketplaceFee = 0.0;
        $additionalFee  = (float) ($so->additional_fee ?? 0);

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

        // Tandai KONVENSI BARU sebelum posting: grand_total kotor & fee menyusul saat
        // settlement. InvoicePostingService membaca penanda ini untuk melewatkan pemanggilan
        // marketplace engine, dan rekonsiliasi membacanya untuk menghitung nilai jual.
        $invoice->forceFill(['fee_at_settlement' => true])->save();

        app(\App\Services\InvoicePostingService::class)->post($invoice);

        return $invoice->fresh();
    }

    /**
     * Ambil jatah baris SO dari kumpulan stok keluar SO (per produk) dan kurangi kumpulannya,
     * supaya baris berikutnya dengan produk yang sama hanya mendapat sisanya. Hasil tak pernah
     * melebihi qty baris SO — faktur tak boleh lebih dari yang dipesan.
     *
     * Bundle: jatahnya = komponen yang paling sedikit tersedia dibagi takarannya per bundle —
     * bundle baru dianggap terkirim kalau semua komponennya ikut. Komponen yang sama sekali tak
     * muncul di SJ SO ini diabaikan (resep bundle diubah setelah SJ dibuat); bila tak satu pun
     * muncul, bundle dianggap terkirim penuh daripada fakturnya tak pernah terbit.
     *
     * @param array<int,float> $stokKeluar product_id => qty keluar yang belum dibagikan
     */
    private function ambilDariStokKeluar(SalesOrderItem $soItem, array &$stokKeluar): float
    {
        $qty = (float) $soItem->qty;

        if ($soItem->product?->sale_type !== 'bundle') {
            $ambil = min($qty, max(0.0, $stokKeluar[$soItem->product_id] ?? 0.0));
            if ($ambil > 0) {
                $stokKeluar[$soItem->product_id] -= $ambil;
            }
            return $ambil;
        }

        $komponen = \App\Core\Inventory\BundleComponent::where('bundle_product_id', $soItem->product_id)
            ->pluck('qty', 'component_product_id');
        if ($komponen->isEmpty()) {
            $komponen = \App\Core\Inventory\ProductBundle::where('bundle_product_id', $soItem->product_id)
                ->pluck('qty_required', 'component_product_id');
        }
        $komponen = $komponen->filter(fn ($t, $pid) => (float) $t > 0 && isset($stokKeluar[$pid]));

        if ($komponen->isEmpty()) {
            return $qty;
        }

        $unit = $qty;
        foreach ($komponen as $pid => $takaran) {
            $unit = min($unit, floor(round(max(0.0, $stokKeluar[$pid]) / (float) $takaran, 4)));
        }
        foreach ($komponen as $pid => $takaran) {
            $stokKeluar[$pid] -= $unit * (float) $takaran;
        }

        return $unit;
    }

    /**
     * Lepas Saldo Ditahan ke Wallet & bebankan biaya admin — untuk faktur GAYA BARU.
     *
     * Faktur gaya baru terbit saat PENGIRIMAN mengikuti Sales Order persis: nilainya kotor dan
     * biaya adminnya belum dibebankan, karena saat itu marketplace belum memotong apa pun.
     * Begitu pesanan dinyatakan SELESAI, barulah potongannya diketahui dan settlement dijalankan:
     *
     *     Dr Wallet (sisa hold − fee) + Dr Beban Admin (fee) / Cr Saldo Ditahan (sisa hold)
     *
     * Fee di sini masih TAKSIRAN dari data Jubelio; selisihnya terhadap potongan sebenarnya
     * dikoreksi saat rekonsiliasi settlement lewat `feeDiff`.
     *
     * Faktur gaya LAMA dilewati: fee-nya sudah dibebankan di jurnal faktur dan settlement-nya
     * sudah jalan saat faktur diposting.
     */
    private function ensureSettlement(array $detail, JubelioOrderLink $link): void
    {
        $invoice = \App\Models\SalesInvoice::where('sales_order_id', $link->sales_order_id)
            ->where('status', '!=', 'void')
            ->latest('id')->first();

        if (!$invoice || !$invoice->fee_at_settlement || $invoice->marketplace_processed) {
            return;
        }

        $so = SalesOrder::find($link->sales_order_id);
        if (!$so) {
            return;
        }

        $subtotal   = (float) $so->subtotal;
        $shipping   = (float) ($detail['shipping_cost'] ?? $so->shipping_cost ?? 0);
        $grandTotal = (float) ($detail['grand_total'] ?? ($subtotal + $shipping));
        $fee        = $this->resolveMarketplaceFee($subtotal, $shipping, $grandTotal, (int) $so->customer_id)['fee'];

        app(\App\Modules\Sales\Services\MarketplaceEngineService::class)->handle($invoice, $fee);

        JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::OK, 'Pesanan ' . ($link->jubelio_salesorder_no ?: $link->jubelio_salesorder_id), [
            'reference'             => $link->jubelio_salesorder_no,
            'jubelio_salesorder_id' => $link->jubelio_salesorder_id,
            'message'               => 'Pesanan selesai → saldo ditahan dilepas ke wallet, biaya admin ' . number_format($fee, 0, ',', '.') . ' dibebankan.',
            'meta'                  => ['invoice_id' => $invoice->id, 'fee' => $fee],
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

        // Retur DIIKATKAN KE FAKTUR bila ada — dan sejak faktur terbit saat pengiriman,
        // pesanan yang barangnya sudah keluar pasti punya faktur.
        //
        // Penting untuk jurnalnya: retur atas FAKTUR membalik Penjualan & memindahkan HPP yang
        // memang sudah dibukukan faktur. Retur atas SO membalik Uang Muka dan mengkredit HPP
        // yang belum tentu pernah ada — itulah yang dulu membuat HPP jadi minus. Jalur SO tetap
        // dipertahankan sebagai cadangan (pesanan lama / non-marketplace).
        $invoice = \App\Models\SalesInvoice::with('items')
            ->where('sales_order_id', $so->id)
            ->whereNotIn('status', ['void', 'cancelled'])
            ->latest('id')
            ->first();

        $doc = $invoice ?: $so;

        // Map tiap baris retur Jubelio (item_id, qty) ke baris dokumen ERP.
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
            $docItem = $doc->items->firstWhere('product_id', $product->id);
            if (!$docItem) {
                continue;
            }
            $items[] = [
                'invoice_item_id' => $docItem->id, // getDoc() mencari by id baris dokumen induk
                'qty'             => min($qty, (float) $docItem->qty),
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
            invoice_id: $invoice?->id,
            sales_order_id: $invoice ? null : $so->id,
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
            // Jubelio kadang mengirim qty=0 (string "0.0000", BUKAN null) padahal qty_in_base
            // berisi jumlah sebenarnya (mis. order TikTok TT-...927646: qty 0, qty_in_base 1).
            // `??` tak menolong krn qty hadir & bernilai 0 → pakai qty_in_base sbg fallback bila
            // qty<=0, agar order tak terbuang sbg "tanpa item yang dapat diproses".
            $qty    = (float) ($it['qty'] ?? 0);
            if ($qty <= 0) {
                $qty = (float) ($it['qty_in_base'] ?? 0);
            }
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
     *               nilai faktur = nilai bersih. Selisih estimasi vs aktual direkonsiliasi
     *               nanti saat settlement (akun fee_diff).
     *
     * CATATAN: `grand_total` yang dikembalikan (= nilai BERSIH) hanya dipakai FAKTUR.
     * Sales Order sengaja memakai nilai KOTOR (subtotal + ongkir + expense) supaya DP kotor
     * dan retur menutup rapi — lihat komentar panjang di titik pembuatan SO.
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

    /**
     * KAPAN pesanan itu selesai menurut marketplace.
     *
     * `received_date` = tanggal pesanan diterima pembeli menurut channel; itulah
     * yang dicari. Kalau detailnya tak menyebutkannya (pesanan yang ditandai
     * selesai manual, atau channel yang tidak mengirim tanggalnya), pemanggil
     * memakai `now()` — saat kita PERTAMA KALI melihatnya selesai. Meleset
     * paling jauh sebesar jeda cron, dan itu jauh lebih dekat daripada tanggal
     * faktur yang untuk marketplace terbit di muka.
     *
     * Jubelio mengirim waktu UTC (akhiran "Z"), jadi dikonversi ke zona app
     * dulu — tanpa itu pesanan yang selesai 17:00–23:59 WIB tercatat mundur
     * satu hari, dan di grafik harian satu hari itu terlihat.
     */
    private function tanggalSelesaiMarketplace(array $d): ?\Carbon\Carbon
    {
        $raw = $d['received_date'] ?? $d['completed_date'] ?? $d['complete_date'] ?? null;

        if (empty($raw)) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($raw)->timezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Pesanan DIRETUR pembeli (RETURNED/TO_RETURN). Tanpa cek ini, order retur yang masih
     * punya resi jatuh ke 'processed' → nyangkut di "Telah Diproses". Diprioritaskan di atas
     * completed/shipped agar retur (termasuk yang terjadi setelah barang diterima) muncul di
     * tab "Retur", bukan tersembunyi di bucket lain.
     */
    private function isReturned(array $d): bool
    {
        $in = strtoupper(trim((string) ($d['internal_status'] ?? '')));
        if ($in === 'RETURNED') {
            return true;
        }
        $ch = strtoupper(trim((string) ($d['channel_status'] ?? '')));
        if (in_array($ch, ['TO_RETURN', 'TO RETURN', 'RETURNED'], true)) {
            return true;
        }
        return strtoupper(trim((string) ($d['wms_status'] ?? ''))) === 'RETURNED';
    }

    private function statusLabel(array $d): string
    {
        if ($this->isReturned($d))  return 'returned';  // diretur pembeli → tab "Retur"
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
