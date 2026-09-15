<?php

namespace App\Modules\POS\Services;

use App\Modules\CRM\Models\CrmOutboxMessage;
use App\Modules\CRM\Services\OrderNotificationService;
use App\Modules\Sales\Models\SalesOrder;

/**
 * Kabari pembeli bahwa barang pesanan KIRIM-nya sudah siap dan tinggal dilunasi.
 *
 * Noud mengirim barang hanya setelah lunas (kecuali tempo), jadi pesanan di bucket
 * "Belum Lunas" benar-benar diam menunggu pembeli — dan pembeli sering tidak tahu
 * barangnya sudah jadi. Pesannya tanpa tautan bayar; lihat TemplateResmi 'tagihan_pelunasan'.
 *
 * Seperti PickupReadyService: kesiapan TIDAK dihitung ulang di sini, melainkan diambil dari
 * bucket FulfillmentReadinessService — sumber yang sama dengan yang dilihat operator.
 * Dipakai dari dua tempat dengan jalan yang sama: command `pos:pindai-pelunasan` (tiap
 * 15 menit) dan tombol "Kabari Pelunasan" di kartu.
 *
 * Ambil-di-toko TIDAK ikut: pembelinya sudah dapat kabar "siap diambil", dan melunasi di
 * kasir saat mengambil barang itu hal biasa — tagihan tambahan cuma terasa menekan.
 */
class PelunasanNoticeService
{
    public function __construct(
        private FulfillmentReadinessService $kesiapan,
        private OrderNotificationService $notifikasi,
    ) {
    }

    /**
     * Antrekan kabar pelunasan untuk satu pesanan.
     *
     * @return array{0:bool, 1:string} [berhasil diantrekan, keterangan]
     */
    public function kabari(SalesOrder $so, bool $manual = false): array
    {
        if ($so->customer?->is_marketplace) {
            return [false, "Pesanan {$so->order_number} marketplace — pembeli dikabari platformnya."];
        }
        if ($so->status !== 'confirmed') {
            return [false, "Pesanan {$so->order_number} tidak berstatus confirmed."];
        }
        if ($so->isPickup()) {
            return [false, "Pesanan {$so->order_number} diambil di toko — pelunasannya di kasir."];
        }
        if ($so->is_tempo) {
            return [false, "Pesanan {$so->order_number} tempo — boleh dikirim sebelum lunas."];
        }

        $sisa = round((float) $so->grand_total - (float) $so->paid_amount, 2);

        if ($sisa <= 0.01) {
            return [false, "Pesanan {$so->order_number} sudah lunas."];
        }
        if ((float) $so->paid_amount <= 0.01) {
            // Belum ada DP sama sekali = bucket "Belum Bayar", yang ditagih BillingReminderService.
            return [false, "Pesanan {$so->order_number} belum menerima DP."];
        }

        /*
         * Tombol manual boleh mencoba lagi kabar yang dulu DILEWATI (nomor kosong, jenis
         * dimatikan) — penyebabnya biasanya sudah dibereskan admin sebelum menekan tombol.
         * Pemindaian otomatis tidak: ia akan menghapus-membuat baris yang sama tiap 15 menit.
         */
        if ($manual) {
            CrmOutboxMessage::where('dedupe_key', sprintf('so:%d:pelunasan:%d', $so->id, (int) round($sisa * 100)))
                ->where('status', CrmOutboxMessage::STATUS_DILEWATI)
                ->delete();
        }

        $baris = $this->notifikasi->antrekanPelunasan($so, $sisa);

        if (! $baris) {
            return [false, "Pembeli {$so->order_number} sudah pernah dikabari untuk sisa Rp "
                . number_format($sisa, 0, ',', '.') . '.'];
        }

        if ($baris->status === CrmOutboxMessage::STATUS_DILEWATI) {
            return [false, "Tidak dikirim: {$baris->reason}"];
        }

        return [true, "Pembeli {$so->order_number} dikabari untuk pelunasan (menyesuaikan jam buka toko)."];
    }

    /**
     * Pindai bucket "Belum Lunas": kabari pesanan kirim yang belum pernah dikabari
     * untuk sisa tagihannya saat ini.
     *
     * @return array{dikabari:int, nomor:string[]}
     */
    public function pindaiOtomatis(): array
    {
        $ids = $this->kesiapan->bucket('belum_lunas')
            ->where('is_pickup', false)
            ->pluck('id');

        $nomor = [];

        foreach (SalesOrder::with('customer')->whereIn('id', $ids)->get() as $so) {
            [$ok] = $this->kabari($so);

            if ($ok) {
                $nomor[] = $so->order_number;
            }
        }

        return ['dikabari' => count($nomor), 'nomor' => $nomor];
    }

    /** Kabar pelunasan TERAKHIR untuk pesanan ini (ditampilkan di kartu), atau null. */
    public static function terakhir(int $salesOrderId): ?CrmOutboxMessage
    {
        return CrmOutboxMessage::where('sales_order_id', $salesOrderId)
            ->where('event', CrmOutboxMessage::EVENT_PELUNASAN)
            ->latest('id')
            ->first();
    }
}
