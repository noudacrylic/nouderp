<?php

namespace App\Console\Commands;

use App\Models\CrmSetting;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Providers\WahaProvider;
use App\Modules\CRM\Services\WahaCerminService;
use App\Modules\CRM\Support\PeranWaha;
use Illuminate\Console\Command;

/**
 * Tarik riwayat chat nomor utama dari WAHA ke cermin Inbox — SEKALI JALAN.
 *
 * Webhook cermin hanya merekam pesan yang datang SESUDAH ia terpasang. Yang
 * sudah ada di HP sebelum itu tidak akan pernah muncul sendiri; perintah inilah
 * yang menambal masa lalu.
 *
 * ────────────────────── YANG PERLU DIKETAHUI DULU ──────────────────────
 * WAHA BUKAN arsip WhatsApp. NOWEB hanya memegang apa yang dikirim WhatsApp
 * saat nomornya ditautkan, ditambah yang lewat sesudahnya. Dibuktikan di
 * server 24 Sep 2026: dari 656 chat, yang punya isi hanya yang aktif beberapa
 * bulan terakhir (tertua 19 Feb 2026); chat yang terakhir aktif ≥1 tahun lalu
 * mengembalikan NOL pesan meski namanya ada di daftar. Chat paling ramai pun
 * cuma 137 pesan, dan menaikkan `limit` di atas 500 tidak menambah apa pun.
 *
 * Jadi perintah ini tidak bisa — dan tidak berpura-pura bisa — memulihkan
 * seluruh riwayat WhatsApp.
 *
 * AMAN DIULANG. Idempotensinya bukan dari pencatatan sendiri melainkan dari
 * `crm_messages.provider_message_id` yang unik: pesan yang sudah pernah
 * direkam ditolak basis data, entah ia datang lewat webhook atau lewat sini.
 * Menjalankannya dua kali tidak menggandakan apa pun.
 *
 * MEDIA. `--media` menyuruh WAHA mengunduh media lama dari server WhatsApp
 * lebih dulu supaya alamatnya terisi. Berkasnya TIDAK diunduh di sini —
 * barisnya dicatat, lalu `crm:unduh-lampiran` (sudah terjadwal tiap menit,
 * 100 berkas per jalan) yang memindahkannya ke penyimpanan ERP. Pola yang sama
 * dengan jalur webhook, dan alasannya sama: satu proses panjang yang
 * mengunduh ribuan berkas sekaligus akan mati di tengah jalan tanpa
 * meninggalkan jejak yang bisa dilanjutkan.
 */
class CrmImporCerminWaha extends Command
{
    protected $signature = 'crm:impor-cermin-waha
                            {--chat= : Impor SATU chat saja (id lengkap, mis. 628123@s.whatsapp.net) — untuk uji}
                            {--pesan=500 : Maksimum pesan per chat}
                            {--batas-chat=0 : Berhenti setelah sekian chat (0 = semua)}
                            {--sejak= : Hanya chat yang aktif sejak tanggal ini (YYYY-MM-DD)}
                            {--media : Minta WAHA menyiapkan media lama supaya lampirannya bisa diunduh}
                            {--dry-run : Hitung saja, jangan tulis apa pun}';

    protected $description = 'Tarik riwayat chat nomor utama dari WAHA ke cermin Inbox (aman diulang)';

    public function handle(WahaCerminService $cermin): int
    {
        $waha = CrmSetting::for('waha');

        if (! $waha->isConfigured()) {
            $this->error('Jalur WAHA belum dikonfigurasi — isi di Pengaturan → WhatsApp Self-Host.');

            return self::FAILURE;
        }

        $adapter = new WahaProvider($waha, PeranWaha::UTAMA);

        /*
         * Sesi diperiksa DULU. Menarik riwayat dari sesi yang tidak WORKING
         * mengembalikan daftar kosong atau separuh — dan hasilnya terbaca
         * seperti "riwayatnya memang cuma segitu", kesimpulan salah yang sulit
         * diralat karena perintah ini aman diulang dan orang akan mengira
         * sudah selesai.
         */
        $status = $adapter->statusJalur();

        if (! $status['siap']) {
            $this->error('Sesi nomor utama berstatus ' . $status['status'] . ' — impor dibatalkan.');
            $this->line('Tautkan/periksa dulu di Pengaturan → WhatsApp Self-Host, lalu ulangi.');

            return self::FAILURE;
        }

        $daftar = $this->chatYangDiimpor($adapter);

        if ($daftar === null) {
            return self::FAILURE;
        }

        if (! $daftar) {
            $this->info('Tidak ada chat yang memenuhi saringan.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d chat akan ditelusuri%s%s.',
            count($daftar),
            $this->option('media') ? ', media lama ikut disiapkan' : '',
            $this->option('dry-run') ? ' (UJI COBA — tidak ada yang ditulis)' : ''
        ));

        return $this->telusuri($adapter, $cermin, $daftar);
    }

