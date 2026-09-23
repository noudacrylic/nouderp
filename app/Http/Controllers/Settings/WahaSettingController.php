<?php

namespace App\Http\Controllers\Settings;

use App\Models\CrmSetting;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Providers\WahaProvider;
use App\Modules\CRM\Support\CrmRuntimeConfig;
use App\Modules\CRM\Support\PeranWaha;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

/**
 * Settings → Integrasi → WhatsApp Self-Host (WAHA).
 *
 * Layar terpisah dari CRM WhatsApp (api.co.id) DENGAN SENGAJA: keduanya vendor
 * berbeda, jatuh sendiri-sendiri, dan dipegang orang pada saat berbeda pula.
 * Menggabungkannya dulu membuat "chat pelanggan bermasalah" dan "notifikasi
 * pesanan berhenti" tampak seperti satu kerusakan yang sama.
 *
 * DUA NOMOR, SATU CONTAINER. Sambungannya (alamat + API key) dibagi bersama
 * karena memang satu container; yang berdiri sendiri adalah SESI per peran —
 * nomor utama tempat pelanggan chat, dan nomor notifikasi yang mengirim kabar
 * pesanan. Setiap tombol di layar ini menyebut perannya, dan tidak ada satu
 * pun yang punya nilai bawaan: "Putuskan" tanpa nama nomor adalah cara
 * termudah memutus nomor utama toko karena mengira sedang mengganti nomor
 * notifikasi.
 *
 * Yang TIDAK ikut pindah ke sini: saklar jangan-kirim & daftar putih penerima.
 * Keduanya pengaman GLOBAL — WAHA mematuhi config yang sama persis
 * (`crm.dry_run`, `crm.allowed_recipients`). Menduplikasinya di layar ini akan
 * melahirkan dua sumber kebenaran, dan cepat atau lambat satu jalur berjalan
 * tanpa penjaga karena orang mengira sudah mematikannya "di layar sebelah".
 *
 * PANEL QR. Sesi WAHA putus tanpa gejala (HP mati, WhatsApp mengeluarkan
 * perangkat tertaut) dan memulihkannya dulu menuntut SSH + terowongan ke port
 * 3000 — pekerjaan yang tidak bisa dikerjakan dari HP di akhir pekan, sehingga
 * notifikasi diam berhari-hari. QR-nya diproksikan lewat ERP supaya bisa
 * dipindai dari layar ini. WAHA-nya sendiri TETAP di 127.0.0.1 dan tidak
 * pernah ikut terbuka keluar.
 */
class WahaSettingController extends Controller
{
    /**
     * QR = kunci untuk menautkan/mengganti akun WhatsApp perusahaan. Pintunya
     * dikunci ke admin, bukan ke siapa pun yang punya menu Pengaturan.
     */
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $u = auth()->user();
            abort_unless($u && in_array($u->role, ['super_admin', 'admin'], true), 403);

