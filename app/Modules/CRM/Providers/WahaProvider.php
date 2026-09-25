<?php

namespace App\Modules\CRM\Providers;

use App\Models\CrmSetting;
use App\Modules\CRM\Contracts\NotificationProvider;
use App\Modules\CRM\Support\PeranWaha;
use App\Modules\CRM\Support\PhoneNumber;
use App\Modules\CRM\Support\WahaClient;
use App\Modules\CRM\Support\TemplateResmi;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Adapter WAHA (WhatsApp HTTP API) yang di-self-host di server on-prem.
 *
 * Ini jalur TIDAK RESMI: WAHA menautkan diri sebagai perangkat ke sebuah akun
 * WhatsApp biasa, persis seperti WhatsApp Web. Konsekuensinya melekat pada
 * seluruh berkas ini:
 *  - tidak ada template, tidak ada jendela 24 jam — yang dikirim teks biasa;
 *  - kalimatnya WAJIB dirangkai dari TemplateResmi, supaya bunyinya sama
 *    persis dengan yang disetujui Meta di jalur berbayar;
 *  - hanya untuk pesan TRANSAKSIONAL. Promosi/blast lewat jalur ini adalah
 *    cara tercepat membuat nomornya diblokir permanen;
 *  - akunnya milik HP pemegang SIM, bukan milik server. Sesi bisa putus
 *    (HP mati, WhatsApp keluarkan perangkat tertaut) — karena itu jawaban
 *    'tahan' ada, dan bukan sekadar 'gagal'.
 *
 * ENGINE TERKUNCI DI NOWEB. Bentuk payload webhook & endpoint WAHA berbeda
 * antar-engine; menggantinya ke WEBJS berarti membongkar adapter ini, dan
 * WEBJS memakai 3–4x memori (Chromium) di server yang sudah menampung ERP +
 * MariaDB.
 *
 * ⚠️ Alamatnya WAJIB 127.0.0.1. API key WAHA = kunci penuh sebuah akun
 * WhatsApp, dan instance WAHA terbuka rutin dipindai bot. Jangan pernah
 * menyambungkannya ke Cloudflare Tunnel — ERP & WAHA satu server.
 */
class WahaProvider implements NotificationProvider
{
    private CrmSetting $setting;

    private WahaClient $klien;

    /**
     * Peran menentukan SESI MANA — yaitu nomor WhatsApp mana — yang dipegang
     * instance ini. Satu container WAHA, beberapa nomor; adapter yang tidak
     * tahu perannya akan mengirim dari nomor yang kebetulan tersimpan
     * terakhir, dan di jalur ini "nomor yang salah" berarti pelanggan menerima
     * kabar dari nomor yang tidak dikenalnya.
     *
     * Bawaannya NOTIFIKASI supaya app(WahaProvider::class) — jalan yang
     * dipakai NotificationManager — tetap berarti persis seperti sebelumnya.
     */
    private string $peran;

    public function __construct(?CrmSetting $setting = null, string $peran = PeranWaha::NOTIFIKASI)
    {
        $this->setting = $setting ?: CrmSetting::for('waha');
        $this->peran   = PeranWaha::sah($peran) ? $peran : PeranWaha::NOTIFIKASI;
        $this->klien   = new WahaClient($this->setting);
    }

    public function key(): string
    {
        return 'waha';
    }

    public function peran(): string
    {
        return $this->peran;
    }

    public function isReady(): bool
    {
        return $this->setting->isConfigured();
    }

    /** Nama sesi WAHA yang dipegang instance ini. */
    public function sesi(): string
    {
        return $this->setting->namaSesi($this->peran);
    }

