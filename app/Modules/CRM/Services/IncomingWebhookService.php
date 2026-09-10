<?php

namespace App\Modules\CRM\Services;

use App\Models\Customer;
use App\Modules\CRM\Models\CrmAttachment;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Models\CrmOutboxMessage;
use App\Modules\CRM\Support\PhoneNumber;
use Carbon\Carbon;

/**
 * Menerjemahkan satu kiriman webhook menjadi baris percakapan & pesan.
 *
 * Amplopnya: event_type + event_id + timestamp + data.
 * Peristiwa: message.received / sent / delivered / read / failed.
 *
 * ⚠️ Nama field di dalam `data` dibaca lewat BEBERAPA alias, dan itu TERBUKTI
 * perlu: payload sungguhan (5 Sep 2026, tersimpan di
 * tests/Fixtures/crm/apicoid-message-received.json) memakai `customer_phone`,
 * sementara dokumentasi vendor menyebut `phone_number`. Nomor pengirim juga
 * muncul lagi di `raw.from`, dan wamid Meta di `raw.id`. Alias inilah yang
 * membedakan "pesan masuk" dari "percakapan lahir tanpa pemilik".
 */
class IncomingWebhookService
{
    public function tangani(array $envelope): void
    {
        $type = (string) ($envelope['event_type'] ?? '');
        $data = (array) ($envelope['data'] ?? []);
        $waktu = $this->waktu($envelope['timestamp'] ?? ($data['timestamp'] ?? null));

        match ($type) {
            'message.received' => $this->pesanMasuk($data, $waktu),
            'message.sent'     => $this->pesanKeluar($data, $waktu),
            'message.delivered', 'message.read', 'message.failed' => $this->perbaruiStatus($type, $data),
            default            => null,   // peristiwa yang belum kami pedulikan: dicatat di crm_webhook_events, cukup.
        };
    }

    /* ------------------------------------------------------------------ masuk */

    private function pesanMasuk(array $data, Carbon $waktu): void
    {
        $percakapan = $this->percakapan($data);
        $pesan      = $this->simpanPesan($percakapan, $data, CrmMessage::MASUK, $waktu);

        if (! $pesan) {
            return;   // duplikat yang ditolak di tingkat basis data
        }

        $this->simpanLampiran($pesan, $data);

        /*
         * Balasan pelanggan MEMBUKA jendela 24 jam. Disimpan (bukan ditanya ke
         * API tiap kali) supaya daftar percakapan bisa menandai thread tanpa
         * satu panggilan API per baris.
         */
        $percakapan->forceFill([
            'last_inbound_at'   => $waktu,
            'last_message_at'   => $waktu,
            'window_expires_at' => $waktu->copy()->addHours(24),
            'unread_count'      => $percakapan->unread_count + 1,
            'queue_state'       => CrmConversation::QUEUE_KITA,
            'status'            => CrmConversation::STATUS_AKTIF,
        ])->save();

        /*
         * Kabari tim. Ditaruh PALING AKHIR, sesudah pesan & percakapan
         * tersimpan: yang tidak boleh hilang adalah pesannya, bukan
         * notifikasinya. Servicenya sendiri tidak pernah melempar.
         */
        app(NotifikasiChatService::class)->pesanMasuk($percakapan, $pesan);
    }

    /* ----------------------------------------------------------------- keluar */

    private function pesanKeluar(array $data, Carbon $waktu): void
    {
        $percakapan = $this->percakapan($data);
        $pesan      = $this->simpanPesan($percakapan, $data, CrmMessage::KELUAR, $waktu);

        if ($pesan) {
            $this->simpanLampiran($pesan, $data);
        }

        /*
         * Bola berpindah ke pelanggan, DAN unread dinolkan — termasuk ketika
         * admin membalas dari HP. Kalau tidak, percakapan yang sudah dijawab
         * tetap menumpuk di "Menunggu Kita" dan antreannya berhenti dipercaya.
         */
        $percakapan->forceFill([
            'last_outbound_at' => $waktu,
            'last_message_at'  => $waktu,
            'unread_count'     => 0,
            'queue_state'      => CrmConversation::QUEUE_PELANGGAN,
        ])->save();
    }

