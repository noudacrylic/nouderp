<?php

namespace App\Modules\Marketplace\Shopee\Services;

use App\Modules\Assistant\Services\ClaudeClient;
use App\Modules\Marketplace\Shopee\Models\ShopeeReturn;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Models\ShopeeUlasan;
use App\Modules\Sales\Models\SalesOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Ulasan pembeli Shopee: tarik (product/get_comment) → shopee_ulasan, draf balasan & chat dari
 * AI untuk ulasan jelek, dan kirim balasan publik (product/reply_comment) dari kartu Meja Kerja.
 */
class ShopeeUlasanService
{
    /** Batas panjang balasan yang diterima Shopee. */
    public const MAKS_BALASAN = 500;

    private const HALAMAN_MAKS = 50;

    public function __construct(private ShopeeClient $client, private ClaudeClient $claude) {}

    /**
     * Ulasan terbaru dulu; berhenti saat melewati jendela $hari. Ulasan yang sudah tersimpan
     * diperbarui (pembeli bisa mengubah bintang sekali; balasan dari Seller Centre ikut terbaca).
     *
     * @return int jumlah ulasan disimpan
     */
    public function tarik(ShopeeShop $shop, int $hari = 60): int
    {
        $batas  = now()->subDays($hari)->getTimestamp();
        $waktu  = fn ($ts) => $ts ? Carbon::createFromTimestamp((int) $ts, config('app.timezone')) : null;
        $cursor = '';
        $n      = 0;

        for ($hal = 0; $hal < self::HALAMAN_MAKS; $hal++) {
            $r    = $this->client->panggil($shop, 'GET', '/api/v2/product/get_comment', ['cursor' => $cursor, 'page_size' => 100]);
            $list = $r['item_comment_list'] ?? [];
            $lewat = false;

            foreach ($list as $x) {
                if ((int) ($x['create_time'] ?? 0) < $batas) {
                    $lewat = true;
                    continue;
                }
                $baris = ShopeeUlasan::firstOrNew(['env' => $shop->env, 'shop_id' => $shop->shop_id, 'comment_id' => (int) $x['comment_id']]);
                [$balasan, $dibalasAt] = $this->balasanShopee($x['comment_reply'] ?? null);
                $baris->fill([
                    'order_sn'         => (string) ($x['order_sn'] ?? ''),
                    'item_id'          => $x['item_id'] ?? null,
                    'model_id'         => ($x['model_id'] ?? 0) ?: (($x['model_id_list'][0] ?? null) ?: null),
                    'buyer_username'   => $x['buyer_username'] ?? null,
                    'rating_star'      => (int) ($x['rating_star'] ?? 5),
                    'comment'          => trim((string) ($x['comment'] ?? '')) ?: null,
                    'media'            => $x['media'] ?? null,
                    'editable'         => $x['editable'] ?? null,
                    'hidden'           => (bool) ($x['hidden'] ?? false),
                    'dibuat_shopee_at' => $waktu($x['create_time'] ?? null),
                    'raw'              => $x,
                    'disinkron_at'     => now(),
                ]);
                // Balasan yang dikirim dari ERP tetap dipegang walau Shopee belum memantulkannya.
                if ($balasan !== null && $baris->balasan === null) {
                    $baris->balasan        = $balasan;
                    $baris->dibalas_at     = $dibalasAt ?? now();
                    $baris->sumber_balasan = 'shopee';
                }
                $baris->save();
                $n++;
            }

            $cursor = (string) ($r['next_cursor'] ?? '');
            if ($lewat || !($r['more'] ?? false) || $cursor === '') {
                break;
            }
        }

        return $n;
    }

    /** @return array{0: ?string, 1: ?Carbon} */
    private function balasanShopee($reply): array
    {
        if (is_string($reply) && trim($reply) !== '') {
            return [trim($reply), null];
        }
        if (is_array($reply) && trim((string) ($reply['reply'] ?? '')) !== '') {
            $ts = (int) ($reply['create_time'] ?? 0);

            return [trim($reply['reply']), $ts ? Carbon::createFromTimestamp($ts, config('app.timezone')) : null];
        }

        return [null, null];
    }

