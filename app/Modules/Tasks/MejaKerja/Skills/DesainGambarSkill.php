<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

use App\Modules\CRM\Services\ChatPesananService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderStockCheck;
use App\Modules\Tasks\MejaKerja\Contracts\JadiSkillManajer;
use App\Modules\Tasks\MejaKerja\Contracts\SkillAdmin;
use App\Modules\Tasks\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Desain gambar oleh Admin Desain: SO yang dicentang owner (form SO, panel Pesanan CRM, atau
 * halaman SO) → kartu di meja Desain begitu pembeli DP/lunas atau SO ditandai tempo. Admin
 * Desain berbicara langsung dengan pelanggan sampai desain disetujui, lalu membuat OP lewat
 * tombol di kartu (template langkah akrilik sign). Kartu Cetak stiker menyusul sendiri dari OP.
 *
 * Selesai otomatis saat SEMUA barang pesanan-khusus SO sudah masuk OP (SO campuran bisa butuh
 * dua OP — kartu tidak boleh tutup setelah OP pertama), atau saat pesanan dikirim. SO tanpa
 * barang preorder (tak butuh OP) ditutup dengan tombol Selesai. Batal bila SO di-void atau
 * centangnya dilepas.
 */
class DesainGambarSkill implements SkillAdmin
{
    use JadiSkillManajer;

    public function key(): string { return 'desain-gambar'; }

    public function label(): string { return 'Desain gambar (diserahkan owner)'; }

    public function deskripsi(): string
    {
        return 'SO yang dicentang "Desain gambar oleh Admin Desain" dan sudah dibayar (atau tempo) → kartu berisi brief, '
            . 'tombol chat pelanggan, dan tombol Buat OP. Selesai sendiri saat semua barang pesanan sudah masuk OP.';
    }

    public function peranBawaan(): string { return 'desain'; }

    public function bobotBawaan(): int { return 8; }

    /** Desain pesanan besar + persetujuan pelanggan: satu hari kerja. */
    public function slaMenitBawaan(): int { return 480; }

    public function tersedia(): bool { return Schema::hasColumn('sales_orders', 'desain_gambar'); }

    /** Mendesain punya masa kerja nyata → kartu ber-Mulai, waktunya terukur. */
    public function butuhMulai(): bool { return true; }

    public function pindai(Carbon $sejak): iterable
    {
        // Centang = perintah eksplisit owner, jadi tidak dibatasi $sejak: SO lama yang baru
        // dicentang hari ini tetap harus sampai ke meja Desain.
        $sos = SalesOrder::where('desain_gambar', true)->where('status', 'confirmed')
            ->with(['items.product:id,sku,name,sale_type'])
            ->orderBy('id')->get();
        if ($sos->isEmpty()) return;

        $bayar = app(SalesOrderStockCheck::class)->waktuAntre($sos->pluck('id')->all());

        foreach ($sos as $so) {
            if (!isset($bayar[$so->id])) continue;              // belum DP & bukan tempo
            if ($this->sudahDikirim($so->id) || $this->semuaMasukOp($so->id)) continue;

            $barang = $so->items->map(fn ($i) => ($i->qty + 0) . '× ' . ($i->description ?: $i->product?->name))->implode(', ');

            yield [
                'kunci'         => "desain-gambar:so:{$so->id}",
                'judul'         => "Desain gambar {$so->order_number}",
                'deskripsi'     => trim("Barang: $barang" . ($so->desain_brief ? "\nBrief: {$so->desain_brief}" : '')
                    . ($so->notes ? "\nCatatan pembeli: {$so->notes}" : '')),
                'taskable_type' => SalesOrder::class,
                'taskable_id'   => $so->id,
                'meta'          => array_filter(['order_number' => $so->order_number, 'no_marketplace' => $so->customer_po_number]),
                'dibuat_at'     => Carbon::parse($bayar[$so->id])->max($sejak),
            ];
        }
    }

    public function keadaan(Task $task): ?array
    {
        $so = DB::table('sales_orders')->where('id', $task->taskable_id)->first(['status', 'desain_gambar']);
        if (!$so || in_array($so->status, ['void', 'cancelled'], true)) return ['cancelled', 'Pesanan dibatalkan.'];
        if (!$so->desain_gambar) return ['cancelled', 'Centang desain gambar dilepas dari SO.'];
        if ($this->semuaMasukOp((int) $task->taskable_id)) return ['done', 'Otomatis: semua barang pesanan sudah masuk OP.'];
        if ($this->sudahDikirim((int) $task->taskable_id)) return ['done', 'Otomatis: pesanan sudah dikirim.'];
        return null;
    }

    /**
     * Pelanggan dan Admin Desain berbicara langsung → chat jadi tombol utama. Buat OP membuka
     * form OP custom yang sudah terisi SO-nya.
     */
    public function aksi(Task $task): array
    {
        $id = (int) $task->taskable_id;
        $no = (string) (($task->meta ?? [])['no_marketplace'] ?? '');
        $chat = app(ChatPesananService::class)->untuk([$id => $no])[$id] ?? [];

        $aksi = [];
        if (!empty($chat['wa'])) {
            $aksi[] = ['label' => 'Chat WA', 'url' => route('crm.inbox.show', $chat['wa']), 'warna' => 'hijau', 'utama' => true];
        }
        if (!empty($chat['shopee'])) {
            $aksi[] = ['label' => 'Chat Shopee', 'url' => route('crm.inbox.show', $chat['shopee']), 'warna' => 'oren', 'utama' => !$aksi];
        }
        $aksi[] = ['label' => 'Buat OP', 'url' => route('production.orders.create', ['type' => 'custom', 'so' => $id]), 'utama' => !$aksi];
        $aksi[] = ['label' => 'Lihat SO', 'url' => route('sales.orders.show', $id)];

        return $aksi;
    }

    public function selesaiManual(): bool { return true; }

    /**
     * Semua barang preorder SO sudah direncanakan di OP yang tidak batal? SO tanpa barang
     * preorder → false (tak ada OP yang ditunggu; ditutup manual atau saat dikirim).
     */
    private function semuaMasukOp(int $soId): bool
    {
        $pesan = DB::table('sales_order_items as i')->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('i.sales_order_id', $soId)->where('p.sale_type', 'preorder')
            ->groupBy('i.product_id')->selectRaw('i.product_id, SUM(i.qty) q')->pluck('q', 'product_id');
        if ($pesan->isEmpty()) return false;

        $op = DB::table('production_order_outputs as x')->join('production_orders as o', 'o.id', '=', 'x.production_order_id')
            ->where('o.sales_order_id', $soId)->whereNotIn('o.status', ['cancelled', 'merged'])
            ->whereIn('x.product_id', $pesan->keys())
            ->groupBy('x.product_id')->selectRaw('x.product_id, SUM(x.qty_planned) q')->pluck('q', 'product_id');

        return $pesan->every(fn ($q, $pid) => (float) ($op[$pid] ?? 0) + 0.0001 >= (float) $q);
    }

    private function sudahDikirim(int $soId): bool
    {
        return DB::table('sales_deliveries')->where('sales_order_id', $soId)->where('status', 'posted')->exists();
    }
}
