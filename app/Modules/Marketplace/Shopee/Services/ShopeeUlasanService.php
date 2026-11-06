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
                if ($balasan !== null) {
                    $baris->balasan    = $balasan;
                    $baris->dibalas_at = $baris->dibalas_at ?? $dibalasAt ?? now();
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
    public function balas(ShopeeShop $shop, ShopeeUlasan $u, string $teks, ?int $userId): void
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

        $u->forceFill(['balasan' => $teks, 'dibalas_at' => now(), 'dibalas_oleh' => $userId])->save();
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