    /**
     * Kirim balasan publik ke Shopee. Melempar RuntimeException berisi pesan Shopee bila ditolak.
     */
    public function balas(ShopeeShop $shop, ShopeeUlasan $u, string $teks, ?int $userId, string $sumber = 'admin'): void
    {
        $teks = trim($teks);
        if ($teks === '') {
            throw new \RuntimeException('Balasan masih kosong.');
        }
        if (mb_strlen($teks) > self::MAKS_BALASAN) {
            throw new \RuntimeException('Balasan maksimal ' . self::MAKS_BALASAN . ' karakter (sekarang ' . mb_strlen($teks) . ').');
        }
        if ($u->balasan !== null) {
            throw new \RuntimeException('Ulasan ini sudah dibalas.');
        }

        $r = $this->client->panggil($shop, 'POST', '/api/v2/product/reply_comment', [
            'comment_list' => [['comment_id' => $u->comment_id, 'comment' => $teks]],
        ]);
        $hasil = collect($r['result_list'] ?? [])->firstWhere('comment_id', $u->comment_id) ?? ($r['result_list'][0] ?? []);
        if (!empty($hasil['fail_error']) || !empty($hasil['fail_message'])) {
            throw new \RuntimeException('Shopee menolak balasan: ' . ($hasil['fail_message'] ?? $hasil['fail_error']));
        }

        $u->forceFill(['balasan' => $teks, 'dibalas_at' => now(), 'dibalas_oleh' => $userId, 'sumber_balasan' => $sumber])->save();
    }

    /**
     * Draf balasan publik (+ draf chat bila pembeli masih bisa mengubah ulasannya) dari AI.
     * Gagal → draf_galat diisi, kartu tetap bisa dikerjakan dengan menulis sendiri.
     */
    public function buatDraf(ShopeeUlasan $u): void
    {
        $konteks = $this->konteks($u);
        $isi = [['type' => 'text', 'text' => $konteks]];
        foreach (array_slice($u->fotoUrl(), 0, 3) as $url) {
            $isi[] = ['type' => 'image', 'source' => ['type' => 'url', 'url' => $url]];
        }

        $payload = [
            'model'      => config('services.shopee.model_ulasan', 'claude-sonnet-5-5'),
            'max_tokens' => 1024,
            'system'     => $this->petunjuk($u->bisaDiubahPembeli()),
            'messages'   => [['role' => 'user', 'content' => $isi]],
        ];
        $resp = $this->claude->messages($payload);
        // Foto ulasan kadang tak bisa diunduh server Anthropic — ulangi dengan teks saja.
        if (isset($resp['_error']) && count($isi) > 1) {
            $payload['messages'][0]['content'] = [$isi[0]];
            $resp = $this->claude->messages($payload);
        }

        $teks = collect($resp['content'] ?? [])->where('type', 'text')->pluck('text')->implode('');
        $json = preg_match('/\{.*\}/s', $teks, $m) ? json_decode($m[0], true) : null;

        if (isset($resp['_error']) || !is_array($json) || trim((string) ($json['balasan'] ?? '')) === '') {
            $galat = $resp['_error'] ?? 'AI tidak mengembalikan draf yang terbaca.';
            Log::warning('[Shopee] draf balasan ulasan gagal', ['ulasan' => $u->id, 'galat' => $galat]);
            $u->forceFill(['draf_galat' => mb_substr($galat, 0, 255), 'draf_at' => now()])->save();

            return;
        }

        $u->forceFill([
            'draf_balasan' => mb_substr(trim($json['balasan']), 0, self::MAKS_BALASAN),
            'draf_chat'    => $u->bisaDiubahPembeli() ? (trim((string) ($json['chat'] ?? '')) ?: null) : null,
            'draf_at'      => now(),
            'draf_galat'   => null,
        ])->save();
    }

    /** Ulasan jelek belum dibalas & belum punya draf (gagal dicoba lagi setelah 6 jam). */
    public function calonDraf(int $batas = 10)
    {
        return ShopeeUlasan::perluDibalas()
            ->where(fn ($q) => $q->whereNull('draf_at')->orWhere(fn ($q) => $q->whereNotNull('draf_galat')->where('draf_at', '<', now()->subHours(6))))
            ->orderByDesc('dibuat_shopee_at')->limit($batas)->get();
    }

