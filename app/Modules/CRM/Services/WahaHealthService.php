<?php

namespace App\Modules\CRM\Services;

use App\Models\CrmSetting;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\NotificationManager;
use App\Modules\CRM\Providers\WahaProvider;
use App\Modules\CRM\Support\PeranWaha;
use App\Modules\Notifications\Services\TelegramNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Denyut jantung sesi WAHA — satu per NOMOR, bukan satu untuk seluruh WAHA.
 *
 * Kerusakan jalur ini TIDAK KELIHATAN dari layar mana pun: ERP tetap
 * mengantrekan notifikasi, layarnya tetap rapi, antreannya menahan diri dengan
 * sopan — dan pelanggan tidak menerima apa pun. Sesi bisa putus tanpa sebab
 * yang terlihat (HP pemegang SIM mati, WhatsApp mengeluarkan perangkat
 * tertaut, container restart). Karena itu diamnya harus disuarakan, dan
 * disuarakan ke tempat yang PASTI dibaca: Telegram, bukan layar ERP yang
 * mungkin tidak dibuka seharian.
 *
 * SEJAK ADA DUA NOMOR, peringatan WAJIB menyebut sesi mana yang mati beserta
 * akibatnya. Keduanya rusak dengan cara yang sama tapi kehilangan hal yang
 * berbeda — nomor notifikasi punya jalur cadangan berbayar, nomor utama tidak
 * punya pengganti sama sekali. Pesan yang tidak membedakannya membuat orang
 * menilai keadaan darurat sebagai keadaan biasa, atau sebaliknya.
 *
 * QR ikut dikirim ke Telegram supaya bisa dipindai langsung dari HP. Tanpa itu
 * pemulihan menuntut orang membuka terowongan SSH ke server lebih dulu — dan
 * itu berarti sesi tetap mati sepanjang akhir pekan.
 *
 * Hasil pemeriksaan DISIMPAN ke pengaturan supaya pita peringatan di layar ERP
 * bisa membacanya tanpa menelepon WAHA sendiri; layar tidak boleh menggantung
 * menunggu timeout, justru pada saat WAHA-nya sedang mati.
 */
class WahaHealthService
{
    public function __construct(
        private NotificationManager $notifikasi,
        private ChatManager $chat,
        private TelegramNotifier $telegram,
    ) {
    }

    /**
     * Ada yang perlu dipantau untuk peran ini?
     *
     * Mode aman menjawab TIDAK untuk semua peran. Selama saklar jangan-kirim
     * menyala, tak satu pun jalur benar-benar melayani siapa pun, jadi sesi
     * yang mati tidak merugikan. Memperingatkannya tetap akan membuat pita
     * menyala terus dan Telegram berbunyi tanpa ada yang bisa ditindaklanjuti
     * - lalu peringatannya berhenti dipercaya justru saat betulan rusak.
     * Alasan yang sama persis dengan pemeriksa webhook.
     *
     * Sisanya berbeda per peran, dan bedanya penting:
     *  - NOTIFIKASI dipantau bila jalur notifikasi memang disetel ke WAHA.
     *    Sesi yang menganggur karena notifikasi lewat template resmi bukan
     *    kerusakan.
     *  - UTAMA dipantau begitu nomornya PERNAH tertaut. Tidak bisa memakai
     *    aturan "sedang dipakai": nomor utama ditautkan lebih dulu dan baru
     *    dipakai beberapa tahap kemudian, sementara sesi yang menganggur pun
     *    tetap harus hidup — kalau tidak, penautannya sudah batal diam-diam
     *    jauh sebelum ada yang menyadarinya.
     *
     * Yang TIDAK ikut aturan ini: tombol "Uji Sesi" di Pengaturan. Di sana
     * yang ditanya memang "sesinya hidup atau tidak", dan menjawabnya dengan
     * status palsu hanya menyesatkan.
     */
    private function perluDipantau(string $peran): bool
    {
        if ($this->chat->isDryRun()) {
            return false;
        }

        return match ($peran) {
            PeranWaha::NOTIFIKASI => $this->notifikasi->pakaiWaha(),
            PeranWaha::UTAMA      => CrmSetting::for('waha')->pernahTertaut(PeranWaha::UTAMA),
            default               => false,
        };
    }

    /**
     * Periksa seluruh peran sekali jalan. Dipakai penjadwal.
     *
     * @return array<string, array{siap:bool, status:string, dilewati:bool}>
     */
    public function periksaSemua(): array
    {
        $hasil = [];

        foreach (PeranWaha::SEMUA as $peran) {
            $hasil[$peran] = $this->periksa($peran);
        }

        return $hasil;
    }

