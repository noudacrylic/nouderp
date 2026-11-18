<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

use App\Modules\CRM\Services\ChatPesananService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderStockCheck;
use App\Modules\Tasks\MejaKerja\Contracts\JadiSkillManajer;
use App\Modules\Tasks\MejaKerja\Contracts\SkillOwner;
use App\Modules\Tasks\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pesanan custom (produk CS*: preorder + made_to_order) yang sudah DP/lunas/tempo tapi belum
 * punya OP → kartu di Meja Owner. Permintaan owner 18 Nov 2026: pembeli Gudang Pigura dikirimi
 * link CS3 lewat WA, membayar di Shopee 16 Nov 14:28 tanpa mengabari — OP baru dibuat 2 hari
 * kemudian. Produk custom tak punya BOM, jadi tak ada yang bergerak sendiri saat pesanan dibayar.
 *
 * Semua kanal (Shopee, WA, web). SO yang dicentang "Desain gambar oleh Admin Desain" dilewati —
 * itu kartu Admin Desain (DesainGambarSkill). Selesai otomatis saat semua barang custom SO masuk
 * OP, atau pesanan dikirim; batal bila SO void atau diserahkan ke Admin Desain.
 *
 * Pesanan Shopee hasil link produk custom yang dikirim dari chat WA: chat WA itu ditemukan lewat
 * pesan keluar berisi ID listing-nya, supaya rincian ukuran/bahan yang dibahas di WA langsung terbuka.
 */
class PesananCustomDibayarSkill implements SkillOwner
{
    use JadiSkillManajer;

    public const KUNCI_KV = 'owner.pesanan-custom-dibayar';

    /** Link produk custom dicari di chat WA sampai sekian hari sebelum pesanan dibuat. */
    public const HARI_CARI_LINK = 7;

    public function key(): string { return 'pesanan-custom-dibayar'; }

    public function label(): string { return 'Pesanan custom sudah dibayar'; }

    public function deskripsi(): string
    {
        return 'Pesanan berisi produk custom (CS*) yang sudah DP/lunas tapi belum dibuatkan OP → kartu di Meja Owner '
            . 'dengan tombol chat pembeli (termasuk chat WA tempat link produk custom dikirim) dan Buat OP. '
            . 'Selesai sendiri saat OP dibuat atau pesanan dikirim.';
    }

    /** Diminta owner langsung jalan; tetap berlabel percobaan (config). */
    public function modeBawaan(): string { return self::OTOMATIS; }

    public function peranBawaan(): string { return 'owner'; }

    public function bobotBawaan(): int { return 8; }

    /** Pembeli sudah membayar: OP sebaiknya dibuat di hari yang sama. */
    public function slaMenitBawaan(): int { return 240; }

    public function tersedia(): bool { return true; }

    public function satuan(): string { return 'pesanan'; }

    public function pindai(Carbon $sejak): iterable
    {
        $sos = SalesOrder::where('status', 'confirmed')
            ->where(fn ($q) => $q->whereNull('desain_gambar')->orWhere('desain_gambar', false))
            ->whereHas('items.product', fn ($q) => $q->where('sale_type', 'preorder')->where('made_to_order', true))
            ->with(['items.product:id,sku,name,sale_type,made_to_order', 'customer:id,name'])
            ->orderBy('id')->get();
        if ($sos->isEmpty()) return;

        $bayar = app(SalesOrderStockCheck::class)->waktuAntre($sos->pluck('id')->all());

        foreach ($sos as $so) {
            if (!isset($bayar[$so->id])) continue;              // belum DP & bukan tempo
            if ($this->sudahDikirim($so->id) || $this->semuaMasukOp($so->id)) continue;

            $barang = $so->items->filter(fn ($i) => $i->product?->isMadeToOrder())
                ->map(fn ($i) => ($i->qty + 0) . '× ' . $i->product->sku . ($i->description && $i->description !== $i->product->name ? " ({$i->description})" : ''))
                ->implode(', ');
            $asal  = $this->chatAsal($so);
            $waktu = Carbon::parse($bayar[$so->id]);

            yield [
                'kunci'         => "pesanan-custom:so:{$so->id}",
                'judul'         => "Pesanan custom dibayar: {$so->order_number}" . ($so->customer ? " — {$so->customer->name}" : ''),
                'deskripsi'     => trim("Barang: {$barang}\nDibayar: " . $waktu->translatedFormat('j M H:i')
                    . ($asal ? "\nLink produk custom dikirim lewat chat WA {$asal['nama']} (" . Carbon::parse($asal['at'])->translatedFormat('j M H:i') . ')' : '')
                    . ($so->notes ? "\nCatatan pembeli: {$so->notes}" : '')),
                'taskable_type' => SalesOrder::class,
                'taskable_id'   => $so->id,
                'meta'          => array_filter([
                    'order_number'   => $so->order_number,
                    'no_marketplace' => $so->customer_po_number,
                    'chat_asal'      => $asal['id'] ?? null,
                ]),
                'dibuat_at'     => $waktu->max($sejak),
            ];
        }
    }

