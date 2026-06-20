<?php

namespace App\Modules\Marketplace\Jubelio\Services;

use App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink;
use App\Modules\Marketplace\Jubelio\Models\JubelioSetting;
use App\Modules\Marketplace\Jubelio\Models\JubelioSyncLog;
use Illuminate\Support\Facades\Log;

/**
 * Menjalankan rantai fulfillment WMS Jubelio dari "Pemrosesan Pesanan":
 *   1. ready-to-pick  2. items-to-pick + picklist auto-complete  3. mark-as-complete (packing)
 *   4. create-invoice (faktur Jubelio)  5. request-awb (resi/AWB)
 *
 * IDEMPOTEN + RESUME: tiap step punya flag di jubelio_order_links. Step yang flag-nya
 * sudah true akan dilewati, jadi re-klik "Proses" melanjutkan dari step yang gagal.
 * Klaim flag dilakukan ATOMIK sebelum panggil API (where(flag,false)->update(flag,true));
 * bila API gagal flag dilepas lagi → dua klik paralel tak menjalankan step yang sama dobel.
 *
 * BATAS TEGAS: service ini HANYA memajukan sisi Jubelio + mengambil resi. Ia TIDAK membuat
 * Surat Jalan / Invoice / jurnal ERP — akuntansi ERP tetap ditangani JubelioOrderSyncService
 * (Tahap A/B/C). Step create-invoice di sini = faktur Jubelio (dokumen marketplace), bukan
 * SalesInvoice ERP.
 */
class JubelioFulfillmentService
{
    public function __construct(private JubelioClient $client)
    {
    }

