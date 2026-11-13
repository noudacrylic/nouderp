<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

use App\Modules\CRM\Services\ChatPesananService;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderStockCheck;
use App\Modules\Tasks\MejaKerja\Contracts\JadiSkillManajer;
use App\Modules\Tasks\MejaKerja\Contracts\SkillAdmin;
use App\Modules\Tasks\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Desain gambar untuk OP pesanan yang urutan langkahnya memuat langkah Desain (cetak/tempel
 * stiker) — mis. OP custom buatan owner (produk baru / modifikasi). Tanpa centang tambahan:
 * langkah Desain itu sendiri penandanya. Begitu OP dikonfirmasi dan pesanannya dibayar (atau
 * tempo), kartu masuk meja Desain — Admin Desain mendesain gambar & minta persetujuan
 * pelanggan SAMBIL produksi berjalan. Cetak/tempelnya = langkah Desain di OP (LangkahDesainSkill).
 * Dipicu langsung saat OP diposting / langkahnya disimpan; sinkron berkala jadi cadangan.
 *
 * Tidak dobel dengan: SO bercentang "Desain gambar oleh Admin Desain" (Admin Desain sendiri yang
 * membuat OP — DesainGambarSkill) dan kartu Desain pesanan biasa (KS/BC, DesainSkill).
 *
 * Selesai: tombol Selesai (= gambar disetujui pelanggan), atau otomatis saat semua langkah
 * Desain OP sudah ditutup / OP difinalisasi. Batal bila OP batal atau langkah Desainnya dihapus.
 */
class DesainGambarOpSkill implements SkillAdmin
{
    use JadiSkillManajer;

    private const STATUS_JALAN = ['confirmed', 'in_progress', 'partial', 'pending', 'completed'];

    public function key(): string { return 'desain-gambar-op'; }

    public function label(): string { return 'Desain gambar OP custom'; }

    public function deskripsi(): string
    {
        return 'OP pesanan yang punya langkah Desain (cetak/tempel stiker) → kartu desain gambar di meja Desain begitu OP dikonfirmasi dan '
            . 'pesanan dibayar, dikerjakan bersamaan dengan produksi. Tandai Selesai saat gambar disetujui pelanggan.';
    }

    public function peranBawaan(): string { return 'desain'; }

    public function bobotBawaan(): int { return 8; }

    /** Desain + persetujuan pelanggan harus beres sebelum giliran cetak di OP. */
    public function slaMenitBawaan(): int { return 480; }

    public function tersedia(): bool { return Schema::hasColumn('production_order_steps', 'desain'); }

    public function butuhMulai(): bool { return true; }

    public function pindai(Carbon $sejak): iterable
    {
        $ops = ProductionOrder::with(['outputs.product:id,sku,name', 'salesOrder:id,order_number,customer_po_number,notes,desain_gambar'])
            ->whereNotNull('sales_order_id')
            ->whereHas('steps', fn ($q) => $q->whereNotNull('desain'))
            ->whereIn('status', self::STATUS_JALAN)
            ->orderBy('id')->get();
        if ($ops->isEmpty()) return;

        $bayar = app(SalesOrderStockCheck::class)->waktuAntre($ops->pluck('sales_order_id')->unique()->all());
        // Pesanan yang gambarnya sudah punya kartu sendiri (Desain pesanan KS/BC, SO bercentang).
        $sudahAdaKartu = Task::whereIn('skill', ['desain', 'desain-gambar'])->where('taskable_type', SalesOrder::class)
            ->whereIn('taskable_id', $ops->pluck('sales_order_id'))->pluck('taskable_id')->flip();

        foreach ($ops as $op) {
            $so = $op->salesOrder;
            if (!$so || !isset($bayar[$so->id]) || $so->desain_gambar || isset($sudahAdaKartu[$so->id])) continue;
            if ($this->desainSudahDitutup($op->id)) continue;

            $barang = $op->outputs->where('output_type', 'main')
                ->map(fn ($o) => ($o->qty_planned + 0) . '× ' . ($o->product?->name ?? $o->product?->sku))->implode(', ');
            $langkah = $this->langkahDesain($op->id)->pluck('name')->implode(', ');

            yield [
                'kunci'         => "desain-gambar-op:op:{$op->id}",
                'judul'         => "Desain gambar {$op->order_number} ({$so->order_number})",
                'deskripsi'     => trim("Barang: $barang"
                    . ($op->description ? "\nInstruksi OP: {$op->description}" : '')
                    . ($langkah ? "\nLangkah di OP: $langkah — gambar harus disetujui pelanggan sebelum gilirannya." : '')
                    . ($so->notes ? "\nCatatan pembeli: {$so->notes}" : '')),
                'taskable_type' => ProductionOrder::class,
                'taskable_id'   => $op->id,
                'meta'          => array_filter(['order_number' => $op->order_number, 'so_id' => $so->id, 'no_marketplace' => $so->customer_po_number]),
                'dibuat_at'     => Carbon::parse($bayar[$so->id])->max($op->created_at)->max($sejak),
            ];
        }
    }

    public function keadaan(Task $task): ?array
    {
        $op = DB::table('production_orders')->where('id', $task->taskable_id)->first(['status']);
        if (!$op || in_array($op->status, ['cancelled', 'merged'], true)) return ['cancelled', 'OP dibatalkan/digabung.'];
        if ($this->langkahDesain((int) $task->taskable_id)->isEmpty()) return ['cancelled', 'Langkah Desain dihapus dari OP.'];
        if ($op->status === 'finalized') return ['done', 'Otomatis: OP sudah difinalisasi.'];
        if ($this->desainSudahDitutup((int) $task->taskable_id)) return ['done', 'Otomatis: langkah Desain di OP sudah selesai.'];
        return null;
    }

    public function aksi(Task $task): array
    {
        $meta = $task->meta ?? [];
        $soId = (int) ($meta['so_id'] ?? 0);
        $chat = $soId ? (app(ChatPesananService::class)->untuk([$soId => (string) ($meta['no_marketplace'] ?? '')])[$soId] ?? []) : [];

        $aksi = [];
        if (!empty($chat['wa'])) {
            $aksi[] = ['label' => 'Chat WA', 'url' => route('crm.inbox.show', $chat['wa']), 'warna' => 'hijau', 'utama' => true];
        }
        if (!empty($chat['shopee'])) {
            $aksi[] = ['label' => 'Chat Shopee', 'url' => route('crm.inbox.show', $chat['shopee']), 'warna' => 'oren', 'utama' => !$aksi];
        }
        $aksi[] = ['label' => 'Lihat OP', 'url' => route('production.orders.show', $task->taskable_id), 'utama' => !$aksi];
        if ($soId) $aksi[] = ['label' => 'Lihat SO', 'url' => route('sales.orders.show', $soId)];

        return $aksi;
    }

    /** Selesai = gambar disetujui pelanggan (tak terlihat di dokumen ERP). */
    public function selesaiManual(): bool { return true; }

    /** OP punya langkah Desain dan semuanya sudah ditutup? (OP tanpa langkah Desain → false) */
    private function desainSudahDitutup(int $opId): bool
    {
        $langkah = $this->langkahDesain($opId);
        return $langkah->isNotEmpty() && $langkah->every(fn ($s) => $s->status === 'completed');
    }

    private function langkahDesain(int $opId)
    {
        return DB::table('production_order_steps')->where('production_order_id', $opId)->whereNotNull('desain')
            ->orderBy('step_number')->get(['name', 'status']);
    }
}