    /**
     * Balas ulasan bagus (⭐4–5) yang belum dibalas, dalam jendela config('ulasan_bagus.jendela_hari'):
     * tanpa tulisan → template; bertulisan → AI. Ulasan bertulisan yang menyelipkan keluhan tidak
     * dibalas di sini — ditandai `keluhan` lalu jadi kartu "Balas ulasan" di Meja Layanan.
     * $simulasi (mode Bayangan): balasan disusun & dicatat, tidak dikirim (penilaian AI tetap
     * disimpan supaya tidak diulang tiap jalan).
     *
     * @return array<int, array{ulasan_id: int, sn: string, bintang: int, status: string, sumber: ?string, teks: ?string, pesan: ?string}>
     */
    public function balasUlasanBagus(ShopeeShop $shop, bool $simulasi): array
    {
        $calon = ShopeeUlasan::bagusBelumDibalas()
            ->where('env', $shop->env)->where('shop_id', $shop->shop_id)
            ->where('dibuat_shopee_at', '>=', now()->subDays((int) config('ulasan_bagus.jendela_hari', 7)))
            // Bayangan: yang sudah tercatat "akan dibalas" tak perlu diulang tiap jalan.
            ->when($simulasi, fn ($q) => $q->whereNull('draf_balasan'))
            ->orderBy('dibuat_shopee_at')->limit((int) config('ulasan_bagus.batas_per_jalan', 30))->get();

        $hasil = [];
        foreach ($calon as $u) {
            $baris = ['ulasan_id' => (int) $u->id, 'sn' => $u->order_sn, 'bintang' => $u->rating_star, 'status' => 'akan', 'sumber' => null, 'teks' => null, 'pesan' => null];

            if ($u->comment === null) {
                [$baris['sumber'], $baris['teks']] = ['template', $this->template($u)];
            } else {
                if ($u->draf_balasan === null) {
                    // Gagal belum lama → jangan menembak AI tiap 15 menit; coba lagi setelah 6 jam.
                    if ($u->draf_galat && $u->draf_at?->gt(now()->subHours(6))) continue;
                    $this->nilaiUlasanBagus($u);
                }
                if ($u->keluhan) {
                    $hasil[] = ['status' => 'keluhan', 'pesan' => "“{$u->comment}”"] + $baris;
                    continue;
                }
                if ($u->draf_balasan === null) {
                    $hasil[] = ['status' => 'gagal', 'pesan' => 'Draf AI gagal: ' . ($u->draf_galat ?? '-')] + $baris;
                    continue;
                }
                [$baris['sumber'], $baris['teks']] = ['ai', $u->draf_balasan];
            }

            if ($simulasi) {
                // Teks template disimpan sebagai draf supaya Bayangan tidak mencatatnya ulang.
                $u->forceFill(['draf_balasan' => $baris['teks'], 'draf_at' => $u->draf_at ?? now()])->save();
                $hasil[] = $baris;
                continue;
            }

            try {
                $this->balas($shop, $u, $baris['teks'], null, $baris['sumber']);
                $baris['status'] = 'terkirim';
            } catch (\Throwable $e) {
                $baris['status'] = 'gagal';
                $baris['pesan']  = $e->getMessage();
            }
            $hasil[] = $baris;
        }

        return $hasil;
    }

    /** Template diputar per ulasan; {produk} hanya bila pesanannya satu jenis barang. */
    public function template(ShopeeUlasan $u): string
    {
        $so = SalesOrder::with('items.product')->where('customer_po_number', 'SP-' . $u->order_sn)->first();
        $nama = $so ? $so->items->map(fn ($i) => trim((string) ($i->description ?: $i->product?->name)))->filter()->unique()->values() : collect();
        $produk = $nama->count() === 1 ? $nama[0] : null;

        $daftar = collect(config('ulasan_bagus.template', []))
            ->filter(fn ($t) => $produk !== null || !str_contains($t, '{produk}'))->values();
        if ($daftar->isEmpty()) {
            return 'Terima kasih banyak atas ulasannya, Kak! 🙏';
        }

        return str_replace('{produk}', (string) $produk, $daftar[$u->comment_id % $daftar->count()]);
    }