    public function kirimNotifikasi(array $payload): array
    {
        /*
         * Nomor utama TIDAK BOLEH mengirim notifikasi, dan penjagaannya ada di
         * sini — di adapter — bukan di pemanggil. Seluruh alasan kedua nomor
         * dipisah adalah supaya yang menanggung risiko blokir bukan identitas
         * toko; satu salah kabel di lapisan atas sudah cukup membatalkannya,
         * dan kesalahan seperti itu tidak menimbulkan gejala apa pun sampai
         * nomor utamanya keburu diblokir.
         *
         * Ditandai GAGAL, bukan ditahan: ini kesalahan pemasangan kabel yang
         * tidak akan pulih sendiri, dan barisnya harus terlihat di antrean
         * lengkap dengan alasannya.
         */
        if ($this->peran !== PeranWaha::NOTIFIKASI) {
            return $this->gagal('Sesi "' . $this->sesi() . '" berperan ' . $this->peran
                . ' — notifikasi hanya boleh berangkat dari nomor notifikasi.');
        }

        if (! $this->isReady()) {
            // Belum dikonfigurasi = keadaan sementara juga (tinggal diisi di
            // layar Pengaturan), jadi ditahan — bukan dihanguskan.
            return $this->tahan('Jalur WAHA belum dikonfigurasi.');
        }

        $to = PhoneNumber::normalize($payload['to'] ?? null);

        if (! $to) {
            return $this->gagal('Nomor tujuan tidak valid.');
        }

        $allowed = (array) config('crm.allowed_recipients', []);

        if ($allowed && ! in_array($to, $allowed, true)) {
            return $this->gagal("Nomor {$to} tidak ada di daftar putih penerima (config crm.allowed_recipients).");
        }

        $teks = TemplateResmi::render(
            (string) ($payload['template'] ?? ''),
            (array) ($payload['body'] ?? []),
            $payload['url_lacak'] ?? null
        );

        if (! $teks) {
            // Bukan keadaan sementara: nama templatenya memang tak dikenal.
            // Ditandai gagal supaya kelihatan, bukan mengendap di antrean.
            return $this->gagal('Template tidak dikenal: ' . ($payload['template'] ?? '(kosong)'));
        }

        /*
         * Perkenalan diri WAJIB, dan hanya di jalur ini. Nomor WAHA tak punya
         * centang hijau maupun nama bisnis terverifikasi, jadi pesan wajib
         * menyebut pengirimnya. Jalur resmi tidak memakainya: di sana identitas
         * kita sudah dijamin Meta, dan menambah teks ke template yang disetujui
         * bukan urusan kode ini.
         */
        $teks = TemplateResmi::perkenalkanDiri($teks);

        /*
         * Status sesi diperiksa DULU, sebelum mengirim. Tanpa ini, pesan yang
         * dikirim saat sesi mati ditolak WAHA dengan galat yang bentuknya
         * berubah-ubah dan mudah salah dibaca sebagai kegagalan permanen.
         */
        $status = $this->statusJalur();

        if (! $status['siap']) {
            return $this->tahan('Sesi WhatsApp "' . $this->sesi() . '" berstatus ' . $status['status'] . '.');
        }

        $res = $this->request('post', '/api/sendText', [
            'session' => $this->sesi(),
            'chatId'  => $to . '@c.us',
            'text'    => $teks,
            // Kartu pratinjau link harus diminta; tanpa ini URL lacak datang polos.
            'linkPreview' => str_contains($teks, 'http'),
        ]);

        if (! $res['success']) {
            /*
             * Tak terjangkau = container mati / sedang restart. Itu keadaan
             * sementara, dan justru saat itulah antrean paling perlu utuh.
             * Penolakan BERISI jawaban dari WAHA barulah kegagalan sungguhan.
             */
            return $res['terjangkau']
                ? $this->gagal((string) $res['error'])
                : $this->tahan((string) $res['error']);
        }

        $id = data_get($res['data'], 'id') ?? data_get($res['data'], '_data.id.id') ?? data_get($res['data'], 'key.id');

        return ['success' => true, 'message_id' => $id ? (string) $id : null, 'tahan' => false, 'error' => null];
    }

    /**
     * Status sesi WAHA. 'WORKING' = tertaut & siap; sisanya (STARTING,
     * SCAN_QR_CODE, STOPPED, FAILED) berarti belum bisa mengirim.
     */
    public function statusJalur(): array
    {
        if (! $this->isReady()) {
            return ['siap' => false, 'status' => 'BELUM_DIATUR', 'keterangan' => 'Jalur WAHA belum dikonfigurasi.'];
        }

        return $this->klien->statusSesi($this->sesi());
    }

    /**
     * Gambar QR sesi (PNG mentah), atau null bila tak bisa diambil.
     *
     * Urutannya penting dan berlawanan dengan dugaan: sesi harus di-START
     * dulu, QR baru ada. Karena itu pemanggil yang menemukan status STOPPED
     * memanggil mulaiSesi() lebih dulu. Sesi yang mati sendiri tanpa discan
     * adalah hal NORMAL (WAHA force-stop setelah menunggu terlalu lama),
     * bukan kerusakan — tinggal diulang.
     */
    public function qr(): ?string
    {
        if (! $this->isReady()) {
            return null;
        }

        try {
            $res = Http::withHeaders([
                'X-Api-Key' => (string) $this->setting->api_key,
                // Tanpa ini WAHA boleh menjawab JSON berisi base64; yang
                // dipakai pemanggil (Telegram sendPhoto, <img> di layar
                // Pengaturan) adalah PNG mentah.
                'Accept'    => 'image/png',
            ])
                ->timeout((int) config('crm.waha.timeout', 20))
                ->get($this->setting->effectiveBaseUrl() . '/api/' . rawurlencode($this->sesi()) . '/auth/qr', ['format' => 'image']);
        } catch (\Throwable $e) {
            Log::warning('[CRM] QR WAHA tidak terambil', ['error' => $e->getMessage()]);

            return null;
        }

        return $res->successful() && $res->body() !== '' ? $res->body() : null;
    }