    /**
     * Jalankan seluruh rantai untuk satu pesanan. Resume otomatis dari step yang belum selesai.
     *
     * @return array{success:bool, message:string, stage:string, tracking_no:?string}
     */
    public function process(JubelioOrderLink $link): array
    {
        if (!$this->client->isReady()) {
            return $this->result($link, false, 'Integrasi Jubelio belum aktif/terkonfigurasi (Settings → Jubelio).');
        }

        $soId = (int) $link->jubelio_salesorder_id;
        $ref  = $link->jubelio_salesorder_no ?: (string) $soId;

        // Step 0 — picker WMS wajib diatur.
        $picker = $this->pickerEmail();
        if (empty($picker)) {
            return $this->fail($link, 'Email picker WMS belum diatur di Settings → Jubelio.');
        }

        // Step 1 — ready to pick.
        if (!$link->j_ready_to_pick) {
            if (!$this->claim($link, 'j_ready_to_pick')) {
                return $this->result($link, false, 'Pesanan sedang diproses oleh proses lain.');
            }
            $res = $this->client->postReadyToPick([$soId]);
            if (!$res['success']) {
                return $this->release($link, 'j_ready_to_pick', "Gagal memindahkan ke antrian pick: {$res['error']}");
            }
        }

        // Step 2 — buat picklist (auto-complete: pick + selesai sekaligus).
        if (!$link->j_picklist_done) {
            if (!$this->claim($link, 'j_picklist_done')) {
                return $this->result($link, false, 'Pesanan sedang diproses oleh proses lain.');
            }
            $items = $this->buildPicklistItems($soId, $pickErr);
            if ($items === null) {
                return $this->release($link, 'j_picklist_done', 'Gagal mengambil daftar item untuk picking dari Jubelio: ' . ($pickErr ?: 'tidak diketahui') . '. Klik "Proses" lagi untuk mengulang.');
            }
            if (empty($items)) {
                return $this->release($link, 'j_picklist_done', 'Tidak ada item yang bisa dipick untuk pesanan ini.');
            }
            $payload = [
                'picklist_id'   => 0,
                'picklist_no'   => '[auto]',
                'is_completed'  => true,
                'is_warehouse'  => true,
                'merge_location'=> false,
                'picker_id'     => $picker,
                'salesorderIds' => [$soId],
                'items'         => $items,
            ];
            $res = $this->client->postPicklistAutoComplete($payload);
            if (!$res['success']) {
                return $this->release($link, 'j_picklist_done', "Gagal membuat picking list: {$res['error']}");
            }
            // invalidSO terisi → Jubelio menolak SO ini.
            $invalid = data_get($res, 'data.data.invalidSO', data_get($res, 'data.invalidSO', []));
            if (!empty($invalid)) {
                return $this->release($link, 'j_picklist_done', 'Jubelio menolak picking SO ini (invalidSO) — cek stok/lokasi di Jubelio.');
            }
        }

        // Step 3 — tandai selesai packing (ready to ship).
        if (!$link->j_packed) {
            if (!$this->claim($link, 'j_packed')) {
                return $this->result($link, false, 'Pesanan sedang diproses oleh proses lain.');
            }
            $res = $this->client->postPacklistMarkComplete([$soId]);
            if (!$res['success']) {
                return $this->release($link, 'j_packed', "Gagal menandai selesai packing: {$res['error']}");
            }
        }

        // Step 4 — buat faktur Jubelio.
        if (!$link->j_invoice_done) {
            if (!$this->claim($link, 'j_invoice_done')) {
                return $this->result($link, false, 'Pesanan sedang diproses oleh proses lain.');
            }
            $res = $this->postCreateInvoiceWithRetry($soId);
            if (!$res['success']) {
                return $this->release($link, 'j_invoice_done', "Gagal membuat faktur Jubelio: {$res['error']}. Klik \"Proses\" lagi untuk melanjutkan (faktur tidak akan dobel).");
            }
            $invId = (int) (data_get($res, 'data.id') ?: 0);
            $link->forceFill(['j_invoice_id' => $invId ?: null])->save();
        }

        // Step 5 — dapatkan resi/AWB.
        if (!$link->awb_requested) {
            if (!$this->claim($link, 'awb_requested')) {
                return $this->result($link, false, 'Pesanan sedang diproses oleh proses lain.');
            }

            // 5a. Channel via agregator kurir Jubelio (Shopee/Tokopedia/dll): request-awb langsung
            //     mengembalikan nomor resi.
            $res      = $this->client->requestAwb($soId);
            $tracking = null;
            $shipper  = null;

            if ($res['success']) {
                $tracking = data_get($res, 'data.tracking_no');
                $shipper  = data_get($res, 'data.shipper');
            } else {
                // 5b. Channel yang menerbitkan resi SENDIRI (mis. TikTok `use_tiktok_label`): request-awb
                //     ditolak "Kurir tidak di support". Picu pembuatan label (setara klik "Cetak Resi" di
                //     Seller Center) — ini yang membuat marketplace menerbitkan resi — lalu baca resinya
                //     dari detail order (andal; endpoint shipments mengosongkan baris setelah resi terbit).
                $hasShipment = !empty(data_get($this->client->getShipmentAwb([$soId]), 'data.0'));
                $label       = $this->client->getShippingLabelUrl($soId);
                [$tracking, $shipper] = $this->trackingFromOrder($soId);

                // Tak ada resi, tak ada shipment, label pun gagal → bukan order channel-issued: gagal nyata.
                if ($tracking === null && !$hasShipment && !$label['success']) {
                    return $this->release($link, 'awb_requested', "Faktur sudah dibuat, tetapi AWB/resi belum terbentuk: {$res['error']}. Klik \"Proses\" lagi untuk membentuk AWB (langkah sebelumnya tidak diulang).");
                }
            }

            $link->forceFill([
                'tracking_no'      => $tracking ?: null,
                'shipper'          => $shipper ?: $link->shipper,
                'wms_last_error'   => null,
                'wms_completed_at' => $tracking ? now() : null,
            ])->save();

            if (empty($tracking)) {
                // Resi marketplace kadang terbit async setelah label dipicu — biarkan flag tetap true;
                // step 5c + cron self-heal menangkapnya begitu Jubelio selesai sync dari channel.
                JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::OK, "Fulfillment {$ref}", [
                    'reference' => $ref, 'jubelio_salesorder_id' => $soId,
                    'message'   => 'Picking→faktur→label selesai; resi sedang diterbitkan marketplace.',
                ]);
                return $this->result($link, true, "Pesanan {$ref} diproses. Resi sedang diterbitkan marketplace — akan masuk otomatis (cron) atau klik Cetak Resi sebentar lagi.");
            }
        }

