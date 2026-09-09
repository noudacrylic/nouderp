<?php

namespace App\Modules\CRM\Services;

use App\Core\Inventory\BundleComponent;
use App\Core\Inventory\Product;
use App\Core\Inventory\StockReservation;
use App\Models\MidtransTransaction;
use App\Modules\CRM\Models\CrmOutboxMessage;
use App\Modules\CRM\Support\JenisNotifikasi;
use App\Modules\Payment\Services\PaymentLinkService;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Menagih pesanan yang tautan bayarnya sudah dibuat tapi belum dibayar — dan
 * membatalkannya kalau tak dibayar juga.
 *
 * Dua kerugian yang sama-sama nyata dijaga sekaligus di sini. Pesanan yang
 * menggantung MENGUNCI STOK lewat reservasinya: barang yang sebenarnya bisa
 * dijual ke orang lain tertahan berminggu-minggu demi pembeli yang sudah lama
 * hilang. Sebaliknya, membatalkan terlalu cepat membuang pembeli sungguhan
 * yang cuma menunggu gajian. Karena itu jadwalnya rapat di depan lalu
 * merenggang, dan pembatalannya diberi tahu jauh-jauh hari — bukan mendadak.
 *
 * Seperti OrderNotificationService, tugas menagihnya berhenti di ANTREAN;
 * pengiriman urusan CrmOutboxSender. Pembatalannya TIDAK: ia menyentuh stok
 * dan status pesanan, jadi ia dikerjakan di sini, dalam transaksi, dengan
 * pemeriksaan ulang di dalam kunci baris.
 */
class BillingReminderService
{
    public function __construct(
        private OrderNotificationService $notifikasi,
        private PaymentLinkService $tautan,
    ) {
    }

    /** @return int[] hari ke berapa saja tagihan dikirim, menaik */
    public function jadwal(): array
    {
        $hari = array_values(array_unique(array_filter(
            (array) config('crm.tagihan.hari_kirim', [1, 2, 3, 10, 17, 24]),
            fn ($h) => (int) $h > 0
        )));

        sort($hari);

        return array_map('intval', $hari);
    }

    public function batasHari(): int
    {
        return max(1, (int) config('crm.tagihan.batal_hari', 28));
    }

    /**
     * Jalankan satu putaran: tagih yang jatuh tempo, batalkan yang lewat batas.
     *
     * @return array{diperiksa:int, ditagih:int, dibatalkan:int, dilewati:int}
     */
    public function jalankan(): array
    {
        $hasil = ['diperiksa' => 0, 'ditagih' => 0, 'dibatalkan' => 0, 'dilewati' => 0];

        foreach ($this->kandidat() as $link) {
            $so = $link->salesOrder;

            if (! $so) {
                continue;
            }

            $hasil['diperiksa']++;

            [$layak, $alasan] = $this->boleh($so);

            if (! $layak) {
                $hasil['dilewati']++;
                continue;
            }

            $umur = $this->umurHari($link);

            if ($umur >= $this->batasHari()) {
                $hasil[$this->batalkan($so, $umur) ? 'dibatalkan' : 'dilewati']++;
                continue;
            }

            $hasil[$this->tagih($so, $link, $umur) ? 'ditagih' : 'dilewati']++;
        }

        return $hasil;
    }

    /**
     * Apa yang AKAN dikerjakan putaran berikutnya, tanpa mengerjakannya.
     *
     * Dipakai `--dry-run`, dan bukan kemewahan: putaran pertama di server
     * sungguhan bisa menemukan puluhan pesanan lama yang menggantung.
     * Membatalkannya serentak tanpa sempat dilihat manusia adalah kerusakan
     * yang tidak bisa ditarik kembali.
     *
     * @return array<int,array{0:string,1:int,2:string,3:string}>
     */
    public function rencana(): array
    {
        $baris = [];

        foreach ($this->kandidat() as $link) {
            $so = $link->salesOrder;

            if (! $so) {
                continue;
            }

            $umur = $this->umurHari($link);
            [$layak, $alasan] = $this->boleh($so);

            if (! $layak) {
                $baris[] = [$so->order_number, $umur, 'lewati', (string) $alasan];
                continue;
            }

            if ($umur >= $this->batasHari()) {
                $baris[] = [$so->order_number, $umur, 'BATALKAN', 'Lewat batas ' . $this->batasHari() . ' hari.'];
                continue;
            }

            $titik = $this->titikJadwal($umur);

            if ($titik === null) {
                $baris[] = [$so->order_number, $umur, 'belum', 'Belum sampai titik tagih pertama.'];
                continue;
            }

            $sudah = CrmOutboxMessage::where('dedupe_key', "so:{$so->id}:tagih:h{$titik}")->exists();

            $baris[] = [
                $so->order_number,
                $umur,
                $sudah ? 'belum' : 'tagih',
                $sudah ? "Tagihan hari ke-{$titik} sudah pernah dikirim." : "Tagihan hari ke-{$titik}.",
            ];
        }

        return $baris;
    }