    /** Nyalakan sesi. Aman dipanggil berulang; sesi yang sudah hidup tak terganggu. */
    public function mulaiSesi(): bool
    {
        return $this->request('post', '/api/sessions/' . rawurlencode($this->sesi()) . '/start')['success'];
    }

    /**
     * Pastikan sesi bernama ini ADA lalu hidup.
     *
     * Dipisah dari mulaiSesi() karena dua keadaan yang tampak sama menuntut
     * panggilan berbeda: sesi yang ada tapi mati cukup di-start, sedangkan
     * sesi yang belum pernah dibuat menjawab 404 pada /start dan harus
     * dibuat lebih dulu. Menyatukannya membuat pemasangan nomor pertama kali
     * selalu gagal dengan galat yang tak menjelaskan apa-apa.
     *
     * @return array{success:bool, error:?string}
     */
    public function pastikanSesiHidup(): array
    {
        $nama = $this->sesi();
        $ada  = $this->request('get', '/api/sessions/' . rawurlencode($nama));

        if (! $ada['success'] && ($ada['kode'] ?? 0) === 404) {
            /*
             * Engine TIDAK disebut di sini: nilainya ditentukan env container
             * (WHATSAPP_DEFAULT_ENGINE=NOWEB). Mengirimkannya dari ERP berarti
             * dua tempat yang bisa berbeda diam-diam, dan adapter ini hanya
             * benar untuk NOWEB.
             */
            $buat = $this->request('post', '/api/sessions', ['name' => $nama, 'start' => true]);

            return $buat['success']
                ? ['success' => true, 'error' => null]
                : ['success' => false, 'error' => 'Sesi "' . $nama . '" tidak bisa dibuat: ' . $buat['error']];
        }

        if (! $ada['success']) {
            return ['success' => false, 'error' => (string) $ada['error']];
        }

        $status = strtoupper((string) (data_get($ada['data'], 'status') ?: ''));

        // Sudah hidup (atau sedang menunggu dipindai) = jangan disentuh.
        // Me-restart sesi WORKING justru memutus nomor yang sedang sehat.
        if (in_array($status, ['WORKING', 'STARTING', 'SCAN_QR_CODE'], true)) {
            return ['success' => true, 'error' => null];
        }

        $mulai = $this->request('post', '/api/sessions/' . rawurlencode($nama) . '/start');

        return $mulai['success']
            ? ['success' => true, 'error' => null]
            : ['success' => false, 'error' => (string) $mulai['error']];
    }

    /**
     * Daftar chat yang dipegang sesi ini.
     *
     * ⚠️ `limit` WAJIB disebut besar. Bawaan WAHA memotong di 200 dan
     * memotongnya DIAM-DIAM — tak ada penanda "masih ada lagi" di jawabannya
     * (DIVERIFIKASI di server 24 Sep 2026: limit=200 mengembalikan tepat 200
     * dari 656 chat yang sebenarnya ada).
     *
     * Yang kembali cuma `id` dan `conversationTimestamp`; tidak ada nama, tidak
     * ada jumlah belum dibaca.
     *
     * @return array{success:bool, chat:array, error:?string}
     */
    public function daftarChat(int $limit = 5000): array
    {
        $res = $this->request('get', '/api/' . rawurlencode($this->sesi()) . '/chats?limit=' . $limit);

        return $res['success']
            ? ['success' => true, 'chat' => (array) $res['data'], 'error' => null]
            : ['success' => false, 'chat' => [], 'error' => (string) $res['error']];
    }

