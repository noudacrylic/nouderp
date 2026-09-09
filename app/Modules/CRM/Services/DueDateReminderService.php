<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmOutboxMessage;
use App\Modules\CRM\Support\JenisNotifikasi;
use App\Modules\Sales\Models\SalesOrder;
use Carbon\Carbon;

/**
 * Pengingat jatuh tempo pesanan TEMPO: H-3, lalu hari-H.
 *
 * Beda watak dari BillingReminderService, dan bedanya menentukan segalanya.
 * Yang di sana adalah pesanan yang mungkin sudah ditinggalkan pembelinya —
 * boleh ditagih berulang, boleh dibatalkan. Yang di sini adalah PENJUALAN
 * KREDIT yang sedang berjalan normal: pembelinya tidak menghilang, ia cuma
 * belum sampai tanggalnya. Karena itu tak ada pembatalan apa pun di berkas
 * ini, dan pesannya cuma dua kali seumur pesanan.
 *
 * Jalurnya WAJIB resmi (TemplateResmi::wajibResmi) — pesan soal utang yang
 * jatuh tempo adalah yang paling mudah disalahartikan sebagai penipuan.
 */
class DueDateReminderService
{
    /** Titik kirim: berapa hari SEBELUM jatuh tempo. 0 = hari-H. */
    public const TITIK = [3, 0];

    /**
     * Toleransi hari, bukan kecocokan persis.
     *
     * Cron yang mati sehari tidak boleh membuat pengingat H-3 hilang selamanya.
     * Tapi toleransinya berbatas: tanpa itu, putaran pertama di server akan
     * mengirim "jatuh tempo hari ini" ke pesanan yang menunggak berbulan-bulan.
     */
    private const TOLERANSI = 2;

    public function __construct(private OrderNotificationService $notifikasi)
    {
    }

    /**
     * @return array{diperiksa:int, diantrekan:int, dilewati:int}
     */
    public function jalankan(?Carbon $hariIni = null): array
    {
        $hasil = ['diperiksa' => 0, 'diantrekan' => 0, 'dilewati' => 0];
        $hari  = ($hariIni ?: now())->copy()->startOfDay();

        foreach ($this->kandidat() as $so) {
            $hasil['diperiksa']++;

            $titik = $this->titik($so, $hari);

            if ($titik === null) {
                continue;
            }

            $hasil[$this->antrekan($so, $titik) ? 'diantrekan' : 'dilewati']++;
        }

        return $hasil;
    }

    /**
     * Pesanan tempo yang masih punya sisa tagihan.
     *
     * Lunas → tidak diingatkan, jelas. Yang perlu disebut adalah SEBAGIAN
     * lunas: DP sudah masuk tapi sisanya belum, dan itu justru kasus tempo
     * yang paling lazim — pengingatnya tetap perlu, dan angkanya yang
     * disebutkan adalah SISANYA, bukan totalnya.
     */
    private function kandidat()
    {
        return SalesOrder::query()
            ->where('status', 'confirmed')
            ->where('is_tempo', true)
            ->whereNotNull('tempo_due_date')
            ->whereRaw('ROUND(COALESCE(grand_total,0) - COALESCE(paid_amount,0), 2) > 0.01')
            ->with('customer')
            ->orderBy('tempo_due_date')
            ->get();
    }

    /**
     * Titik kirim yang berlaku hari ini untuk sebuah pesanan, atau null.
     *
     * @return int|null 3 (H-3) atau 0 (hari-H)
     */
    private function titik(SalesOrder $so, Carbon $hari): ?int
    {
        $sisa = (int) $hari->diffInDays($so->tempo_due_date->copy()->startOfDay(), false);

        foreach (self::TITIK as $titik) {
            // Jendela sempit di sekitar tiap titik: [titik − toleransi, titik].
            // Batas atasnya persis di titiknya supaya pengingat H-3 tidak
            // berangkat lebih awal, dan batas bawahnya menoleransi cron yang
            // sempat mati tanpa menyapu tunggakan lama.
            if ($sisa <= $titik && $sisa >= $titik - self::TOLERANSI) {
                return $titik;
            }
        }

        return null;
    }

    /**
     * Antrekan satu pengingat. Kunci dedupe per TITIK, jadi H-3 dan hari-H
     * masing-masing berangkat paling banyak sekali seumur pesanan.
     */
    private function antrekan(SalesOrder $so, int $titik): bool
    {
        /*
         * Jenisnya dimatikan → tidak diantrekan sama sekali, bukan dicatat
         * 'dilewati'. Mencatatnya membakar kunci dedupe titik ini, dan
         * pengingatnya tak akan pernah bisa berangkat lagi meski jenisnya
         * dinyalakan besok — padahal jatuh temponya belum lewat.
         */
        if (! JenisNotifikasi::aktif(CrmOutboxMessage::EVENT_JATUH_TEMPO)) {
            return false;
        }

        [$layak, , $nomor] = $this->notifikasi->kelayakan($so);

        if (! $layak) {
            return false;
        }

        $sisa = round((float) $so->grand_total - (float) $so->paid_amount, 2);

        $baris = CrmOutboxMessage::antrekan("so:{$so->id}:tempo:h{$titik}", [
            'event'          => CrmOutboxMessage::EVENT_JATUH_TEMPO,
            'sales_order_id' => $so->id,
            'recipient'      => $nomor,
            'template_name'  => CrmOutboxMessage::TEMPLATES[CrmOutboxMessage::EVENT_JATUH_TEMPO],
            'template_body'  => [
                (string) ($so->customer?->name ?: 'Pelanggan'),
                (string) $so->order_number,
                number_format($sisa, 0, ',', '.'),
                $so->tempo_due_date->translatedFormat('j F Y'),
                (string) config('crm.admin_phone'),
            ],
            'status'         => CrmOutboxMessage::STATUS_MENUNGGU,
            'scheduled_at'   => null,
        ]);

        return (bool) $baris;
    }
}
