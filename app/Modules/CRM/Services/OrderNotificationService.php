<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmOutboxMessage;
use App\Modules\CRM\Support\JenisNotifikasi;
use App\Modules\CRM\Support\PhoneNumber;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\SDM\Models\NationalHoliday;
use Carbon\Carbon;

/**
 * Menyusun & mengantrekan notifikasi pesanan ke pelanggan.
 *
 * Tugasnya berhenti di ANTREAN — pengiriman sungguhan urusan CrmOutboxSender.
 * Pemisahan ini yang membuat seluruh aturan bisnis di bawah bisa diuji tanpa
 * jaringan, dan membuat "tombol kirim ulang manual" nanti memakai jalan yang
 * sama persis dengan pemicu otomatis.
 *
 * TIGA notifikasi saja, semuanya UTILITY. Menambah jenis berarti menambah
 * template baru ke Meta — dan mengirim terlalu sering mengundang blokir.
 */
class OrderNotificationService
{
    /**
     * Kabar yang menyangkut BARANG — satu-satunya yang boleh mendarat di nomor cabang.
     *
     * Sisanya menyangkut UANG (pembayaran, tagihan, jatuh tempo, pelunasan) dan
     * selalu ke nomor utama pelanggan, walaupun cabangnya punya nomor sendiri.
     * Tanpa pemisahan ini, tagihan purchasing mendarat di tangan orang gudang
     * cabang — dan itu jenis kebocoran yang baru ketahuan setelah terjadi.
     */
    public const EVENT_BARANG = [
        CrmOutboxMessage::EVENT_DIKIRIM,
        CrmOutboxMessage::EVENT_SIAP_AMBIL,
    ];

    /** Pembayaran masuk (DP maupun pelunasan). */
    public function antrekanPembayaranDiterima(SalesOrder $so, float $paidAmount): ?CrmOutboxMessage
    {
        // Kunci memakai nominal kumulatif: DP lalu pelunasan = dua kunci berbeda
        // (dua notifikasi, memang benar), sedangkan penyimpanan berulang dengan
        // nominal yang sama = satu notifikasi.
        $key = sprintf('so:%d:pembayaran:%d', $so->id, (int) round($paidAmount * 100));

        $sisa = round((float) $so->grand_total - $paidAmount, 2);

        return $this->antrekan($so, CrmOutboxMessage::EVENT_PEMBAYARAN, $key, fn () => [
            $this->namaPelanggan($so),
            $this->rupiah($paidAmount),
            $so->order_number,
            $sisa > 0.01
                ? 'DP diterima, sisa ' . $this->rupiah($sisa)
                : 'Lunas',
        ]);
    }

    /** Barang selesai & menunggu diambil di toko. */
    public function antrekanSiapDiambil(SalesOrder $so): ?CrmOutboxMessage
    {
        return $this->antrekan($so, CrmOutboxMessage::EVENT_SIAP_AMBIL, "so:{$so->id}:siap_diambil", function () use ($so) {
            $body = [
                $this->namaPelanggan($so),
                $so->order_number,
                (string) ($so->pickup_code ?: '-'),
                (string) config('crm.store_hours_text'),
            ];

            /*
             * Ambil-toko tidak dapat "Pengingat Pelunasan" — kekurangan bayarnya disebut di
             * pesan ini (variabel ke-5, khusus WAHA; lihat TemplateResmi 'tambahan_waha').
             * Tempo tidak: sisanya memang ditagih belakangan, bukan di kasir.
             */
            $sisa = round((float) $so->grand_total - (float) $so->paid_amount, 2);

            /*
             * Slot {{5}} SELALU dikirim, walau kosong.
             *
             * Kalimat tambahan berikutnya ({{6}} & {{7}}) membaca slot setelah
             * ini. Kalau slot sisa dilewati saat nihil, alamat akan menempati
             * nomor yang salah dan muncul sebagai nominal pembayaran.
             */
            $body[] = ($sisa > 0.01 && ! $so->is_tempo) ? $this->rupiah($sisa) : '';

            // Alamat & peta toko. Pesan yang menyuruh orang datang tapi tidak
            // menyebut ke mana memaksa mereka bertanya dulu — dan di luar jam
            // kerja pertanyaan itu tidak terjawab.
            $body[] = (string) config('crm.store_address');
            $body[] = (string) config('crm.store_maps_url');

            return $body;
        });
    }