    /**
     * Periksa satu peran. @return array{siap:bool, status:string, dilewati:bool}
     * 'dilewati' = sesi peran ini memang tidak sedang dipakai, jadi tak ada
     * yang perlu dipantau maupun diperingatkan.
     */
    public function periksa(string $peran = PeranWaha::NOTIFIKASI): array
    {
        if (! $this->perluDipantau($peran)) {
            return ['siap' => true, 'status' => 'TIDAK_DIPAKAI', 'dilewati' => true];
        }

        $waha    = CrmSetting::for('waha');
        $adapter = new WahaProvider($waha, $peran);
        $status  = $adapter->statusJalur();

        $sebelumnya = (string) ($waha->sesi($peran)['last_status'] ?? '');

        $waha->catatStatusSesi($peran, $status);

        if ($status['siap']) {
            // Pulih: dikabarkan HANYA bila sebelumnya memang sempat bermasalah,
            // supaya Telegram tidak menerima kabar "baik-baik saja" tiap 5 menit.
            if ($sebelumnya !== '' && $sebelumnya !== 'WORKING') {
                $this->kabari('✅ Sesi WhatsApp <b>' . $adapter->sesi() . '</b> ('
                    . PeranWaha::label($peran) . ') pulih.'
                    . ($peran === PeranWaha::NOTIFIKASI ? ' Notifikasi yang tertahan akan berangkat sendiri.' : ''));

                $this->tandaiSudahDikabari($waha, $peran, null);
            }

            return ['siap' => true, 'status' => $status['status'], 'dilewati' => false];
        }

        if ($this->bolehMengabari($waha, $peran)) {
            $this->peringatkan($adapter, $status);
            $this->tandaiSudahDikabari($waha, $peran, now());
        }

        return ['siap' => false, 'status' => $status['status'], 'dilewati' => false];
    }

    /**
     * Status hasil pemeriksaan terakhir — dibaca layar untuk pita peringatan.
     * Null berarti belum pernah diperiksa (atau sesi peran ini tidak dipakai).
     *
     * @return array{status:string, siap:bool, diperiksa:?Carbon}|null
     */
    public function statusTersimpan(string $peran = PeranWaha::NOTIFIKASI): ?array
    {
        if (! $this->perluDipantau($peran)) {
            return null;
        }

        $sesi = CrmSetting::for('waha')->sesi($peran);

        if (blank($sesi['last_status'] ?? null)) {
            return null;
        }

        return [
            'status'    => (string) $sesi['last_status'],
            'siap'      => $sesi['last_status'] === 'WORKING',
            'diperiksa' => ($sesi['last_checked_at'] ?? null) ? Carbon::parse($sesi['last_checked_at']) : null,
        ];
    }

    /**
     * Peringatan + QR. Sesi yang STOPPED dinyalakan dulu: QR baru terbit
     * setelah sesi hidup, jadi mengirim peringatan tanpa menyalakannya berarti
     * menyuruh orang memindai sesuatu yang belum ada.
     */
    private function peringatkan(WahaProvider $adapter, array $status): void
    {
        $peran = $adapter->peran();

        $pesan = '⚠️ <b>' . PeranWaha::label($peran) . " terputus.</b>\n"
            . 'Sesi <b>' . $adapter->sesi() . '</b> berstatus <b>' . $status['status'] . "</b>.\n"
            . PeranWaha::akibatPutus($peran) . "\n"
            // QR juga terbit di layar ERP, dan di sana ada tombol putus/ganti
            // nomor. Tautannya disertakan supaya pemulihan tidak selalu harus
            // menunggu peringatan berikutnya kalau QR di Telegram kedaluwarsa.
            . 'Bisa juga dipindai dari ERP: ' . route('settings.waha.edit');

        $this->kabari($pesan);

        if (in_array($status['status'], ['STOPPED', 'FAILED'], true)) {
            $adapter->mulaiSesi();
        }

        $qr = $adapter->qr();

        if ($qr === null) {
            return;
        }

        foreach ($this->telegram->approverChatIds() as $chatId) {
            $this->telegram->sendPhoto(
                $chatId,
                $qr,
                'waha-qr-' . $peran . '.png',
                /*
                 * Nomor mana yang harus dipindai WAJIB disebut: dua QR yang
                 * datang di hari yang sama tak bisa dibedakan dari gambarnya,
                 * dan memindai dengan HP yang keliru menukar kedua nomor —
                 * kerusakan yang jauh lebih mahal daripada sesi yang mati.
                 */
                'Pindai QR ini dari HP pemegang ' . PeranWaha::label($peran)
                . '. QR hanya hidup sekitar semenit — kalau kedaluwarsa, tunggu peringatan berikutnya.'
            );
        }
    }

    private function kabari(string $pesan): void
    {
        $terkirim = $this->telegram->notifyApprovers($pesan);

        if ($terkirim === 0) {
            // Telegram belum dikonfigurasi. Log-nya jadi satu-satunya jejak,
            // dan itu tetap lebih baik daripada diam sama sekali.
            Log::warning('[CRM] peringatan sesi WAHA tidak terkirim ke Telegram', ['pesan' => strip_tags($pesan)]);
        }
    }

    /**
     * Rem pengulangan: sesi mati semalaman tidak boleh jadi 300 pesan Telegram.
     * Peringatan yang terlalu sering justru berhenti dibaca — persis saat ia
     * paling perlu dibaca.
     *
     * Remnya PER PERAN. Kalau dipakai bersama, nomor notifikasi yang mati
     * semalam akan membungkam peringatan nomor utama yang putus sejam
     * kemudian — dan yang kedua itulah yang lebih mendesak.
     */
    private function bolehMengabari(CrmSetting $waha, string $peran): bool
    {
        $terakhir = $waha->sesi($peran)['alerted_at'] ?? null;

        if (! $terakhir) {
            return true;
        }

        return Carbon::parse($terakhir)->lte(now()->subMinutes((int) config('crm.notifikasi.peringatan_jeda_menit', 60)));
    }

    private function tandaiSudahDikabari(CrmSetting $waha, string $peran, ?Carbon $waktu): void
    {
        $waha->simpanSesi($peran, ['alerted_at' => $waktu?->toIso8601String()]);
    }
}