    public function keadaan(Task $task): ?array
    {
        $so = DB::table('sales_orders')->where('id', $task->taskable_id)->first(['status', 'desain_gambar']);
        if (!$so || in_array($so->status, ['void', 'cancelled'], true)) return ['cancelled', 'Pesanan dibatalkan.'];
        if ($so->desain_gambar) return ['cancelled', 'Diserahkan ke Admin Desain (centang desain gambar).'];
        if ($this->semuaMasukOp((int) $task->taskable_id)) return ['done', 'Otomatis: OP pesanan custom sudah dibuat.'];
        if ($this->sudahDikirim((int) $task->taskable_id)) return ['done', 'Otomatis: pesanan sudah dikirim.'];
        return null;
    }

    public function aksi(Task $task): array
    {
        $id   = (int) $task->taskable_id;
        $meta = $task->meta ?? [];
        $chat = app(ChatPesananService::class)->untuk([$id => (string) ($meta['no_marketplace'] ?? '')])[$id] ?? [];

        $aksi = [['label' => 'Buat OP', 'url' => route('production.orders.create', ['type' => 'custom', 'so' => $id]), 'utama' => true]];
        $wa = $chat['wa'] ?? ($meta['chat_asal'] ?? null);
        if ($wa) {
            $aksi[] = ['label' => 'Chat WA', 'url' => route('crm.inbox.show', $wa), 'warna' => 'hijau'];
        }
        if (!empty($chat['shopee'])) {
            $aksi[] = ['label' => 'Chat Shopee', 'url' => route('crm.inbox.show', $chat['shopee']), 'warna' => 'oren'];
        }
        $aksi[] = ['label' => 'Lihat SO', 'url' => route('sales.orders.show', $id)];

        return $aksi;
    }

    /** Pesanan yang memang tak perlu OP (mis. dikerjakan di luar) ditutup dengan tombol. */
    public function selesaiManual(): bool { return true; }

    /**
     * Chat WA yang dikirimi link listing Shopee barang custom pesanan ini, paling dekat sebelum
     * pesanan dibuat. Hanya untuk pesanan Shopee; pesanan WA/web sudah tertaut chatnya sendiri.
     *
     * @return array{id: int, nama: string, at: string}|null
     */
    public function chatAsal(SalesOrder $so): ?array
    {
        $sn = str_starts_with((string) $so->customer_po_number, 'SP-') ? substr($so->customer_po_number, 3) : null;
        if (!$sn) return null;

        $raw = json_decode((string) DB::table('shopee_orders')->where('order_sn', $sn)->value('raw'), true);
        $itemIds = collect($raw['item_list'] ?? [])->pluck('item_id')->filter()->map(fn ($v) => (string) $v)->unique();
        if ($itemIds->isEmpty()) return null;

        $dibuat = Carbon::parse($so->created_at);
        $pesan = DB::table('crm_messages as m')->join('crm_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('c.channel', 'like', 'whatsapp%')
            ->where('m.direction', 'keluar')
            ->whereBetween('m.sent_at', [$dibuat->copy()->subDays(self::HARI_CARI_LINK), $dibuat])
            ->where(function ($q) use ($itemIds) {
                foreach ($itemIds as $item) {
                    $q->orWhere('m.content', 'like', "%/{$item}%");
                }
            })
            ->orderByDesc('m.sent_at')
            ->first(['c.id', 'c.display_name', 'c.contact_key', 'm.sent_at']);

        return $pesan ? ['id' => (int) $pesan->id, 'nama' => $pesan->display_name ?: $pesan->contact_key, 'at' => (string) $pesan->sent_at] : null;
    }

    /** Semua barang custom SO sudah direncanakan di OP yang tidak batal? */
    private function semuaMasukOp(int $soId): bool
    {
        $pesan = DB::table('sales_order_items as i')->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('i.sales_order_id', $soId)->where('p.sale_type', 'preorder')->where('p.made_to_order', true)
            ->groupBy('i.product_id')->selectRaw('i.product_id, SUM(i.qty) q')->pluck('q', 'product_id');
        if ($pesan->isEmpty()) return false;

        $op = DB::table('production_order_outputs as x')->join('production_orders as o', 'o.id', '=', 'x.production_order_id')
            ->where('o.sales_order_id', $soId)->whereNotIn('o.status', ['cancelled', 'merged'])
            ->whereIn('x.product_id', $pesan->keys())
            ->groupBy('x.product_id')->selectRaw('x.product_id, SUM(x.qty_planned) q')->pluck('q', 'product_id');

        // OP custom sering memakai produk keluaran lain (bukan CS*) — cukup ada OP untuk SO ini.
        if ($op->isEmpty()) {
            return DB::table('production_orders')->where('sales_order_id', $soId)
                ->whereNotIn('status', ['cancelled', 'merged'])->exists();
        }

        return $pesan->every(fn ($q, $pid) => (float) ($op[$pid] ?? 0) + 0.0001 >= (float) $q);
    }

    private function sudahDikirim(int $soId): bool
    {
        return DB::table('sales_deliveries')->where('sales_order_id', $soId)->where('status', 'posted')->exists();
    }
}