    /**
     * Barang pesanan kirim sudah siap, tinggal menunggu pelunasan.
     *
     * Kunci memakai SISA tagihan: pelunasan sebagian yang mengubah sisanya
     * boleh memicu satu kabar baru dengan angka yang benar, sedangkan
     * pemindaian berulang atas sisa yang sama tetap satu pesan.
     */
    public function antrekanPelunasan(SalesOrder $so, float $sisa): ?CrmOutboxMessage
    {
        $key = sprintf('so:%d:pelunasan:%d', $so->id, (int) round($sisa * 100));

        return $this->antrekan($so, CrmOutboxMessage::EVENT_PELUNASAN, $key, fn () => [
            $this->namaPelanggan($so),
            $so->order_number,
            $this->rupiah($sisa),
            (string) config('crm.admin_phone'),
        ]);
    }

    /** Paket diserahkan ke kurir & sudah punya resi. */
    public function antrekanDikirim(SalesOrder $so, SalesDelivery $delivery): ?CrmOutboxMessage
    {
        if (blank($delivery->tracking_number)) {
            return null;   // belum ada resi = belum ada yang bisa dilacak
        }

        return $this->antrekan($so, CrmOutboxMessage::EVENT_DIKIRIM, "sj:{$delivery->id}:dikirim", fn () => [
            $this->namaPelanggan($so),
            $so->order_number,
            (string) ($delivery->courier_name ?: $delivery->shipping_courier_code ?: 'kurir'),
            (string) $delivery->tracking_number,
        ]);
    }

    /**
     * Jalur bersama ketiganya: periksa kelayakan, susun baris outbox.
     *
     * Baris tetap dibuat meski pelanggan tidak layak dikirimi — statusnya
     * 'dilewati' beserta alasannya. Kalau dilewati begitu saja tanpa jejak,
     * pertanyaan "kenapa pelanggan ini tidak dapat kabar?" tak akan terjawab.
     */
    private function antrekan(SalesOrder $so, string $event, string $dedupeKey, callable $bodyBuilder): ?CrmOutboxMessage
    {
        /*
         * Marketplace berhenti di sini, TANPA meninggalkan baris.
         *
         * Berbeda dari alasan-alasan di bawah, yang satu ini bukan kekurangan
         * yang bisa diperbaiki: pembeli Shopee/Tokopedia memang dikabari oleh
         * platformnya sendiri, dan kita tidak akan pernah mengiriminya apa pun.
         * Mencatatnya sebagai 'dilewati' cuma menghasilkan satu baris per
         * pesanan marketplace — dan dengan sinkron Jubelio tiap lima menit,
         * layar Notifikasi Pesanan tenggelam oleh baris yang tak seorang pun
         * perlu tindaklanjuti, menutupi justru yang perlu (opt-in kosong,
         * nomor tidak valid).
         */
        if ($so->customer?->is_marketplace) {
            return null;
        }

        [$layak, $alasan, $nomor] = $this->kelayakan($so, $event);

        /*
         * Jenis yang dimatikan tetap DICATAT sebagai 'dilewati', bukan
         * dilewatkan begitu saja. Barisnya beserta alasannya yang kelak
         * menjawab "kenapa pelanggan ini tidak dapat kabar?" — dan sekaligus
         * mengingatkan bahwa yang mematikannya adalah kita sendiri.
         */
        if ($layak && ! JenisNotifikasi::aktif($event)) {
            $layak  = false;
            $alasan = 'Jenis notifikasi "' . JenisNotifikasi::label($event) . '" sedang dimatikan di layar Notifikasi Pesanan.';
        }

        $atribut = [
            'event'          => $event,
            'sales_order_id' => $so->id,
            'recipient'      => $nomor,
            'template_name'  => CrmOutboxMessage::TEMPLATES[$event] ?? null,
            'template_body'  => $layak ? $bodyBuilder() : null,
            'status'         => $layak ? CrmOutboxMessage::STATUS_MENUNGGU : CrmOutboxMessage::STATUS_DILEWATI,
            'reason'         => $layak ? null : $alasan,
            'scheduled_at'   => $layak ? $this->jadwalKirim($event) : null,
        ];

        $baris = CrmOutboxMessage::antrekan($dedupeKey, $atribut);

        // Tembusan hanya untuk yang benar-benar berangkat. Kalau tidak layak,
        // satu baris 'dilewati' sudah cukup menjelaskan — mengalikannya per
        // nomor cuma menenggelamkan layar tanpa menambah satu pun keterangan.
        if ($layak) {
            $this->antrekanTembusan($so, $dedupeKey, $atribut, $nomor, $this->cabangKabar($so, $event));
        }

        return $baris;
    }