    /**
     * Daftar id chat yang akan ditelusuri, terbaru lebih dulu.
     *
     * Urutan itu disengaja: proses panjang bisa terputus (koneksi, Ctrl-C,
     * reboot), dan yang paling berharga untuk sudah-terlanjur-masuk adalah
     * chat yang baru saja aktif — bukan yang mengendap sejak Februari.
     *
     * @return string[]|null  null = gagal menghubungi WAHA
     */
    private function chatYangDiimpor(WahaProvider $adapter): ?array
    {
        if ($satu = trim((string) $this->option('chat'))) {
            return [$satu];
        }

        $res = $adapter->daftarChat();

        if (! $res['success']) {
            $this->error('Gagal mengambil daftar chat: ' . $res['error']);

            return null;
        }

        $sejak = trim((string) $this->option('sejak'));
        $batas = $sejak !== '' ? strtotime($sejak) : null;

        if ($sejak !== '' && ! $batas) {
            $this->error('Tanggal --sejak tidak terbaca: ' . $sejak);

            return null;
        }

        $chat = collect($res['chat'])
            ->map(fn ($c) => [
                'id'    => (string) data_get($c, 'id', ''),
                'waktu' => $this->detik(data_get($c, 'conversationTimestamp')),
            ])
            ->filter(fn ($c) => $c['id'] !== '')
            // Grup, status, newsletter, dan @lid disaring di service yang sama
            // dengan webhook — supaya aturannya cuma hidup di SATU tempat.
            ->filter(fn ($c) => WahaCerminService::perorangan($c['id']))
            ->when($batas, fn ($k) => $k->filter(fn ($c) => $c['waktu'] >= $batas))
            ->sortByDesc('waktu')
            ->pluck('id')
            ->values();

        $batasChat = (int) $this->option('batas-chat');

        return $batasChat > 0 ? $chat->take($batasChat)->all() : $chat->all();
    }

    /** @param  string[]  $daftar */
    private function telusuri(WahaProvider $adapter, WahaCerminService $cermin, array $daftar): int
    {
        $bar = $this->output->createProgressBar(count($daftar));
        $bar->start();

        $pesanBaru = 0;
        $pesanLewat = 0;
        $chatKosong = 0;
        $chatGagal = [];

        foreach ($daftar as $chatId) {
            $res = $adapter->riwayatChat(
                $chatId,
                (int) $this->option('pesan'),
                (bool) $this->option('media')
            );

            if (! $res['success']) {
                // Satu chat yang gagal TIDAK menghentikan sisanya. Penyebab
                // tersering adalah timeout pada chat bermedia sangat banyak,
                // dan menghentikan seluruh impor karenanya berarti 500 chat
                // lain ikut tak terimpor demi satu yang rewel.
                $chatGagal[$chatId] = $res['error'];
                $bar->advance();

                continue;
            }

            if (! $res['pesan']) {
                $chatKosong++;
                $bar->advance();

                continue;
            }

            foreach ($res['pesan'] as $payload) {
                if ($this->option('dry-run')) {
                    $pesanBaru++;

                    continue;
                }

                $cermin->rekam((array) $payload, $chatId) ? $pesanBaru++ : $pesanLewat++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        return $this->laporkan($pesanBaru, $pesanLewat, $chatKosong, $chatGagal);
    }

    private function laporkan(int $baru, int $lewat, int $kosong, array $gagal): int
    {
        if ($this->option('dry-run')) {
            $this->info("Uji coba: {$baru} pesan AKAN direkam, {$kosong} chat tanpa riwayat.");

            return self::SUCCESS;
        }

        $this->info("Pesan direkam: {$baru} baru, {$lewat} dilewati (sudah ada / bukan chat perorangan).");
        $this->line("Chat tanpa riwayat di WAHA: {$kosong}.");

        $menunggu = \App\Modules\CRM\Models\CrmAttachment::belumTerunduh()->count();

        if ($menunggu > 0) {
            $this->line("Lampiran menunggu diunduh: {$menunggu}. "
                . 'Penjadwal mengambil 100 per menit lewat crm:unduh-lampiran — '
                . 'perkiraan selesai ~' . (int) ceil($menunggu / 100) . ' menit.');
        }

        $this->line('Percakapan cermin sekarang: '
            . CrmConversation::where('channel', CrmConversation::KANAL_CERMIN)->count() . '.');

        if ($gagal) {
            $this->newLine();
            $this->warn(count($gagal) . ' chat gagal ditarik — ulangi perintah ini untuk mencobanya lagi '
                . '(yang sudah masuk tidak akan digandakan):');

            foreach (array_slice($gagal, 0, 10, true) as $id => $pesan) {
                $this->line("  {$id} — {$pesan}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * `conversationTimestamp` datang sebagai detik, kadang sebagai milidetik,
     * kadang sebagai objek. Yang salah baca akan menggeser saringan --sejak
     * puluhan tahun tanpa ada yang menyadarinya.
     */
    private function detik(mixed $nilai): int
    {
        if (is_array($nilai)) {
            $nilai = $nilai['low'] ?? $nilai['seconds'] ?? 0;
        }

        $angka = (int) $nilai;

        return $angka > 100000000000 ? intdiv($angka, 1000) : $angka;
    }
}