        // Step 5c — resi sering terbit BELAKANGAN (channel async): AWB sudah diproses tapi nomor
        // resi belum tercatat (step 5 dilewati karena awb_requested sudah true). Tarik ulang
        // dari detail order Jubelio agar klik "Generate Ulang" menangkap resi yang sudah keluar.
        if ($link->awb_requested && empty($link->tracking_no)) {
            $od       = $this->client->getOrder($soId);
            $tracking = data_get($od, 'data.tracking_no') ?: data_get($od, 'data.tracking_number');
            if (!empty($tracking)) {
                $link->forceFill([
                    'tracking_no'      => $tracking,
                    'shipper'          => data_get($od, 'data.shipper') ?: $link->shipper,
                    'wms_last_error'   => null,
                    'wms_completed_at' => now(),
                ])->save();
            } else {
                return $this->result($link, true, "Pesanan {$ref} diproses. Resi belum terbit dari kurir — coba lagi beberapa saat.");
            }
        }

        JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::OK, "Fulfillment {$ref}", [
            'reference' => $ref, 'jubelio_salesorder_id' => $soId,
            'message'   => 'Picking → faktur → resi selesai. Resi: ' . ($link->tracking_no ?: '-'),
        ]);

        return $this->result($link, true, "Pesanan {$ref} berhasil diproses. Resi: " . ($link->tracking_no ?: '—'));
    }

    /**
     * Ambil daftar item picking dari Jubelio & susun jadi payload picklist auto-complete.
     * Mengembalikan null bila API gagal, [] bila tak ada item.
     */
    private function buildPicklistItems(int $soId, ?string &$error = null): ?array
    {
        $res = $this->client->postItemsToPick([$soId]);
        if (!$res['success']) {
            $error = $res['error'] ?? ('HTTP ' . ($res['status'] ?? '?'));
            return null;
        }
        $rows = is_array($res['data']) ? $res['data'] : [];
        $defaultLoc = (int) (JubelioSetting::singleton()->default_location_id ?: 0);

        $items = [];
        foreach ($rows as $row) {
            $loc = (int) ($row['location_id'] ?? 0);
            if ($loc <= 0) {                  // -1 = "Pusat"/belum spesifik → pakai lokasi default setting
                $loc = $defaultLoc;
            }
            $qty = (float) ($row['qty_ordered'] ?? 0);
            $items[] = [
                'salesorder_detail_id' => (int) ($row['salesorder_detail_id'] ?? 0),
                'item_id'              => (int) ($row['item_id'] ?? 0),
                'location_id'          => $loc,
                'qty_ordered'          => $qty,
                'qty_picked'           => $qty,   // auto-complete: anggap semua terpick
                'salesorder_id'        => (int) ($row['salesorder_id'] ?? $soId),
                'bundle_item_id'       => (int) ($row['bundle_item_id'] ?? 0),
                'package_detail_id'    => 0,
                'package_id'           => 0,
            ];
        }

        return $items;
    }

    /**
     * Buat faktur Jubelio dengan retry pada error transien. Jubelio kadang balas 5xx bila
     * create-invoice dipanggil tepat setelah "mark-as-complete packing" (server belum tuntas
     * memproses packlist). Endpoint ini IDEMPOTEN — mengulang tidak membuat faktur dobel
     * (mengembalikan invoice id yang sama). Hanya retry pada 5xx / timeout (status 0);
     * 4xx = penolakan permanen, jangan diulang.
     */
    private function postCreateInvoiceWithRetry(int $soId, int $attempts = 3): array
    {
        $res = ['success' => false, 'status' => 0, 'data' => null, 'error' => 'tidak dipanggil'];
        for ($i = 1; $i <= $attempts; $i++) {
            $res = $this->client->postCreateInvoice($soId);
            if ($res['success']) {
                return $res;
            }
            $status = (int) ($res['status'] ?? 0);
            if ($status >= 400 && $status < 500) {
                break; // penolakan permanen — percuma diulang
            }
            if ($i < $attempts) {
                usleep(1_500_000); // jeda 1,5 dtk sebelum percobaan berikutnya
            }
        }
        return $res;
    }

    /**
     * Resi + shipper dari detail order Jubelio. tracking null bila resi belum terbit.
     * Dipakai setelah memicu label: detail order adalah sumber resi yang andal (endpoint WMS
     * shipments mengosongkan baris begitu resi terbit & order keluar dari antrian "ready to ship").
     *
     * @return array{0:?string, 1:?string} [tracking_no, shipper]
     */
    private function trackingFromOrder(int $soId): array
    {
        $d  = $this->client->getOrder($soId)['data'] ?? [];
        $tn = trim((string) ($d['tracking_no'] ?? $d['tracking_number'] ?? ''));
        $sh = trim((string) ($d['shipper'] ?? ''));

        return [$tn !== '' ? $tn : null, $sh ?: null];
    }

    private function pickerEmail(): ?string
    {
        $config = JubelioSetting::singleton()->config ?? [];
        $email  = $config['wms_picker_email'] ?? null;
        return is_string($email) && trim($email) !== '' ? trim($email) : null;
    }

    /**
     * Klaim atomik sebuah flag step. true bila berhasil mengklaim ATAU flag memang sudah
     * true (step selesai oleh proses lain → boleh lanjut). false hanya bila baris hilang.
     */
    private function claim(JubelioOrderLink $link, string $flag): bool
    {
        $claimed = JubelioOrderLink::where('id', $link->id)
            ->where($flag, false)
            ->update([$flag => true]);

        if ($claimed) {
            $link->{$flag} = true;
            return true;
        }

        // Tidak terklaim → mungkin sudah true (selesai), atau baris hilang.
        $fresh = JubelioOrderLink::find($link->id);
        if ($fresh && $fresh->{$flag}) {
            $link->{$flag} = true;
            return true;
        }
        return false;
    }

    /** Lepas klaim flag + catat error; kembalikan hasil gagal. */
    private function release(JubelioOrderLink $link, string $flag, string $error): array
    {
        JubelioOrderLink::where('id', $link->id)->update([
            $flag            => false,
            'wms_last_error' => $error,
        ]);
        $link->{$flag} = false;
        $link->wms_last_error = $error;

        return $this->fail($link, $error, false);
    }

    private function fail(JubelioOrderLink $link, string $error, bool $persist = true): array
    {
        if ($persist) {
            JubelioOrderLink::where('id', $link->id)->update(['wms_last_error' => $error]);
            $link->wms_last_error = $error;
        }
        JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::FAIL, 'Fulfillment ' . ($link->jubelio_salesorder_no ?: $link->jubelio_salesorder_id), [
            'reference'             => $link->jubelio_salesorder_no,
            'jubelio_salesorder_id' => (int) $link->jubelio_salesorder_id,
            'message'               => $error,
            'meta'                  => ['stage' => $link->wmsStage()],
        ]);
        Log::warning('Jubelio fulfillment gagal', ['salesorder_id' => $link->jubelio_salesorder_id, 'error' => $error]);

        return $this->result($link, false, $error);
    }

    private function result(JubelioOrderLink $link, bool $success, string $message): array
    {
        return [
            'success'     => $success,
            'message'     => $message,
            'stage'       => $link->wmsStage(),
            'tracking_no' => $link->tracking_no,
        ];
    }
}