    /**
     * Salin satu kabar ke nomor tambahan perusahaan.
     *
     * Satu pelanggan bisa ditangani beberapa orang berbeda posisi, dan mereka
     * sama-sama perlu tahu. Kunci dedupe pesan utama SENGAJA tidak berubah,
     * jadi menyalakan fitur ini tidak membangunkan ulang kabar lama yang sudah
     * terkirim; tiap tembusan punya kuncinya sendiri.
     *
     * @return int berapa tembusan yang benar-benar masuk antrean
     */
    public function antrekanTembusan(SalesOrder $so, string $dedupeKey, array $atribut, ?string $nomorUtama, $cabang = null): int
    {
        $jumlah = 0;

        foreach ($this->nomorTembusan($so, $nomorUtama, $cabang) as $nomor) {
            $atribut['recipient'] = $nomor;

            if (CrmOutboxMessage::antrekan($dedupeKey . ':cc:' . $nomor, $atribut)) {
                $jumlah++;
            }
        }

        return $jumlah;
    }

    /**
     * Nomor tambahan yang ikut dikabari, tanpa nomor utama itu sendiri.
     *
     * @return string[]
     */
    public function nomorTembusan(SalesOrder $so, ?string $kecuali, $cabang = null): array
    {
        $customer = $so->customer;

        if (! $customer || $customer->is_marketplace || $customer->wa_opt_out_at) {
            return [];
        }

        return array_values(array_filter(
            $customer->semuaNomorNotifikasi($cabang),
            fn ($nomor) => $nomor !== $kecuali
        ));
    }

    /**
     * Boleh dikirimi notifikasi?
     *
     * @return array{0:bool, 1:?string, 2:?string} [layak, alasan, nomor]
     */
    public function kelayakan(SalesOrder $so, ?string $event = null): array
    {
        $customer = $so->customer;

        if (! $customer) {
            return [false, 'Pesanan tanpa data pelanggan.', null];
        }

        /*
         * Pesanan marketplace TIDAK PERNAH dikirimi. Nomor pembeli Shopee/
         * Tokopedia umumnya nomor proxy, dan menghubungi pembeli di luar
         * platform melanggar aturan marketplace.
         */
        if ($customer->is_marketplace) {
            return [false, 'Pesanan marketplace — pembeli tidak boleh dihubungi di luar platform.', null];
        }

        /*
         * Yang menghentikan pengiriman adalah KEBERATAN, bukan tiadanya izin.
         *
         * Orang yang sudah membuat pesanan di Noud menunggu kabar tentang
         * pesanannya — dan yang dikirim memang hanya itu: pembayaran masuk,
         * barang siap, nomor resi. Dulu syaratnya `wa_opt_in` menyala, dan
         * akibatnya 72 dari 73 pelanggan tidak pernah dikabari apa pun padahal
         * tak seorang pun dari mereka pernah menyatakan keberatan.
         *
         * Penolakan yang sungguh-sungguh tetap dihormati: pembeli yang melepas
         * centang di checkout, atau yang diminta admin berhenti dikirimi.
         */
        if ($customer->wa_opt_out_at) {
            return [false, 'Pelanggan meminta tidak dikirimi notifikasi WhatsApp.', null];
        }

        /*
         * Nomor UTAMA, bukan nomor penerima barang.
         *
         * Dulu urutannya `recipient_phone ?: phone`, dan akibatnya mengisi
         * "No. HP Penerima Barang" diam-diam memindahkan SELURUH kabar — kabar
         * uang sekalian — ke orang yang cuma menunggu paket di lokasi.
         * Rantainya kini satu arah dan tertulis di Customer::nomorNotifikasi().
         */
        $nomor = PhoneNumber::normalize($this->sumberNomor($so, $event)->nomorNotifikasi());

        if (! $nomor) {
            return [false, 'Nomor WhatsApp pelanggan kosong atau tidak valid.', null];
        }

        return [true, null, $nomor];
    }