            return $next($request);
        });
    }

    public function edit(ChatManager $chat)
    {
        $waha = CrmSetting::for('waha');

        CrmRuntimeConfig::apply(true);

        /*
         * Status TIDAK ditanyakan ke WAHA saat halaman dibuka. Container yang
         * mati membuat panggilan itu menggantung sampai timeout — layar ini
         * ikut menggantung persis pada saat orang membukanya untuk mencari
         * tahu kenapa notifikasi berhenti. Yang ditampilkan adalah hasil
         * pemeriksaan terakhir yang sudah disimpan WahaHealthService; yang
         * terbaru diambil lewat tombol.
         */
        $panel = [];

        foreach (PeranWaha::SEMUA as $peran) {
            $sesi = $waha->sesi($peran);

            $panel[$peran] = [
                'peran'      => $peran,
                'label'      => PeranWaha::label($peran),
                'penjelasan' => PeranWaha::penjelasan($peran),
                'nama_sesi'  => $waha->namaSesi($peran),
                'tertaut'    => $waha->pernahTertaut($peran),
                'status'     => (string) ($sesi['last_status'] ?? ''),
                'diperiksa'  => ($sesi['last_checked_at'] ?? null) ? Carbon::parse($sesi['last_checked_at']) : null,
            ];
        }

        return view('erp.settings.waha.edit', [
            /*
             * Alamat cermin (Tahap 6). Tokennya dibuat saat pertama kali layar
             * ini dibuka — di sinilah satu-satunya tempat orang bisa
             * menyalinnya, jadi menundanya sampai "nanti kalau dipakai" cuma
             * berarti tombol Pasang yang gagal tanpa sebab yang terlihat.
             */
            'webhookUrl'  => route('crm.waha.webhook', $waha->webhookToken()),
            'waha'        => $waha,
            'panel'       => $panel,
            'driver'      => (string) config('crm.notifikasi.driver', 'resmi'),
            'dryRun'      => $chat->isDryRun(),
            'daftarPutih' => (array) config('crm.allowed_recipients', []),
            // Panel QR mana yang terbuka. Nilainya nama peran, bukan sekadar
            // '1': dua panel QR yang terbuka bersamaan membuat orang memindai
            // kode milik nomor yang salah.
            'qrPeran'     => PeranWaha::sah(request('qr')) ? (string) request('qr') : null,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'waha_enabled'            => 'nullable|boolean',
            'waha_api_key'            => 'nullable|string|max:255',
            'waha_base_url'           => 'nullable|url|max:255',
            'waha_session_utama'      => 'nullable|string|max:64',
            'waha_session_notifikasi' => 'nullable|string|max:64',
            'notifikasi_driver'       => 'nullable|in:resmi,waha',
        ]);

        $sesiUtama      = trim((string) ($data['waha_session_utama'] ?? '')) ?: PeranWaha::bawaanSesi(PeranWaha::UTAMA);
        $sesiNotifikasi = trim((string) ($data['waha_session_notifikasi'] ?? '')) ?: PeranWaha::bawaanSesi(PeranWaha::NOTIFIKASI);

        /*
         * Dua peran yang menunjuk sesi yang sama berarti satu nomor memikul
         * kedua peran — dan itu membatalkan seluruh alasan keduanya dipisah:
         * risiko blokir yang lahir dari mengirim duluan akan ditanggung oleh
         * nomor yang jadi identitas toko. Ditolak di sini, bukan sekadar
         * diperingatkan, karena akibatnya tidak bisa dibatalkan.
         */
        if ($sesiUtama === $sesiNotifikasi) {
            return back()
                ->withInput()
                ->with('error', 'Nama sesi nomor utama dan nomor notifikasi tidak boleh sama ("'
                    . $sesiUtama . '"). Keduanya harus nomor yang berbeda — itulah yang membuat '
                    . 'nomor utama tidak ikut menanggung risiko blokir.');
        }

        $waha = CrmSetting::for('waha');

        $waha->is_enabled = (bool) ($data['waha_enabled'] ?? false);
        $waha->base_url   = ($data['waha_base_url'] ?? null) ?: null;

        // Kunci dibiarkan kosong = JANGAN diubah. Nilainya tak pernah
        // ditampilkan balik ke layar, jadi menimpanya dengan string kosong
        // akan mematikan jalur notifikasi tanpa disadari siapa pun.
        if (filled($data['waha_api_key'] ?? null)) {
            $waha->api_key = trim($data['waha_api_key']);
        }

        $waha->config = array_merge((array) $waha->config, [
            'notifikasi_driver' => $data['notifikasi_driver'] ?? 'resmi',
        ]);

        $waha->save();

        $waha->simpanSesi(PeranWaha::UTAMA, ['session' => $sesiUtama]);
        $waha->simpanSesi(PeranWaha::NOTIFIKASI, ['session' => $sesiNotifikasi]);

        $this->bersihkanJejakLama();

        CrmRuntimeConfig::forget();
        CrmRuntimeConfig::apply();

        $pesan = 'Pengaturan WAHA disimpan.';

        if (($waha->config['notifikasi_driver'] ?? 'resmi') === 'waha') {
            $pesan .= ' Notifikasi pesanan sekarang lewat WAHA (jalur tak resmi).';
        } else {
            $pesan .= ' Notifikasi pesanan tetap lewat template resmi Meta (berbayar).';
        }

        return redirect()->route('settings.waha.edit')->with('success', $pesan);
    }

    /**
     * Uji sesi satu nomor. Bukan sekadar "kredensial benar": yang dijawab
     * adalah apakah nomornya masih TERTAUT. Sesi bisa putus tanpa gejala apa
     * pun (HP mati, WhatsApp mengeluarkan perangkat tertaut), dan sejak itu
     * tak satu pun pesan berangkat.
     */
    public function uji(string $peran)
    {
        $waha = $this->siap($peran);

        if (! $waha instanceof CrmSetting) {
            return $waha;
        }

        $adapter = new WahaProvider($waha, $peran);
        $status  = $adapter->statusJalur();

        $waha->catatStatusSesi($peran, $status);

        if ($status['siap']) {
            return back()->with('success', PeranWaha::label($peran) . ' tertaut lewat sesi "'
                . $adapter->sesi() . '" (status WORKING).');
        }

        return back()->with('error', PeranWaha::label($peran) . ' belum siap — status ' . $status['status']
            . ($status['keterangan'] ? ': ' . $status['keterangan'] : '') . ' ' . $this->saran($status['status'], $peran));
    }

    /**
     * Daftarkan alamat cermin pada sesi peran ini.
     *
     * Hanya peran UTAMA yang punya cermin: yang dicermin adalah chat
     * pelanggan, dan nomor notifikasi tidak menerima chat — ia menerima
     * balasan atas notifikasi, yang sudah punya jawaban otomatisnya sendiri.
     * Memasangnya di sana hanya akan mengisi inbox dengan thread yang tak
     * seorang pun ditugasi membacanya.
     */
    public function pasangWebhook(string $peran)
    {
        if ($peran !== PeranWaha::UTAMA) {
            return back()->with('error', 'Cermin chat hanya untuk nomor utama.');
        }

        $waha = $this->siap($peran);

        if (! $waha instanceof CrmSetting) {
            return $waha;
        }

        $url   = route('crm.waha.webhook', $waha->webhookToken());
        $hasil = (new WahaProvider($waha, $peran))->pasangWebhook($url);

        if (! $hasil['success']) {
            return back()->with('error', 'Gagal memasang cermin: ' . $hasil['error']);
        }

        return back()->with('success', 'Cermin terpasang pada sesi nomor utama. '
            . 'WAHA me-restart sesinya beberapa detik — nomornya TIDAK perlu dipindai ulang. '
            . 'Kirim satu pesan uji dari HP lain untuk memastikannya muncul di Inbox.');
    }

    /**
     * Hidupkan sesi supaya QR terbit. Sesi yang belum pernah ada ikut dibuat
     * di sini — pemasangan nomor pertama kali kalau tidak selalu berakhir 404
     * dengan galat yang tak menjelaskan apa-apa.
     */
    public function tautkan(string $peran)
    {
        $waha = $this->siap($peran);

        if (! $waha instanceof CrmSetting) {
            return $waha;
        }

        $res = (new WahaProvider($waha, $peran))->pastikanSesiHidup();

        if (! $res['success']) {
            return back()->with('error', 'Sesi ' . PeranWaha::label($peran) . ' tidak bisa dihidupkan: ' . $res['error']);
        }

        return redirect()->route('settings.waha.edit', ['qr' => $peran])
            ->with('success', 'Sesi dihidupkan. QR muncul di bawah — pindai dari HP pemegang '
                . PeranWaha::label($peran) . ' (WhatsApp → Perangkat Tertaut → Tautkan Perangkat).');
    }

    /**
     * Putuskan nomor yang tertaut lalu terbitkan QR baru — inilah cara ganti
     * nomor WhatsApp tanpa menyentuh server.
     *
     * Merusak dengan sengaja: sejak tombol ini ditekan sampai QR baru dipindai,
     * nomor itu tidak melayani apa pun.
     */
    public function putuskan(string $peran)
    {
        $waha = $this->siap($peran);

        if (! $waha instanceof CrmSetting) {
            return $waha;
        }

        $res = (new WahaProvider($waha, $peran))->putuskanSesi();

        if (! $res['success']) {
            return back()->with('error', 'Gagal memutuskan ' . PeranWaha::label($peran) . ': ' . $res['error']);
        }

        $waha->catatStatusSesi($peran, ['siap' => false, 'status' => 'SCAN_QR_CODE']);

        return redirect()->route('settings.waha.edit', ['qr' => $peran])
            ->with('success', 'Nomor lama diputus dari ' . PeranWaha::label($peran)
                . '. Pindai QR di bawah dengan HP nomor yang baru.');
    }

    /**
     * Gambar QR (PNG mentah) untuk ditempel di <img>.
     *
     * no-store WAJIB: QR berputar tiap ±20 detik, dan gambar basi yang
     * disajikan ulang browser membuat orang memindai kode kedaluwarsa
     * berkali-kali lalu menyimpulkan fiturnya rusak.
     */
    public function qr(string $peran)
    {
        $waha = CrmSetting::for('waha');

        abort_unless(PeranWaha::sah($peran) && $waha->isConfigured(), 404);

        $png = (new WahaProvider($waha, $peran))->qr();

        // 404, bukan 500: sesi yang sudah WORKING memang tidak punya QR, dan
        // itu keadaan normal — <img> yang gagal dimuat sudah jawaban yang benar.
        abort_if($png === null || $png === '', 404);

        return response($png, 200, [
            'Content-Type'  => 'image/png',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * Status ringkas untuk polling panel QR. Dipanggil tiap beberapa detik
     * saat QR sedang ditampilkan, supaya layarnya tahu sendiri kapan sesi
     * berubah jadi WORKING dan berhenti menyuruh orang memindai.
     */
    public function status(string $peran)
    {
        $waha = CrmSetting::for('waha');

        if (! PeranWaha::sah($peran) || ! $waha->isConfigured()) {
            return response()->json(['siap' => false, 'status' => 'BELUM_DIATUR']);
        }

        $status = (new WahaProvider($waha, $peran))->statusJalur();

        $waha->catatStatusSesi($peran, $status);

        return response()->json([
            'siap'   => (bool) $status['siap'],
            'status' => (string) $status['status'],
        ]);
    }

    /**
     * Penjaga bersama tiga aksi: peran harus dikenal DAN kredensialnya terisi.
     * Mengembalikan baris pengaturan bila lolos, atau jawaban redirect bila
     * tidak — memanggil WAHA tanpa kunci hanya menghasilkan galat 401 yang
     * dibaca orang sebagai "sesinya putus".
     */
    private function siap(string $peran)
    {
        abort_unless(PeranWaha::sah($peran), 404);

        $waha = CrmSetting::for('waha');

        if (! $waha->isConfigured()) {
            return back()->with('error', 'Isi API Key WAHA dan centang "Aktifkan WAHA" dulu, lalu simpan.');
        }

        return $waha;
    }

    /**
     * Nilai lama `notifikasi_driver` mengendap di baris apicoid dari zaman
     * layarnya masih menyatu. Dibuang setelah baris waha punya nilainya
     * sendiri, kalau tidak ada dua tempat menyimpan jawaban untuk satu
     * pertanyaan dan yang menang bergantung urutan kode.
     */
    private function bersihkanJejakLama(): void
    {
        $apicoid = CrmSetting::query()->where('provider', 'apicoid')->first();

        if (! $apicoid || ! array_key_exists('notifikasi_driver', (array) $apicoid->config)) {
            return;
        }

        $config = (array) $apicoid->config;
        unset($config['notifikasi_driver']);

        $apicoid->config = $config;
        $apicoid->save();
    }

    /**
     * Saran tindakan menyesuaikan sebab, bukan satu kalimat untuk semua.
     * "Scan ulang QR" pada kasus kunci yang ditolak mengirim orang mencari HP
     * dan memindai QR, padahal yang salah cuma satu kolom di layar ini.
     */
    private function saran(string $status, string $peran): string
    {
        $waha = CrmSetting::for('waha');

        return match ($status) {
            /*
             * Sebab yang paling sering & paling membingungkan: nilai env
             * berbentuk 'sha512:...' — itu HASH, bukan kuncinya. Klien wajib
             * mengirim nilai polosnya, yang tidak bisa dibalik dari hash. Tanpa
             * disebut di sini, orang akan menyalin hash itu berulang kali dan
             * bertanya-tanya kenapa ditolak terus.
             */
            'KUNCI_DITOLAK'  => 'WAHA menolak API Key-nya. Ambil dari env container (WAHA_API_KEY, versi lama: WHATSAPP_API_KEY). '
                                . 'Bila nilainya diawali "sha512:", itu hash — yang harus diisi di sini nilai polosnya, bukan hash-nya.',
            'SESI_TIDAK_ADA' => 'Kunci & alamatnya SUDAH benar — yang tidak ada cuma sesi bernama "'
                                . $waha->namaSesi($peran)
                                . '". Tekan "Tautkan Nomor" di panel ini untuk membuat & menghidupkannya, atau samakan namanya '
                                . 'dengan sesi yang sudah berstatus WORKING di dasbor WAHA.',
            'TAK_TERJANGKAU' => 'ERP tidak bisa menjangkau WAHA di ' . $waha->effectiveBaseUrl()
                                . '. Pastikan container hidup, dan bila menguji dari laptop, terowongan SSH ke port 3000 terbuka.',
            'SCAN_QR_CODE'   => 'Sesi menunggu dipindai. Tekan "Tautkan Nomor" di panel ini, lalu pindai QR-nya dari HP pemegang nomor.',
            'STOPPED', 'FAILED' => 'Sesi berhenti. Tekan "Tautkan Nomor" di panel ini — sesinya dinyalakan dulu, QR baru terbit setelah itu.',
            default          => 'Periksa keadaan sesi di WAHA.',
        };
    }
}
