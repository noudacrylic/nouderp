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
            $items = $this->buildPicklistItems($soId);
            if ($items === null) {
                return $this->release($link, 'j_picklist_done', 'Gagal mengambil daftar item untuk picking dari Jubelio.');
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
            $res = $this->client->postCreateInvoice($soId);
            if (!$res['success']) {
                return $this->release($link, 'j_invoice_done', "Gagal membuat faktur Jubelio: {$res['error']}");
            }
            $invId = (int) (data_get($res, 'data.id') ?: 0);
            $link->forceFill(['j_invoice_id' => $invId ?: null])->save();
        }

        // Step 5 — minta resi/AWB.
        if (!$link->awb_requested) {
            if (!$this->claim($link, 'awb_requested')) {
                return $this->result($link, false, 'Pesanan sedang diproses oleh proses lain.');
            }
            $res = $this->client->requestAwb($soId);
            if (!$res['success']) {
                return $this->release($link, 'awb_requested', "Gagal meminta resi (AWB): {$res['error']}");
            }
            $tracking = data_get($res, 'data.tracking_no');
            $shipper  = data_get($res, 'data.shipper');
            $link->forceFill([
                'tracking_no'      => $tracking ?: null,
                'shipper'          => $shipper ?: null,
                'wms_last_error'   => null,
                'wms_completed_at' => $tracking ? now() : null,
            ])->save();

            if (empty($tracking)) {
                // AWB diminta tapi resi belum keluar (mis. kurir belum siap) — biarkan flag
                // tetap true; user bisa cek lagi nanti via Cetak Resi/print yang ambil URL.
                JubelioSyncLog::record(JubelioSyncLog::TYPE_ORDER, JubelioSyncLog::OK, "Fulfillment {$ref}", [
                    'reference' => $ref, 'jubelio_salesorder_id' => $soId,
                    'message'   => 'Picking→faktur→AWB selesai; nomor resi belum tersedia dari kurir.',
                ]);
                return $this->result($link, true, "Pesanan {$ref} diproses. Resi belum terbit dari kurir — coba Cetak Resi beberapa saat lagi.");
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
    private function buildPicklistItems(int $soId): ?array
    {
        $res = $this->client->postItemsToPick([$soId]);
        if (!$res['success']) {
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
