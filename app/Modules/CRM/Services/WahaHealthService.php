<?php

namespace App\Modules\CRM\Services;

use App\Models\CrmSetting;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\NotificationManager;
use App\Modules\CRM\Providers\WahaProvider;
use App\Modules\Notifications\Services\TelegramNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Denyut jantung sesi WAHA.
 *
 * Kerusakan jalur ini TIDAK KELIHATAN dari layar mana pun: ERP tetap
 * mengantrekan notifikasi, layarnya tetap rapi, antreannya menahan diri dengan
 * sopan — dan pelanggan tidak menerima apa pun. Sesi bisa putus tanpa sebab
 * yang terlihat (HP pemegang SIM mati, WhatsApp mengeluarkan perangkat
 * tertaut, container restart). Karena itu diamnya harus disuarakan, dan
 * disuarakan ke tempat yang PASTI dibaca: Telegram, bukan layar ERP yang
 * mungkin tidak dibuka seharian.
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
     * Ada yang perlu dipantau?
     *
     * Mode aman menjawab TIDAK, meski jalurnya dipilih WAHA: selama saklar
     * jangan-kirim menyala, notifikasi diserahkan ke driver palsu, jadi sesi
     * yang mati tidak merugikan siapa pun. Memperingatkannya tetap akan
     * membuat pita menyala terus dan Telegram berbunyi tanpa ada yang bisa
     * ditindaklanjuti - lalu peringatannya berhenti dipercaya justru saat
     * betulan rusak. Alasan yang sama persis dengan pemeriksa webhook.
     *
     * Yang TIDAK ikut aturan ini: tombol "Uji Sesi WAHA" di Pengaturan. Di
     * sana yang ditanya memang "sesinya hidup atau tidak", dan menjawabnya
     * dengan status palsu hanya menyesatkan.
     */
    private function perluDipantau(): bool
    {
        return $this->notifikasi->pakaiWaha() && ! $this->chat->isDryRun();
    }

    /**
     * Periksa sekali. @return array{siap:bool, status:string, dilewati:bool}
     * 'dilewati' = jalur WAHA memang tidak sedang dipakai, jadi tak ada yang
     * perlu dipantau maupun diperingatkan.
     */
    public function periksa(): array
    {
        if (! $this->perluDipantau()) {
            return ['siap' => true, 'status' => 'TIDAK_DIPAKAI', 'dilewati' => true];
        }

        $waha    = CrmSetting::for('waha');
        $adapter = new WahaProvider($waha);
        $status  = $adapter->statusJalur();

        $sebelumnya = (string) ($waha->config['last_status'] ?? '');

        $this->simpanStatus($waha, $status);

        if ($status['siap']) {
            // Pulih: dikabarkan HANYA bila sebelumnya memang sempat bermasalah,
            // supaya Telegram tidak menerima kabar "baik-baik saja" tiap 5 menit.
            if ($sebelumnya !== '' && $sebelumnya !== 'WORKING') {
                $this->kabari('✅ Sesi WhatsApp <b>' . $adapter->sesi() . '</b> pulih. Notifikasi yang tertahan akan berangkat sendiri.');
                $this->tandaiSudahDikabari($waha, null);
            }

            return ['siap' => true, 'status' => $status['status'], 'dilewati' => false];
        }

        if ($this->bolehMengabari($waha)) {
            $this->peringatkan($adapter, $status);
            $this->tandaiSudahDikabari($waha, now());
        }

        return ['siap' => false, 'status' => $status['status'], 'dilewati' => false];
    }

    /**
     * Status hasil pemeriksaan terakhir — dibaca layar untuk pita peringatan.
     * Null berarti belum pernah diperiksa (atau jalur WAHA tidak dipakai).
     *
     * @return array{status:string, siap:bool, diperiksa:?Carbon}|null
     */
    public function statusTersimpan(): ?array
    {
        if (! $this->perluDipantau()) {
            return null;
        }

        $config = (array) CrmSetting::for('waha')->config;

        if (blank($config['last_status'] ?? null)) {
            return null;
        }

        return [
            'status'    => (string) $config['last_status'],
            'siap'      => $config['last_status'] === 'WORKING',
            'diperiksa' => ($config['last_checked_at'] ?? null) ? Carbon::parse($config['last_checked_at']) : null,
        ];
    }

    /**
     * Peringatan + QR. Sesi yang STOPPED dinyalakan dulu: QR baru terbit
     * setelah sesi hidup, jadi mengirim peringatan tanpa menyalakannya berarti
     * menyuruh orang memindai sesuatu yang belum ada.
     */
    private function peringatkan(WahaProvider $adapter, array $status): void
    {
        $pesan = "⚠️ <b>Notifikasi WhatsApp berhenti.</b>\n"
            . 'Sesi <b>' . $adapter->sesi() . '</b> berstatus <b>' . $status['status'] . "</b>.\n"
            . 'Notifikasi pesanan sedang ditahan di antrean, dan setelah '
            . (int) config('crm.notifikasi.tahan_maks_jam', 3) . " jam akan dialihkan ke template berbayar.
"
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
                'waha-qr.png',
                'Pindai QR ini dari HP pemegang nomor notifikasi. QR hanya hidup sekitar semenit — '
                . 'kalau kedaluwarsa, tunggu peringatan berikutnya.'
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
     */
    private function bolehMengabari(CrmSetting $waha): bool
    {
        $terakhir = $waha->config['alerted_at'] ?? null;

        if (! $terakhir) {
            return true;
        }

        return Carbon::parse($terakhir)->lte(now()->subMinutes((int) config('crm.notifikasi.peringatan_jeda_menit', 60)));
    }

    private function simpanStatus(CrmSetting $waha, array $status): void
    {
        $waha->config = array_merge((array) $waha->config, [
            'last_status'     => $status['status'],
            'last_checked_at' => now()->toIso8601String(),
        ]);

        $waha->save();
    }

    private function tandaiSudahDikabari(CrmSetting $waha, ?Carbon $waktu): void
    {
        $waha->config = array_merge((array) $waha->config, [
            'alerted_at' => $waktu?->toIso8601String(),
        ]);

        $waha->save();
    }
}
