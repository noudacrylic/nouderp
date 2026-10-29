<?php

namespace App\Modules\Marketplace\Shopee\Services;

use App\Models\User;
use App\Modules\CRM\Models\CrmAttachment;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmLabel;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Services\LabelPesananService;
use App\Modules\Marketplace\Shopee\Models\ShopeeChat;
use App\Modules\Marketplace\Shopee\Models\ShopeeItem;
use App\Modules\Marketplace\Shopee\Models\ShopeeSetting;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Sales\Models\SalesOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Chat Shopee → CRM, Tahap A (BACA SAJA): menyalin percakapan Shopee ke inbox CRM kanal
 * `shopee`. Tidak pernah mengirim apa pun ke Shopee.
 *
 * Aturan yang disepakati user (29 Okt 2026):
 *  - Percakapan yang seluruhnya dilayani AI Shopee DISEMBUNYIKAN (hanya dicatat di
 *    shopee_chats). Ia muncul begitu pembeli bicara ke penjual / AI mengalihkannya.
 *  - Penanda obrolan AI (`bundle_message`) tidak disalin; isinya memang tidak bisa diambil
 *    lewat API. Kartu ringkasan saat AI mengalihkan (`bot_summary_bundle_msg`) DISALIN.
 *  - Chat baru dipegang pemilik awal dari Pengaturan Shopee (Saiful), bukan "belum dioper".
 *  - Riwayat lama masuk sebagai arsip (Close): tanpa lencana belum dibaca.
 */
class ShopeeChatService
{
    private const UKURAN_HALAMAN = 60;

    /** Batas halaman pesan per percakapan sekali sinkron (60 × 40 = 2.400 pesan). */
    private const MAKS_HALAMAN_PESAN = 40;

    /** Chat yang aktivitas terakhirnya lebih tua dari ini masuk sebagai arsip walau ditemukan saat sinkron rutin. */
    private const HARI_AKTIF = 3;

    /** Nomor pesanan Shopee: 6 digit tanggal + 8 huruf/angka, mis. 261027PDYVRSV7. */
    private const POLA_ORDER_SN = '/\b(\d{6}[A-Z0-9]{8})\b/';

    /*
     * ⚠️ Carbon 3: createFromTimestamp() TANPA zona = UTC, bukan zona aplikasi. Kolom datetime
     * menyimpan jam dinding, jadi stempel Shopee wajib diberi config('app.timezone') — kalau
     * tidak semua jam chat mundur 7 jam (terjadi 29 Okt 2026).
     */
    public function __construct(private ShopeeClient $client, private LabelPesananService $label)
    {
    }

    /**
     * Sinkron rutin: percakapan yang berubah sejak penanda terakhir.
     *
     * @return array{diperiksa:int, tampil:int, tersembunyi:int, pesan:int}
     */
    public function tarikBaru(ShopeeShop $shop, int $maksHalaman = 20): array
    {
        $hasil  = ['diperiksa' => 0, 'tampil' => 0, 'tersembunyi' => 0, 'pesan' => 0];
        $batas  = $shop->chat_nano_terakhir;
        $teratas = null;
        $kursor = '';

        for ($hal = 0; $hal < $maksHalaman; $hal++) {
            $r = $this->daftarPercakapan($shop, $kursor);
            $berhenti = false;

            foreach ($r['conversations'] ?? [] as $cv) {
                $nano = (string) ($cv['last_message_timestamp'] ?? '0');
                $teratas ??= $nano;

                if ($batas !== null && $this->nanoLe($nano, $batas)) {
                    $berhenti = true;
                    break;
                }

                $this->hitung($hasil, $this->sinkronkanPercakapan($shop, $cv, hidup: true));
            }

            // Penanda kosong (pertama kali jalan): satu halaman teratas cukup — riwayat
            // ditarik terpisah lewat shopee:tarik-riwayat-chat.
            if ($berhenti || $batas === null || !($r['page_result']['more'] ?? false)) {
                break;
            }
            $kursor = (string) data_get($r, 'page_result.next_cursor.next_message_time_nano', '');
            if ($kursor === '') {
                break;
            }
        }

        if ($teratas !== null && ($batas === null || !$this->nanoLe($teratas, $batas))) {
            $shop->forceFill(['chat_nano_terakhir' => $teratas])->save();
        }

        return $hasil;
    }