    /** Titik jadwal TERAKHIR yang sudah terlewati, atau null bila belum ada. */
    private function titikJadwal(int $umur): ?int
    {
        $titik = null;

        foreach ($this->jadwal() as $hari) {
            if ($umur >= $hari) {
                $titik = $hari;
            }
        }

        return $titik;
    }

    /**
     * Tautan bayar yang masih menunggu, beserta pesanannya.
     *
     * Hanya tautan `source = link` — yang sengaja DIBUATKAN untuk ditagihkan.
     * QRIS kasir dan tautan checkout toko online punya siklusnya sendiri (yang
     * terakhir malah sudah punya auto-batal 24 jam), dan menagih keduanya
     * berarti mengirim tagihan atas uang yang sudah di depan mata kasir.
     */
    private function kandidat()
    {
        return MidtransTransaction::query()
            ->whereNotNull('sales_order_id')
            ->whereNull('sales_invoice_id')
            ->where('source', 'link')
            ->where('status', 'pending')
            ->with(['salesOrder.customer'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Pesanan ini boleh ditagih/dibatalkan?
     *
     * @return array{0:bool, 1:?string}
     */
    private function boleh(SalesOrder $so): array
    {
        if ($so->status !== 'confirmed') {
            return [false, 'Pesanan tidak berstatus confirmed.'];
        }

        /*
         * SUDAH ADA UANGNYA → berhenti total, jangan ditagih maupun dibatalkan.
         * Sisa pelunasan punya alurnya sendiri, dan pesanan yang sudah menerima
         * DP tidak boleh dibatalkan sepihak oleh cron: uangnya sudah masuk,
         * barangnya mungkin sudah dikerjakan, dan void-nya pun akan tertahan
         * oleh pembayaran yang aktif.
         */
        if (round((float) $so->paid_amount, 2) > 0.01) {
            return [false, 'Pesanan sudah menerima pembayaran.'];
        }

        /*
         * Pesanan TEMPO memang sengaja belum dibayar sampai jatuh tempo.
         * Menagihnya tiap hari lalu membatalkannya di minggu keempat berarti
         * membatalkan penjualan kredit yang justru sedang berjalan normal.
         */
        if ($so->is_tempo) {
            return [false, 'Pesanan tempo — punya jatuh temponya sendiri.'];
        }

        // Marketplace, opt-in, & nomor yang sah diperiksa lewat aturan yang
        // sama dengan notifikasi pesanan — satu tempat, bukan dua yang
        // pelan-pelan berbeda.
        [$layakKirim, $alasanKirim] = $this->notifikasi->kelayakan($so);

        return $layakKirim ? [true, null] : [false, $alasanKirim];
    }

    /** Umur tautan dalam hari penuh, dihitung dari tengah malam ke tengah malam. */
    private function umurHari(MidtransTransaction $link): int
    {
        return (int) $link->created_at->copy()->startOfDay()->diffInDays(now()->startOfDay());
    }

    /**
     * Antrekan tagihan untuk titik jadwal TERAKHIR yang sudah terlewati.
     *
     * Sengaja bukan "semua yang terlewat": kalau cron mati dua hari, pelanggan
     * tidak boleh menerima tiga pesan sekaligus begitu ia hidup lagi. Kunci
     * dedupe per titik jadwal memastikan tiap titik berangkat paling banyak
     * sekali, seumur pesanan.
     */
    private function tagih(SalesOrder $so, MidtransTransaction $link, int $umur): bool
    {
        $titik = $this->titikJadwal($umur);

        if ($titik === null) {
            return false;   // belum waktunya menagih
        }

        /*
         * Jenisnya dimatikan → tidak diantrekan sama sekali, bukan dicatat
         * 'dilewati'. Mencatatnya membakar kunci dedupe titik ini, dan tagihan
         * itu tak akan pernah bisa berangkat lagi meski jenisnya dinyalakan
         * besok. Beda dari notifikasi pesanan, yang peristiwanya memang lewat.
         */
        if (! JenisNotifikasi::aktif(CrmOutboxMessage::EVENT_TAGIHAN)) {
            return false;
        }

        [, , $nomor] = $this->notifikasi->kelayakan($so);

        $baris = CrmOutboxMessage::antrekan("so:{$so->id}:tagih:h{$titik}", [
            'event'          => CrmOutboxMessage::EVENT_TAGIHAN,
            'sales_order_id' => $so->id,
            'recipient'      => $nomor,
            'template_name'  => CrmOutboxMessage::TEMPLATES[CrmOutboxMessage::EVENT_TAGIHAN],
            'template_body'  => [
                (string) ($so->customer?->name ?: 'Pelanggan'),
                (string) $so->order_number,
                number_format((float) $so->grand_total, 0, ',', '.'),
                $this->tautan->publicUrl($link),
                // Tanggal batasnya disebut terang-terangan. Pembatalan yang
                // datang tanpa peringatan terbaca sebagai pembatalan sepihak,
                // dan itulah yang berubah jadi keluhan.
                $link->created_at->copy()->addDays($this->batasHari())->translatedFormat('j F Y'),
                (string) config('crm.admin_phone'),
            ],
            'status'         => CrmOutboxMessage::STATUS_MENUNGGU,
            'scheduled_at'   => null,
        ]);

        return (bool) $baris;
    }

    /**
     * Batalkan pesanan yang tak dibayar juga: lepas reservasi, void SO.
     *
     * Diperiksa ULANG di dalam kunci baris. Antara pemilihan kandidat dan titik
     * ini, pembayaran bisa saja masuk lewat webhook — dan membatalkan pesanan
     * yang baru saja dibayar adalah kerusakan yang tidak bisa ditarik kembali.
     *
     * Tautan bayarnya sendiri tidak dimatikan di sini: PaymentLinkDocumentObserver
     * sudah mematikannya begitu SO berstatus void, dan menuliskannya dua kali
     * berarti dua tempat yang harus ikut berubah setiap kali aturannya berubah.
     */
    private function batalkan(SalesOrder $so, int $umur): bool
    {
        try {
            return DB::transaction(function () use ($so, $umur) {
                $kunci = SalesOrder::lockForUpdate()->find($so->id);

                if (! $kunci || $kunci->status !== 'confirmed') {
                    return false;
                }

                if (round((float) $kunci->paid_amount, 2) > 0.01) {
                    return false;
                }

                // Dokumen turunan aktif = pesanannya sudah berjalan; itu bukan
                // urusan cron penagihan. Pagar yang sama dengan void manual.
                if (! $kunci->canBeVoided()) {
                    return false;
                }

                StockReservation::where('sales_order_id', $kunci->id)
                    ->update(['status' => 'cancelled']);
                $this->tandaiStokJubelio($kunci->id);

                $kunci->forceFill([
                    'status' => 'void',
                    'notes'  => trim((string) ($kunci->notes ?? '')
                        . "\nAuto-batal: tidak dibayar dalam {$umur} hari sejak tautan bayar dibuat."),
                ])->save();

                return true;
            });
        } catch (\Throwable $e) {
            Log::warning('[CRM] auto-batal pesanan tak dibayar gagal', [
                'sales_order_id' => $so->id,
                'error'          => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Tandai produk yang reservasinya dilepas agar didorong ulang ke Jubelio.
     *
     * Mass-update reservasi melewati StockReservationObserver, jadi tanpa ini
     * stok yang baru saja bebas tidak pernah sampai ke marketplace — barang
     * ada di rak tapi tampak habis di lapak. Kembaran dari yang ada di
     * SalesOrderController::void dan WebPaymentService.
     */
    private function tandaiStokJubelio(int $salesOrderId): void
    {
        $productIds = StockReservation::where('sales_order_id', $salesOrderId)
            ->pluck('product_id')->unique()->all();

        if (empty($productIds)) {
            return;
        }

        Product::whereIn('id', $productIds)
            ->where('sync_to_jubelio', true)
            ->update(['jubelio_sync_pending' => true]);

        $bundleIds = BundleComponent::whereIn('component_product_id', $productIds)
            ->pluck('bundle_product_id');

        if ($bundleIds->isNotEmpty()) {
            Product::whereIn('id', $bundleIds)
                ->where('sync_to_jubelio', true)
                ->update(['jubelio_sync_pending' => true]);
        }
    }
}