    /* ----------------------------------------------------------- status kirim */

    private function perbaruiStatus(string $type, array $data): void
    {
        $status = match ($type) {
            'message.delivered' => 'delivered',
            'message.read'      => 'read',
            default             => 'failed',
        };

        $id = $this->messageId($data);

        if (! $id) {
            return;
        }

        $error = $status === 'failed'
            ? (string) (data_get($data, 'error.message') ?? $data['error'] ?? $data['reason'] ?? 'Gagal tanpa keterangan.')
            : null;

        CrmMessage::where('provider_message_id', $id)
            ->orWhere('wam_id', $id)
            ->update(['status' => $status, 'error' => $error]);

        $this->rekamWamid($id, $data);

        /*
         * Notifikasi yang sudah terlanjur ditandai "terkirim" ternyata ditolak
         * di hilir (nomor tidak punya WhatsApp, saldo habis, template ditolak).
         * Tanpa langkah ini, outbox akan selamanya mengaku berhasil padahal
         * pelanggan tidak pernah menerima apa pun.
         */
        if ($status === 'failed') {
            CrmOutboxMessage::where('provider_message_id', $id)
                ->get()
                ->each(fn (CrmOutboxMessage $baris) => $baris->tandaiGagal($error));
        }
    }

    /**
     * Catat wamid milik pesan KELUAR.
     *
     * Ini satu-satunya tempat kita bisa mendapatkannya. Jawaban vendor saat
     * mengirim hanya membawa id internalnya sendiri (bentuk `cmtp…`), sedangkan
     * yang dikenal WhatsApp adalah wamid (`wamid.HBg…`) — dan tanpa wamid,
     * pesan kita sendiri TIDAK BISA DIKUTIP: penanda balasan yang memakai id
     * vendor diterima API tanpa keluhan lalu diabaikan diam-diam, jadi
     * pesannya sampai polos tanpa kutipan dan tak ada gejala apa pun di ERP.
     *
     * Wamid-nya menumpang peristiwa 'delivered'/'read' (bukan 'sent', yang
     * belum membawanya), jadi pesan yang belum sampai memang belum bisa
     * dikutip — dan itu memang benar: pesan yang belum sampai belum punya
     * wamid di sisi Meta.
     */
    private function rekamWamid(string $id, array $data): void
    {
        $wamid = data_get($data, 'raw.id');

        if (! is_string($wamid) || ! str_starts_with($wamid, 'wamid.')) {
            return;
        }

        CrmMessage::where('provider_message_id', $id)
            ->whereNull('wam_id')
            ->update(['wam_id' => $wamid]);
    }

    /* ---------------------------------------------------------------- bantuan */

    /** Percakapan pemilik pesan ini — dibuat bila kontaknya belum pernah menyapa. */
    private function percakapan(array $data): CrmConversation
    {
        $kontak  = $this->kontak($data);
        $kanal   = (string) ($data['channel'] ?? 'whatsapp');
        $nomorId = $data['phone_number_id'] ?? $data['whatsapp_phone_number_id'] ?? null;

        $percakapan = CrmConversation::findOrCreateFor($kontak, $kanal, $nomorId ? (string) $nomorId : null);

        $ubah = [];

        if (($nama = $data['customer_name'] ?? data_get($data, 'customer.name')) && ! $percakapan->display_name) {
            $ubah['display_name'] = (string) $nama;
        }

        if (($cid = $data['customer_id'] ?? data_get($data, 'customer.id')) && ! $percakapan->provider_customer_id) {
            $ubah['provider_customer_id'] = (string) $cid;
        }

        // Pencocokan ke master pelanggan. Gagal cocok BUKAN kesalahan — justru
        // itu definisi lead: percakapan yang belum punya dokumen apa pun.
        if (! $percakapan->customer_id && $kanal === 'whatsapp') {
            if ($customer = $this->cariPelanggan($percakapan->contact_key)) {
                $ubah['customer_id'] = $customer->id;
            }
        }

        if ($ubah) {
            $percakapan->forceFill($ubah)->save();
        }

        return $percakapan;
    }