    /**
     * Riwayat: mundur dari kursor tersimpan, sebanyak $maksHalaman halaman percakapan.
     * Kursor disimpan tiap halaman — bisa dihentikan & dilanjutkan kapan saja.
     *
     * @return array{diperiksa:int, tampil:int, tersembunyi:int, pesan:int, selesai:bool}
     */
    public function tarikRiwayat(ShopeeShop $shop, int $maksHalaman = 10): array
    {
        $hasil = ['diperiksa' => 0, 'tampil' => 0, 'tersembunyi' => 0, 'pesan' => 0, 'selesai' => false];

        for ($hal = 0; $hal < $maksHalaman; $hal++) {
            $r = $this->daftarPercakapan($shop, (string) $shop->chat_riwayat_kursor);

            foreach ($r['conversations'] ?? [] as $cv) {
                $this->hitung($hasil, $this->sinkronkanPercakapan($shop, $cv, hidup: false));
            }

            $kursor = (string) data_get($r, 'page_result.next_cursor.next_message_time_nano', '');
            if (!($r['page_result']['more'] ?? false) || $kursor === '') {
                $shop->forceFill(['chat_riwayat_kursor' => null, 'chat_riwayat_selesai_at' => now()])->save();
                $hasil['selesai'] = true;
                break;
            }
            $shop->forceFill(['chat_riwayat_kursor' => $kursor])->save();
        }

        return $hasil;
    }

    /**
     * Sinkronkan satu percakapan dari daftar Shopee.
     *
     * @return array{status:string, pesan:int} status: lewat|tersembunyi|tampil
     */
    public function sinkronkanPercakapan(ShopeeShop $shop, array $cv, bool $hidup): array
    {
        // Sinkron tiap menit dan penarikan riwayat bisa menyentuh percakapan yang sama bersamaan.
        return Cache::lock('shopee-chat:' . $shop->shop_id . ':' . $cv['conversation_id'], 120)
            ->block(60, fn () => $this->sinkronkanTerkunci($shop, $cv, $hidup));
    }

    private function sinkronkanTerkunci(ShopeeShop $shop, array $cv, bool $hidup): array
    {
        $chat = ShopeeChat::firstOrNew([
            'env' => $shop->env, 'shop_id' => $shop->shop_id, 'conversation_id' => (int) $cv['conversation_id'],
        ]);
        $terakhir = (string) ($cv['latest_message_id'] ?? '');

        if ($chat->exists && $chat->latest_message_id === $terakhir) {
            return ['status' => $chat->tampil ? 'tampil' : 'tersembunyi', 'pesan' => 0, 'lewat' => true];
        }

        $chat->fill([
            'to_id'           => (int) $cv['to_id'],
            'to_name'         => $cv['to_name'] ?? null,
            'last_message_at' => $this->waktuNano((string) ($cv['last_message_timestamp'] ?? '0')),
        ]);

        $pesan = $this->ambilPesan($shop, $chat);

        if (!$chat->tampil) {
            $chat->tampil = collect($pesan)->contains(fn ($m) => $this->terlihat($shop, $m));
        }

        $jumlah = 0;
        if ($chat->tampil) {
            DB::transaction(function () use ($shop, $chat, $pesan, $hidup, &$jumlah) {
                $crm = $this->percakapanCrm($shop, $chat, $pesan, $hidup);
                $chat->crm_conversation_id = $crm->id;
                $jumlah = $this->salinPesan($shop, $crm, $pesan, $hidup);
            });
        }

        $chat->latest_message_id = $terakhir;
        $chat->save();

        return ['status' => $chat->tampil ? 'tampil' : 'tersembunyi', 'pesan' => $jumlah];
    }

    /** Pemilik awal chat Shopee (Pengaturan › Integrasi › Shopee); null bila belum dipilih / nonaktif. */
    public function pemilikAwal(): ?int
    {
        $id = ShopeeSetting::singleton()->chat_owner_user_id;

        return $id && User::whereKey($id)->where('is_active', true)->exists() ? (int) $id : null;
    }

    /* ------------------------------------------------------------------ ambil */

    private function daftarPercakapan(ShopeeShop $shop, string $kursor): array
    {
        $q = ['direction' => 'older', 'type' => 'all', 'page_size' => self::UKURAN_HALAMAN];
        if ($kursor !== '') {
            $q['next_timestamp_nano'] = $kursor;
        }

        return $this->client->panggil($shop, 'GET', '/api/v2/sellerchat/get_conversation_list', $q);
    }