    /**
     * Pemilik nomor yang dikabari untuk satu jenis kabar.
     *
     * Kabar barang memakai cabang tujuan pesanan bila ada; rantai jatuh-balik
     * di CustomerBranch::nomorNotifikasi() yang mengurus cabang tanpa nomor.
     */
    private function sumberNomor(SalesOrder $so, ?string $event)
    {
        return $this->cabangKabar($so, $event) ?: $so->customer;
    }

    /** Cabang yang berhak menerima kabar ini, atau null bila kabarnya soal uang. */
    private function cabangKabar(SalesOrder $so, ?string $event)
    {
        if (! $event || ! in_array($event, self::EVENT_BARANG, true)) {
            return null;
        }

        return $so->customer_branch_id ? $so->customerBranch : null;
    }

    /**
     * Kapan pesan ini pantas dikirim. Null = segera.
     *
     * Hanya "siap diambil" yang menunggu jam buka, karena pesan itu MENGAJAK
     * pelanggan datang — memberitahunya pukul 23.00 atau di hari libur cuma
     * membuat orang datang ke toko yang tutup. Konfirmasi pembayaran dan nomor
     * resi justru ditunggu segera; menundanya lebih buruk daripada mengirim malam.
     *
     * Pengingat pelunasan ikut menunggu jam buka: tagihan yang datang tengah
     * malam terasa menekan, dan pelanggan yang ingin bertanya ke admin baru
     * dilayani saat toko buka.
     */
    public function jadwalKirim(string $event, ?Carbon $sekarang = null): ?Carbon
    {
        if (! in_array($event, [CrmOutboxMessage::EVENT_SIAP_AMBIL, CrmOutboxMessage::EVENT_PELUNASAN], true)) {
            return null;
        }

        $buka  = (int) config('crm.store_open_hour', 8);
        $tutup = (int) config('crm.store_close_hour', 16);

        $waktu = ($sekarang ?: now())->copy();

        // Masih di dalam jam buka pada hari kerja → kirim sekarang.
        if ($this->hariBuka($waktu) && $waktu->hour >= $buka && $waktu->hour < $tutup) {
            return null;
        }

        // Sudah lewat jam tutup (atau hari ini libur) → mulai cari dari besok.
        if (! $this->hariBuka($waktu) || $waktu->hour >= $tutup) {
            $waktu->addDay();
        }

        $waktu->setTime($buka, 0);

        // Lompati minggu & hari libur nasional. Batas 14 hari supaya tidak
        // pernah ada kemungkinan berputar tanpa henti.
        for ($i = 0; $i < 14 && ! $this->hariBuka($waktu); $i++) {
            $waktu->addDay()->setTime($buka, 0);
        }

        return $waktu;
    }

    /** Toko buka Senin–Sabtu, kecuali hari libur nasional. */
    private function hariBuka(Carbon $waktu): bool
    {
        if ($waktu->isSunday()) {
            return false;
        }

        return ! NationalHoliday::isLibur($waktu->toDateString());
    }

    private function namaPelanggan(SalesOrder $so): string
    {
        return (string) ($so->customer?->name ?: 'Pelanggan');
    }

    private function rupiah(float $nilai): string
    {
        return number_format($nilai, 0, ',', '.');
    }
}