    /**
     * Cocokkan nomor ke master pelanggan lewat 9 digit terakhir.
     *
     * Nomor di master ditulis manusia dengan segala bentuk ('0899-8844-666',
     * '+62 899…'), jadi membandingkan apa adanya pasti meleset. Ekornya
     * dibandingkan setelah dinormalkan, bukan lewat LIKE mentah, supaya
     * '628998844666' tidak keliru dianggap sama dengan '62899884466'.
     */
    private function cariPelanggan(string $contactKey): ?Customer
    {
        $ekor = substr($contactKey, -9);

        if (strlen($ekor) < 9) {
            return null;
        }

        /*
         * Pemisah dibuang DI SQL sebelum dibandingkan. Tanpa ini, ekor yang
         * sudah dinormalkan ('998844666') diadu dengan isi kolom apa adanya
         * ('0899-8844-666') dan tidak pernah cocok — pelanggan lama selamanya
         * terlihat sebagai lead baru.
         */
        $bersih = fn (string $kolom) => "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$kolom}, '-', ''), ' ', ''), '+', ''), '(', ''), ')', '')";

        return Customer::where(fn ($q) => $q
                ->whereRaw($bersih('phone') . ' LIKE ?', ['%' . $ekor])
                ->orWhereRaw($bersih('recipient_phone') . ' LIKE ?', ['%' . $ekor]))
            ->get()
            // LIKE hanya menyaring kasar; kecocokan tepatnya diputuskan di PHP
            // supaya '62899884466' tidak pernah lolos sebagai '628998844666'.
            ->first(fn (Customer $c) => in_array($contactKey, array_filter([
                PhoneNumber::normalize($c->phone),
                PhoneNumber::normalize($c->recipient_phone),
            ]), true));
    }