    /**
     * Pesan percakapan, TERLAMA dulu. Bila percakapan sudah ada di CRM, penarikan berhenti
     * di pesan pertama yang sudah tersalin (pesan datang terbaru dulu).
     */
    private function ambilPesan(ShopeeShop $shop, ShopeeChat $chat): array
    {
        $kumpulan = [];
        $offset   = '';

        for ($hal = 0; $hal < self::MAKS_HALAMAN_PESAN; $hal++) {
            $q = ['conversation_id' => $chat->conversation_id, 'page_size' => self::UKURAN_HALAMAN];
            if ($offset !== '') {
                $q['offset'] = $offset;
            }
            $r     = $this->client->panggil($shop, 'GET', '/api/v2/sellerchat/get_message', $q);
            $daftar = $r['messages'] ?? [];

            $sudahAda = $chat->crm_conversation_id && $daftar
                ? CrmMessage::whereIn('provider_message_id', array_map(fn ($m) => $this->idPesan($m), $daftar))
                    ->pluck('provider_message_id')->flip()
                : collect();

            foreach ($daftar as $m) {
                if ($sudahAda->has($this->idPesan($m))) {
                    return array_reverse($kumpulan);
                }
                $kumpulan[] = $m;
            }

            $offset = (string) data_get($r, 'page_result.next_offset', '');
            if (!$daftar || $offset === '' || $offset === '0') {
                break;
            }
        }

        return array_reverse($kumpulan);
    }

    /* ---------------------------------------------------------------- tulis CRM */

    private function percakapanCrm(ShopeeShop $shop, ShopeeChat $chat, array $pesan, bool $hidup): CrmConversation
    {
        if ($chat->crm_conversation_id && ($ada = CrmConversation::find($chat->crm_conversation_id))) {
            if ($chat->to_name && $ada->name_source !== CrmConversation::NAMA_MANUAL && $ada->display_name !== $chat->to_name) {
                $ada->forceFill(['display_name' => $chat->to_name])->save();
            }

            return $ada;
        }

        $pemilik  = $this->pemilikAwal();
        $terakhir = $chat->last_message_at ?? now();
        $aktif    = $hidup && $terakhir->gte(now()->subDays(self::HARI_AKTIF));

        return CrmConversation::findOrCreateFor((string) $chat->to_id, CrmConversation::KANAL_SHOPEE, (string) $shop->shop_id, [
            'display_name'         => $chat->to_name,
            'name_source'          => CrmConversation::NAMA_SHOPEE,
            'provider_customer_id' => (string) $chat->conversation_id,
            'owner_user_id'        => $pemilik,
            'queue_state'          => ($pemilik ? CrmLabel::dasarUntuk($pemilik) : null) ?? CrmConversation::QUEUE_KITA,
            'status'               => $aktif ? CrmConversation::STATUS_AKTIF : CrmConversation::STATUS_ARSIP,
            'closed_at'            => $aktif ? null : $terakhir,
            'unread_count'         => 0,
        ]);
    }

