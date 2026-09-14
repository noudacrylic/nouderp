<?php

namespace App\Modules\POS\Services;

use App\Modules\CRM\Models\CrmOutboxMessage;
use App\Modules\Sales\Models\SalesOrder;

/**
 * Penanda "barang pesanan ambil-di-toko sudah siap diambil".
 *
 * Satu-satunya yang dilakukan di sini adalah menstempel `ready_at`. Notifikasi ke pembeli
 * TIDAK dipanggil dari sini — CrmSalesOrderObserver mengamati kolom itu, persis seperti
 * notifikasi pembayaran mengamati `paid_amount`. Dengan begitu tombol manual, pemindaian
 * otomatis, dan jalur apa pun di masa depan mustahil berbeda perilaku.
 *
 * Dipakai dari dua tempat: tombol di kartu Pemrosesan Pesanan, dan command
 * `pos:pindai-siap-diambil` yang jalan tiap 15 menit.
 */
class PickupReadyService
{
    /**
     * Bucket yang berarti "barangnya sudah ada di tangan kita".
     *
     * `belum_lunas` ikut dengan sengaja: untuk pesanan ambil-di-toko, melunasi di kasir saat
     * mengambil barang adalah hal yang normal. Menahan kabarnya sampai lunas berarti pembeli
     * tidak pernah tahu barangnya sudah menunggu.
     */
    private const BUCKET_SIAP = ['perlu_diproses', 'belum_lunas'];

    public function __construct(private FulfillmentReadinessService $kesiapan)
    {
    }

    /**
     * Tandai satu pesanan siap diambil. False = tidak ada yang berubah (bukan pesanan
     * ambil-toko, sudah pernah ditandai, atau barangnya sudah diambil).
     */
    public function tandaiSiap(SalesOrder $so, ?int $userId = null): bool
    {
        if (! $so->isPickup() || $so->ready_at !== null || $so->pickup_status === 'picked_up') {
            return false;
        }

        $so->forceFill(['ready_at' => now(), 'ready_by' => $userId])->save();

        return true;
    }

    /**
     * Tarik kembali penandaan siap.
     *
     * Notifikasi yang BELUM berangkat ikut dihapus dari antrean — salah tandai yang langsung
     * diperbaiki sebaiknya tidak menyisakan pesan yang tetap terkirim 5 menit kemudian. Yang
     * sudah terkirim dibiarkan: barisnya adalah catatan bahwa pembeli memang sudah dikabari,
     * dan kunci dedupe-nya sekaligus mencegah pesan kedua saat pesanan ditandai siap lagi.
     */
    public function batalSiap(SalesOrder $so): void
    {
        $so->forceFill(['ready_at' => null, 'ready_by' => null])->save();

        CrmOutboxMessage::where('dedupe_key', "so:{$so->id}:siap_diambil")
            ->where('status', CrmOutboxMessage::STATUS_MENUNGGU)
            ->delete();
    }

    /**
     * Pindai antrean kerja: pesanan ambil-di-toko yang barangnya sudah siap tapi belum
     * ditandai. Inilah jalur otomatisnya — tombol manual di kartu hanya mempercepat, atau
     * dipakai untuk pesanan yang kesiapannya tidak bisa disimpulkan ERP (mis. gerbang
     * produksi yang dibebaskan paksa).
     *
     * Kesiapan TIDAK dihitung ulang di sini: yang dipakai adalah bucket dari
     * FulfillmentReadinessService, sumber kebenaran yang sama dengan yang dilihat operator
     * di layar. Menyalin aturannya ke sini cuma menciptakan versi kedua yang akan menyimpang.
     *
     * @return array{ditandai:int, nomor:string[]}
     */
    public function pindaiOtomatis(): array
    {
        $ids = collect(self::BUCKET_SIAP)
            ->flatMap(fn (string $bucket) => $this->kesiapan->bucket($bucket)
                ->where('is_pickup', true)
                ->pluck('id'))
            ->unique();

        if ($ids->isEmpty()) {
            return ['ditandai' => 0, 'nomor' => []];
        }

        $nomor = [];

        foreach (SalesOrder::whereIn('id', $ids)->whereNull('ready_at')->get() as $so) {
            if ($this->tandaiSiap($so)) {
                $nomor[] = $so->order_number;
            }
        }

        return ['ditandai' => count($nomor), 'nomor' => $nomor];
    }
}