    /**
     * Simpan pesan. Null bila `provider_message_id` sudah ada — kembar ditolak
     * basis data, bukan oleh pemeriksaan kita.
     */
    private function simpanPesan(CrmConversation $percakapan, array $data, string $arah, Carbon $waktu): ?CrmMessage
    {
        $id = $this->messageId($data);

        if ($id && CrmMessage::where('provider_message_id', $id)->exists()) {
            return null;
        }

        try {
            return CrmMessage::create([
                'conversation_id'     => $percakapan->id,
                'direction'           => $arah,
                'message_type'        => (string) ($data['message_type'] ?? $data['type'] ?? 'text'),
                'content'             => $this->isi($data),
                'source'              => $arah === CrmMessage::KELUAR ? $this->source($data) : null,
                'provider_message_id' => $id,
                // wamid asli Meta ada di 'raw.id' pada kiriman sungguhan; itu yang
                // dipakai peristiwa delivered/read/failed untuk menemukan pesannya.
                'wam_id'              => $data['wam_id'] ?? $data['whatsapp_message_id'] ?? data_get($data, 'raw.id') ?? null,
                'reply_to_wam_id'     => $data['reply_to_message_id'] ?? null,
                'status'              => $arah === CrmMessage::KELUAR ? 'terkirim' : null,
                'sent_at'             => $waktu,
                'raw'                 => $data,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Dua kiriman kembar tiba nyaris bersamaan.
            return null;
        }
    }

    /**
     * Catat lampiran — TANPA mengunduhnya di sini.
     *
     * Mengunduh di dalam permintaan webhook berarti vendor menunggu selama
     * berkasnya turun; kalau lambat, ia menganggap kiriman gagal, mengulang,
     * lalu mematikan endpoint setelah gagal beruntun. Unduhan dikerjakan
     * `crm:unduh-lampiran` beberapa detik kemudian — masih jauh di dalam jendela
     * ~30 hari milik Meta.
     */
    private function simpanLampiran(CrmMessage $pesan, array $data): void
    {
        $url     = $data['media_url'] ?? data_get($data, 'media.url') ?? null;
        $mediaId = $data['media_id'] ?? data_get($data, 'media.id') ?? null;

        if (! $url && ! $mediaId) {
            return;
        }

        /*
         * `media_status` menentukan boleh-tidaknya `media_url` DIPERCAYA.
         *
         * Vendor menyatakannya sendiri: hanya "ok" yang berarti berkasnya
         * benar-benar berhasil mereka ambil dari Meta. "download_failed" dan
         * "unsupported" (mis. pesan sekali-lihat) tetap datang membawa alamat,
         * tapi alamat itu menjawab 404 — dan tanpa pemeriksaan ini lampirannya
         * duduk selamanya sebagai "belum terunduh" yang dicoba ulang tiap menit
         * oleh crm:unduh-lampiran, tanpa seorang pun tahu sebabnya.
         *
         * Barisnya tetap DIBUAT: pelanggan memang mengirim sesuatu, dan
         * gelembung yang diam-diam kosong membuat admin mengira tak ada apa-apa.
         * Yang berubah cuma alasannya, dan alasannya terbaca.
         */
        $statusMedia = (string) ($data['media_status'] ?? 'ok');
        $bisaDiambil = $statusMedia === 'ok';

        CrmAttachment::create([
            'message_id'        => $pesan->id,
            'provider_media_id' => $mediaId ? (string) $mediaId : null,
            'source_url'        => $bisaDiambil && $url ? (string) $url : null,
            'download_error'    => $bisaDiambil ? null : match ($statusMedia) {
                'download_failed' => 'Vendor gagal mengambil media ini dari Meta. Minta pelanggan mengirim ulang.',
                'unsupported'     => 'Media tidak bisa diunduh (mis. pesan sekali-lihat). Hanya bisa dilihat di HP.',
                default           => 'Media ditandai vendor sebagai "' . $statusMedia . '" — tidak bisa diambil.',
            },
            /*
             * Nama & mime aslinya duduk di dalam `raw.<jenis>` — DIVERIFIKASI atas
             * payload sungguhan: raw.document.filename = "MIMBAR DUDUK MASJID.cdr".
             * Tanpa alias ini berkasnya tersimpan TANPA EKSTENSI ('9', '3'), dan
             * Windows tak tahu harus membukanya dengan apa. Isinya utuh, tapi
             * praktis tak terpakai — dan gejalanya baru terasa berhari-hari
             * kemudian saat berkasnya dicari.
             */
            'original_name'     => $this->namaBerkas($data),
            'mime'              => $data['mime_type']
                ?? data_get($data, 'media.mime_type')
                ?? $this->dariRaw($data, 'mime_type'),
            'size_bytes'        => $data['file_size'] ?? data_get($data, 'media.size'),
        ]);
    }

    /**
     * Nama berkas kiriman pelanggan. Jatuh ke nama pada URL vendor bila payload
     * tak membawanya — lebih baik nama acak berekstensi benar daripada berkas
     * tanpa ekstensi yang tak bisa dibuka sama sekali.
     */
    private function namaBerkas(array $data): ?string
    {
        $nama = $data['file_name']
            ?? $data['filename']
            ?? data_get($data, 'media.filename')
            ?? $this->dariRaw($data, 'filename');

        if ($nama) {
            return (string) $nama;
        }

        $url = (string) ($data['media_url'] ?? data_get($data, 'media.url') ?? '');
        $sisa = basename(parse_url($url, PHP_URL_PATH) ?: '');

        return $sisa !== '' && str_contains($sisa, '.') ? $sisa : null;
    }

    /**
     * Ambil satu kunci dari `raw.<jenis>` — WhatsApp menaruh keterangan media di
     * bawah nama jenisnya (raw.document.*, raw.image.*, raw.video.*).
     */
    private function dariRaw(array $data, string $kunci): ?string
    {
        $jenis = (string) (data_get($data, 'raw.type') ?? $data['message_type'] ?? '');

        $nilai = $jenis !== ''
            ? data_get($data, "raw.{$jenis}.{$kunci}")
            : null;

        return $nilai ? (string) $nilai : null;
    }

    /** Nomor/username lawan bicara — bukan nomor bisnis kita. */
    private function kontak(array $data): string
    {
        // 'customer_phone' & 'raw.from' DIVERIFIKASI atas payload sungguhan
        // (message.received, 5 Sep 2026) — dokumentasi vendor menyebut
        // 'phone_number', kiriman aslinya tidak. Tanpa alias ini kontaknya
        // kosong dan percakapan lahir tanpa pemilik.
        return (string) ($data['customer_phone']
            ?? $data['phone_number']
            ?? $data['from']
            ?? data_get($data, 'raw.from')
            ?? data_get($data, 'customer.phone_number')
            ?? data_get($data, 'customer.phone')
            ?? '');
    }

    private function messageId(array $data): ?string
    {
        $id = $data['message_id'] ?? $data['id'] ?? null;

        return $id ? (string) $id : null;
    }

    private function isi(array $data): ?string
    {
        $isi = $data['content'] ?? $data['text'] ?? $data['body'] ?? $data['caption'] ?? null;
        $isi = is_string($isi) ? $isi : (is_array($isi) ? ($isi['body'] ?? null) : null);

        return $isi !== null && trim($isi) !== '' ? $isi : $this->judulTombol($data);
    }

    /**
     * Judul tombol yang ditekan pelanggan, untuk pesan `interactive`.
     *
     * Pesan hasil tekan tombol tidak selalu membawa `content` — yang pasti ada
     * cuma id & judul tombolnya di dalam raw. Tanpa cadangan ini, pancingan yang
     * BERHASIL justru meninggalkan gelembung kosong di thread: jendelanya
     * terbuka lagi, tapi tak ada yang bisa membaca bahwa pelanggan menekannya,
     * dan admin menyimpulkan pancingannya tidak bekerja.
     */
    private function judulTombol(array $data): ?string
    {
        $judul = data_get($data, 'raw.interactive.button_reply.title')
            ?? data_get($data, 'raw.interactive.list_reply.title')
            ?? data_get($data, 'raw.button.text')
            ?? data_get($data, 'interactive.button_reply.title');

        return is_string($judul) && trim($judul) !== '' ? $judul : null;
    }

    /**
     * `WHATSAPP_APP` = admin mengetik langsung dari HP (coexistence).
     * Inilah yang membuat "kebocoran balas dari HP" bisa ditegakkan kode —
     * dulu saya kira itu cuma soal disiplin.
     */
    private function source(array $data): string
    {
        return match (strtoupper((string) ($data['source'] ?? ''))) {
            'WHATSAPP_APP' => CrmMessage::SOURCE_WHATSAPP_APP,
            'ERP'          => CrmMessage::SOURCE_ERP,
            default        => CrmMessage::SOURCE_API,
        };
    }

    /**
     * Waktu menurut vendor; jatuh ke sekarang bila tak terbaca.
     *
     * ⚠️ WAJIB dikonversi ke zona waktu aplikasi sebelum disimpan. Vendor
     * mengirim ISO-8601 berakhiran 'Z' (UTC); tanpa konversi, jam UTC-nya
     * ditulis apa adanya ke kolom yang dibaca ERP sebagai Asia/Jakarta —
     * hasilnya seluruh jam di thread meleset 7 jam ke belakang, dan pesan
     * pagi tampil sebagai pesan tengah malam kemarin.
     */
    private function waktu(mixed $raw): Carbon
    {
        if (blank($raw)) {
            return now();
        }

        $zona = (string) config('app.timezone', 'UTC');

        try {
            $waktu = is_numeric($raw)
                ? Carbon::createFromTimestamp((int) $raw)
                : Carbon::parse((string) $raw);

            return $waktu->setTimezone($zona);
        } catch (\Throwable) {
            return now();
        }
    }
}