    /** AI menilai ulasan bagus bertulisan: pujian → draf balasan; menyelipkan keluhan → keluhan = true. */
    public function nilaiUlasanBagus(ShopeeUlasan $u): void
    {
        $resp = $this->claude->messages([
            'model'      => config('services.shopee.model_ulasan', 'claude-sonnet-5-5'),
            'max_tokens' => 512,
            'system'     => <<<TXT
Kamu admin layanan toko akrilik Noud Acrylic di Shopee. Pembeli memberi ulasan bintang bagus.
1. Tentukan apakah ulasan menyelipkan KELUHAN yang perlu ditindaklanjuti (barang retak/pecah/kurang/rusak, salah kirim, bahan tipis, kemasan buruk, dsb.). Saran ringan atau pujian biasa bukan keluhan.
2. Bila bukan keluhan, tulis balasan publik: bahasa Indonesia hangat, sapa "Kak", 1–2 kalimat, maksimal 250 karakter, tanggapi hal spesifik yang dipuji, tanpa nomor HP/tautan/promo.
Jawab HANYA JSON: {"keluhan": true|false, "balasan": "..."} (balasan kosong bila keluhan).
TXT,
            'messages'   => [['role' => 'user', 'content' => "Bintang: {$u->rating_star} dari 5\nUlasan: \"{$u->comment}\"\n\n" . $this->konteks($u)]],
        ]);

        $teks = collect($resp['content'] ?? [])->where('type', 'text')->pluck('text')->implode('');
        $json = preg_match('/\{.*\}/s', $teks, $m) ? json_decode($m[0], true) : null;
        if (isset($resp['_error']) || !is_array($json) || !array_key_exists('keluhan', $json)) {
            $u->forceFill(['draf_galat' => mb_substr($resp['_error'] ?? 'AI tidak mengembalikan penilaian yang terbaca.', 0, 255), 'draf_at' => now()])->save();

            return;
        }

        if ($json['keluhan']) {
            // Draf permintaan maaf + chat dibuat jalur ulasan jelek (calonDraf) — draf_at dikosongkan.
            $u->forceFill(['keluhan' => true, 'draf_balasan' => null, 'draf_at' => null, 'draf_galat' => null])->save();

            return;
        }
        $balasan = trim((string) ($json['balasan'] ?? ''));
        $u->forceFill([
            'keluhan'      => false,
            'draf_balasan' => $balasan !== '' ? mb_substr($balasan, 0, self::MAKS_BALASAN) : null,
            'draf_at'      => now(),
            'draf_galat'   => $balasan !== '' ? null : 'AI mengembalikan balasan kosong.',
        ])->save();
    }

    private function konteks(ShopeeUlasan $u): string
    {
        $so = SalesOrder::with('items.product')->where('customer_po_number', 'SP-' . $u->order_sn)->first();
        $barang = $so ? $so->items->map(fn ($i) => '- ' . ($i->description ?: $i->product?->name) . ' × ' . (float) $i->qty)->implode("\n") : '(pesanan tidak ditemukan di ERP)';
        $retur = ShopeeReturn::where('order_sn', $u->order_sn)->get()
            ->map(fn ($r) => "- {$r->status}, alasan {$r->alasanLabel()}" . ($r->text_reason ? ": \"{$r->text_reason}\"" : ''))->implode("\n");

        return "Ulasan pembeli Shopee:\n"
            . "Bintang: {$u->rating_star} dari 5\n"
            . 'Isi ulasan: ' . ($u->comment ? "\"{$u->comment}\"" : '(pembeli hanya memberi bintang, tanpa tulisan)') . "\n"
            . 'Foto dari pembeli: ' . count($u->fotoUrl()) . "\n"
            . "Pesanan: SP-{$u->order_sn}" . ($so && $so->order_date ? ' (tanggal ' . Carbon::parse($so->order_date)->format('d/m/Y') . ')' : '') . "\nBarang:\n{$barang}\n"
            . ($retur ? "Pengajuan retur di Shopee untuk pesanan ini:\n{$retur}\n" : "Tidak ada pengajuan retur untuk pesanan ini.\n");
    }

    private function petunjuk(bool $denganChat): string
    {
        return <<<TXT
Kamu admin layanan toko akrilik Noud Acrylic di Shopee. Tulis balasan untuk ulasan pembeli yang memberi bintang rendah.

BALASAN PUBLIK (tampil di halaman produk, dibaca calon pembeli lain):
- Bahasa Indonesia santun dan hangat, sapa "Kak", 2–4 kalimat, maksimal 400 karakter.
- Minta maaf dengan tulus atas pengalaman yang kurang menyenangkan; sebut keluhannya secara spesifik bila ada.
- Tunjukkan tindak lanjut: ajak pembeli menghubungi lewat chat toko agar dibantu, atau sebutkan bahwa retur sedang diproses bila ada pengajuan retur.
- Jangan menyalahkan pembeli atau kurir, jangan berdebat, jangan menjanjikan uang, refund, atau ganti barang secara spesifik.
- Jangan cantumkan nomor HP, tautan, atau akun media sosial (dilarang Shopee).
- Bila pembeli hanya memberi bintang tanpa tulisan: minta maaf umum dan ajak cerita lewat chat apa yang kurang.

CHAT PRIBADI KE PEMBELI:
- Pesan pembuka singkat (2–3 kalimat) untuk menanyakan kendalanya dan menawarkan bantuan.
- JANGAN meminta pembeli mengubah bintang atau ulasan, dan jangan menawarkan imbalan apa pun untuk ulasan (melanggar kebijakan Shopee).

Jawab HANYA dengan JSON: {"balasan": "...", "chat": "..."}
TXT . ($denganChat ? '' : "\nPembeli sudah tidak bisa mengubah ulasannya — isi \"chat\" dengan string kosong.");
    }
}
