<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmOutboxMessage;
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
        return $this->antrekan($so, CrmOutboxMessage::EVENT_SIAP_AMBIL, "so:{$so->id}:siap_diambil", fn () => [
            $this->namaPelanggan($so),
            $so->order_number,
            (string) ($so->pickup_code ?: '-'),
            (string) config('crm.store_hours_text'),
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
        [$layak, $alasan, $nomor] = $this->kelayakan($so);

        $baris = CrmOutboxMessage::antrekan($dedupeKey, [
            'event'          => $event,
            'sales_order_id' => $so->id,
            'recipient'      => $nomor,
            'template_name'  => CrmOutboxMessage::TEMPLATES[$event] ?? null,
            'template_body'  => $layak ? $bodyBuilder() : null,
            'status'         => $layak ? CrmOutboxMessage::STATUS_MENUNGGU : CrmOutboxMessage::STATUS_DILEWATI,
            'reason'         => $layak ? null : $alasan,
            'scheduled_at'   => $layak ? $this->jadwalKirim($event) : null,
        ]);

        return $baris;
    }

    /**
     * Boleh dikirimi notifikasi?
     *
     * @return array{0:bool, 1:?string, 2:?string} [layak, alasan, nomor]
     */
    public function kelayakan(SalesOrder $so): array
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

        if (! $customer->wa_opt_in) {
            return [false, 'Pelanggan belum menyetujui notifikasi WhatsApp (opt-in).', null];
        }

        $nomor = PhoneNumber::normalize($customer->recipient_phone ?: $customer->phone);

        if (! $nomor) {
            return [false, 'Nomor WhatsApp pelanggan kosong atau tidak valid.', null];
        }

        return [true, null, $nomor];
    }

    /**
     * Kapan pesan ini pantas dikirim. Null = segera.
     *
     * Hanya "siap diambil" yang menunggu jam buka, karena pesan itu MENGAJAK
     * pelanggan datang — memberitahunya pukul 23.00 atau di hari libur cuma
     * membuat orang datang ke toko yang tutup. Konfirmasi pembayaran dan nomor
     * resi justru ditunggu segera; menundanya lebih buruk daripada mengirim malam.
     */
    public function jadwalKirim(string $event, ?Carbon $sekarang = null): ?Carbon
    {
        if ($event !== CrmOutboxMessage::EVENT_SIAP_AMBIL) {
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