    /** Salin pesan (terlama dulu) lalu perbarui keadaan percakapan. Kembalikan jumlah pesan baru. */
    private function salinPesan(ShopeeShop $shop, CrmConversation $crm, array $pesan, bool $hidup): int
    {
        $baru = 0;
        $masukBaru = false;
        $waktuTerakhir = null;
        $masukTerakhir = null;
        $keluarTerakhir = null;

        foreach ($pesan as $m) {
            $u = $this->uraikan($shop, $m);
            if (!$u) {
                continue;
            }

            $waktu = Carbon::createFromTimestamp((int) ($m['created_timestamp'] ?? time()), config('app.timezone'));

            try {
                $baris = CrmMessage::create([
                    'conversation_id'     => $crm->id,
                    'direction'           => $u['arah'],
                    'message_type'        => $u['jenis'],
                    'content'             => $u['isi'],
                    'source'              => $u['sumber'],
                    'provider_message_id' => $this->idPesan($m),
                    'sent_at'             => $waktu,
                    'raw'                 => $m,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                continue;
            }

            $baru++;
            $waktuTerakhir = $waktu;

            if ($u['url']) {
                // Riwayat: alamatnya saja, diunduh saat dibuka (tombol "Unduh sekarang") —
                // menghemat disk server. Pesan baru diunduh penjadwal crm:unduh-lampiran.
                CrmAttachment::create([
                    'message_id'     => $baris->id,
                    'source_url'     => $u['url'],
                    'mime'           => $u['jenis'] === 'video' ? 'video/mp4' : 'image/jpeg',
                    'download_error' => $hidup ? null : 'Riwayat Shopee — unduh bila diperlukan.',
                ]);
            }

            if ($u['order_sn']) {
                $this->tautkanPesanan($crm, $u['order_sn']);
            }

            if ($u['arah'] === CrmMessage::MASUK) {
                $masukTerakhir = $waktu;
                $masukBaru = true;
            } elseif ($u['sumber'] !== CrmMessage::SOURCE_SHOPEE_OTOMATIS) {
                $keluarTerakhir = $waktu;
            }
        }

        if (!$baru) {
            return 0;
        }

        $ubah = [];
        if (!$crm->last_message_at || $waktuTerakhir->gt($crm->last_message_at)) {
            $ubah['last_message_at'] = $waktuTerakhir;
        }
        if ($masukTerakhir && (!$crm->last_inbound_at || $masukTerakhir->gt($crm->last_inbound_at))) {
            $ubah['last_inbound_at'] = $masukTerakhir;
        }
        if ($keluarTerakhir && (!$crm->last_outbound_at || $keluarTerakhir->gt($crm->last_outbound_at))) {
            $ubah['last_outbound_at'] = $keluarTerakhir;
        }

        if ($hidup) {
            // Belum dibaca = pesan pembeli sesudah balasan manusia terakhir (balasan otomatis
            // Shopee tidak dihitung menjawab). Dihitung ulang dari CRM: tetap benar walau satu
            // putaran sinkron terlewat.
            $ubah['unread_count'] = $this->hitungBelumDibaca($crm);

            if ($masukBaru && $ubah['unread_count'] > 0) {
                $ubah += $crm->perubahanSaatPesanMasuk();

                // Chat yang dibuka ulang sebagai pesanan baru (tanpa pemilik) kembali ke pemilik awal Shopee.
                if (array_key_exists('owner_user_id', $ubah) && $ubah['owner_user_id'] === null && ($pemilik = $this->pemilikAwal())) {
                    $ubah['owner_user_id'] = $pemilik;
                    $ubah['queue_state']   = CrmLabel::dasarUntuk($pemilik) ?? $ubah['queue_state'];
                }
            }
        }

        if ($ubah) {
            $crm->forceFill($ubah)->save();
        }

        return $baru;
    }

    private function hitungBelumDibaca(CrmConversation $crm): int
    {
        $balasanManusia = CrmMessage::where('conversation_id', $crm->id)
            ->where('direction', CrmMessage::KELUAR)
            ->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', CrmMessage::SOURCE_SHOPEE_OTOMATIS))
            ->max('sent_at');

        return CrmMessage::where('conversation_id', $crm->id)
            ->where('direction', CrmMessage::MASUK)
            ->when($balasanManusia, fn ($q) => $q->where('sent_at', '>', $balasanManusia))
            ->count();
    }

    /** Tautkan SO `SP-<sn>` ke chat — hanya bila SO itu belum tertaut chat lain. */
    private function tautkanPesanan(CrmConversation $crm, string $sn): void
    {
        $so = SalesOrder::where('customer_po_number', 'SP-' . $sn)
            ->orderByRaw("status = 'void'")->orderByDesc('id')->first();

        if ($so && !DB::table('crm_pesanan_chat')->where('sales_order_id', $so->id)->exists()) {
            $this->label->tautkan($crm, $so);
        }
    }

    /* ---------------------------------------------------------------- uraikan */

    /**
     * Pesan Shopee → bentuk CRM. Null = tidak disalin (penanda obrolan AI).
     *
     * @return array{arah:string, jenis:string, isi:?string, sumber:?string, url:?string, order_sn:?string}|null
     */
    public function uraikan(ShopeeShop $shop, array $m): ?array
    {
        $jenis = (string) ($m['message_type'] ?? '');
        if ($jenis === 'bundle_message') {
            return null;
        }

        $c       = (array) ($m['content'] ?? []);
        $dariToko = $this->dariToko($shop, $m);
        $u = [
            'arah'     => $dariToko ? CrmMessage::KELUAR : CrmMessage::MASUK,
            'jenis'    => 'text',
            'isi'      => null,
            'sumber'   => $dariToko ? $this->sumberToko($m) : null,
            'url'      => null,
            'order_sn' => null,
        ];

        switch ($jenis) {
            case 'text':
                $u['isi'] = (string) ($c['text'] ?? '');
                break;
            case 'image':
                $u['jenis'] = 'image';
                $u['url']   = $this->url($c['url'] ?? null);
                break;
            case 'image_with_text':
                $u['jenis'] = 'image';
                $u['isi']   = ($c['text'] ?? '') !== '' ? (string) $c['text'] : null;
                $u['url']   = $this->url($c['image_url'] ?? $c['url'] ?? null);
                break;
            case 'video':
                $u['jenis'] = 'video';
                $u['url']   = $this->url($c['video_url'] ?? null);
                break;
            case 'sticker':
                $u['isi'] = '[Stiker]';
                break;
            case 'order':
                $sn = (string) ($c['order_sn'] ?? '');
                $u['isi']      = '🧾 Pesanan Shopee ' . ($sn ?: '?');
                $u['order_sn'] = $sn !== '' ? $sn : null;
                break;
            case 'item':
                $u['isi'] = '🛍️ Produk: ' . $this->namaProduk($shop, (int) ($c['item_id'] ?? 0));
                break;
            case 'bot_summary_bundle_msg':
                $baris = array_filter([
                    '🤖 Dialihkan dari AI Shopee',
                    ($t = data_get($c, 'transfer_reason.text')) ? 'Alasan: ' . $t : null,
                    ($t = data_get($c, 'summary.text')) ? 'Ringkasan: ' . $t : null,
                    ($t = data_get($c, 'suggested_solution.text')) ? 'Saran: ' . $t : null,
                ]);
                $u['isi'] = implode("\n", $baris);
                break;
            case 'faq_liveagent':
                $u['isi'] = '🙋 Pembeli memilih: ' . ((string) ($c['text'] ?? '') ?: 'Chat dengan Penjual');
                break;
            case 'voucher':
                $u['isi'] = '🎟️ Voucher ' . ($c['voucher_code'] ?? '');
                break;
            case 'notification':
                $u['isi'] = 'ℹ️ ' . ($c['notification_for_receiver'] ?? $c['notification_for_sender'] ?? 'Notifikasi Shopee');
                break;
            default:
                $u['isi'] = '[' . ($jenis ?: 'pesan') . ']';
        }

        // Nomor pesanan yang diketik pembeli di teks.
        if (!$u['order_sn'] && !$dariToko && $u['isi'] && preg_match(self::POLA_ORDER_SN, $u['isi'], $cocok)) {
            $u['order_sn'] = $cocok[1];
        }

        return $u;
    }

    /** Pesan ini membuat percakapan layak tampil di CRM? (bukan obrolan AI, bukan pesan otomatis toko) */
    public function terlihat(ShopeeShop $shop, array $m): bool
    {
        if (($m['message_type'] ?? '') === 'bundle_message') {
            return false;
        }

        return !$this->dariToko($shop, $m) || ($m['source'] ?? '') !== 'server';
    }

    private function dariToko(ShopeeShop $shop, array $m): bool
    {
        return (int) ($m['from_shop_id'] ?? 0) === (int) $shop->shop_id;
    }

    /** server = otomatis Shopee (sambutan, luar jam, voucher); openapi = dikirim lewat API; sisanya admin di Seller Centre. */
    private function sumberToko(array $m): string
    {
        return match ((string) ($m['source'] ?? '')) {
            'server'  => CrmMessage::SOURCE_SHOPEE_OTOMATIS,
            'openapi' => CrmMessage::SOURCE_API,
            default   => CrmMessage::SOURCE_SELLER_CENTRE,
        };
    }

    private function namaProduk(ShopeeShop $shop, int $itemId): string
    {
        $nama = $itemId
            ? ShopeeItem::where('env', $shop->env)->where('shop_id', $shop->shop_id)->where('item_id', $itemId)->value('nama')
            : null;

        return $nama ?: 'Shopee #' . $itemId;
    }

    private function url(mixed $url): ?string
    {
        return is_string($url) && preg_match('#^https?://#', $url) ? $url : null;
    }

    private function idPesan(array $m): string
    {
        return 'shopee:' . $m['message_id'];
    }

    private function waktuNano(string $nano): ?Carbon
    {
        $detik = strlen($nano) > 10 ? (int) substr($nano, 0, -9) : (int) $nano;

        return $detik > 0 ? Carbon::createFromTimestamp($detik, config('app.timezone')) : null;
    }

    /** $a <= $b untuk angka nano berbentuk string (melebihi PHP_INT_MAX tidak, tapi aman dibanding sebagai string). */
    private function nanoLe(string $a, string $b): bool
    {
        return strlen($a) === strlen($b) ? strcmp($a, $b) <= 0 : strlen($a) < strlen($b);
    }

    private function hitung(array &$hasil, array $r): void
    {
        if (!empty($r['lewat'])) {
            return;
        }
        $hasil['diperiksa']++;
        $hasil[$r['status'] === 'tampil' ? 'tampil' : 'tersembunyi']++;
        $hasil['pesan'] += $r['pesan'];
    }
}