    /**
     * Riwayat pesan satu chat.
     *
     * `$media = true` menyuruh WAHA MENGUNDUH medianya lebih dulu dari server
     * WhatsApp supaya `media.url` terisi — itulah satu-satunya cara media lama
     * bisa diambil, dan itu pula sebabnya panggilan ini punya timeout
     * sendiri yang jauh lebih longgar. Timeout biasa (20 detik, dipakai kirim
     * & cek status) akan memutus chat bermedia banyak di tengah jalan, dan
     * gejalanya "sebagian chat kosong tanpa sebab".
     *
     * @return array{success:bool, pesan:array, error:?string}
     */
    public function riwayatChat(string $chatId, int $limit = 500, bool $media = false, int $timeout = 180): array
    {
        $path = '/api/' . rawurlencode($this->sesi())
            . '/chats/' . rawurlencode($chatId)
            . '/messages?limit=' . $limit
            . '&downloadMedia=' . ($media ? 'true' : 'false');

        $res = $this->request('get', $path, [], $timeout);

        return $res['success']
            ? ['success' => true, 'pesan' => (array) $res['data'], 'error' => null]
            : ['success' => false, 'pesan' => [], 'error' => (string) $res['error']];
    }

    /**
     * Daftarkan alamat webhook cermin pada sesi ini.
     *
     * ⚠️ WAHA me-RESTART sesi saat konfigurasinya diperbarui. Nomornya TIDAK
     * perlu dipindai ulang (kredensialnya tersimpan di volume), tapi ada jeda
     * beberapa detik saat sesi tidak WORKING — karena itu ini tombol yang
     * ditekan sadar, bukan sesuatu yang dijalankan diam-diam tiap pemeriksaan
     * berkala.
     *
     * Webhook lain pada sesi yang sama DIPERTAHANKAN; yang dicocokkan
     * alamatnya, bukan urutannya. Menimpa seluruh daftar berarti tiap kali
     * tombol ini ditekan, integrasi lain yang menumpang sesi yang sama mati
     * tanpa ada yang menyadarinya.
     *
     * @param  string[]  $events
     * @return array{success:bool, error:?string}
     */
    /**
     * `message.ack` ikut dari awal: tanpa langganan itu centang membeku di
     * keadaan saat pesan direkam, dan "sampai" tidak pernah berubah jadi
     * "dibaca". Mengubah daftar ini menuntut tombol "Pasang Cermin" ditekan
     * ulang — WAHA menyimpan langganannya di konfigurasi SESI, bukan di ERP.
     */
    public function pasangWebhook(string $url, array $events = ['message.any', 'message.ack']): array
    {
        $nama = $this->sesi();
        $ada  = $this->request('get', '/api/sessions/' . rawurlencode($nama));

        if (! $ada['success']) {
            return ['success' => false, 'error' => (string) $ada['error']];
        }

        $config   = (array) (data_get($ada['data'], 'config') ?: []);
        $webhooks = array_values(array_filter(
            (array) ($config['webhooks'] ?? []),
            fn ($w) => rtrim((string) data_get($w, 'url', ''), '/') !== rtrim($url, '/')
        ));

        $webhooks[]          = ['url' => $url, 'events' => array_values($events)];
        $config['webhooks']  = $webhooks;

        $simpan = $this->request('put', '/api/sessions/' . rawurlencode($nama), [
            'config' => $config,
        ]);

        return $simpan['success']
            ? ['success' => true, 'error' => null]
            : ['success' => false, 'error' => (string) $simpan['error']];
    }

    /**
     * Putuskan nomor yang sedang tertaut, lalu hidupkan sesinya lagi supaya
     * QR baru terbit. Ini jalur "ganti nomor WhatsApp": tanpa logout, WAHA
     * tetap memegang sesi lama dan QR tidak pernah muncul.
     *
     * @return array{success:bool, error:?string}
     */
    public function putuskanSesi(): array
    {
        $nama   = $this->sesi();
        $logout = $this->request('post', '/api/sessions/' . rawurlencode($nama) . '/logout');

        // 404 = memang belum ada sesinya; itu bukan kegagalan untuk niat
        // "mulai dari nol", jadi diteruskan ke pembuatan sesi di bawah.
        if (! $logout['success'] && ($logout['kode'] ?? 0) !== 404) {
            return ['success' => false, 'error' => (string) $logout['error']];
        }

        return $this->pastikanSesiHidup();
    }

    /**
     * Diteruskan ke WahaClient — penafsiran jawaban WAHA duduk di sana supaya
     * adapter chat (Tahap 7) memakai pembacaan yang sama persis.
     *
     * @return array{success:bool, data:array, terjangkau:bool, kode:int, error:?string}
     */
    private function request(string $method, string $path, array $body = [], ?int $timeout = null): array
    {
        return $this->klien->request($method, $path, $body, $timeout);
    }

    private function gagal(string $pesan): array
    {
        return ['success' => false, 'message_id' => null, 'tahan' => false, 'error' => $pesan];
    }

    private function tahan(string $pesan): array
    {
        return ['success' => false, 'message_id' => null, 'tahan' => true, 'error' => $pesan];
    }
}
