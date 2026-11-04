<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Tasks\MejaKerja\Contracts\JadiSkillManajer;
use App\Modules\Tasks\MejaKerja\Contracts\SkillAdmin;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\Models\Task;
use Carbon\Carbon;

/**
 * PO yang barangnya belum difakturkan → kartu Pending "Selesaikan pesanan" di meja admin.
 * Tombolnya membuat & memposting faktur dari sisa PO. PO yang masih di tahap bayar kartu
 * Purchasing menunggu kartu itu selesai dulu; PO buatan langsung dari menu ikut terpantau.
 */
class SelesaikanPesananSkill implements SkillAdmin
{
    use JadiSkillManajer;

    public function key(): string { return 'selesaikan-pesanan'; }

    public function label(): string { return 'Selesaikan pesanan'; }

    public function deskripsi(): string
    {
        return 'PO yang belum difakturkan → kartu di Pekerjaan Tertunda. Saat barang sampai, tombol Selesaikan pesanan otomatis membuat faktur dan menambah stok. Kartu selesai juga bila fakturnya diinput manual.';
    }

    /** Skill baru: dilihat dulu di Bayangan sebelum kartunya sungguh dibuat. */
    public function modeBawaan(): string { return self::BAYANGAN; }

    public function peranBawaan(): string { return 'layanan'; }

    public function bobotBawaan(): int { return 5; }

    public function slaMenitBawaan(): int { return 4320; }

    public function tersedia(): bool { return true; }

    public function satuan(): string { return 'PO'; }

    public function pindai(Carbon $sejak): iterable
    {
        $masihBayar = Task::whereIn('skill', ['purchasing-ready', 'purchasing-nonstok', 'purchasing-laporan'])
            ->whereIn('status', ['open', 'in_progress', 'pending'])->whereNotNull('meta')->get()
            ->map(fn (Task $t) => (int) ($t->meta['po_id'] ?? 0))->filter()->flip();

        $pos = PurchaseOrder::with('supplier:id,name')->where('status', 'posted')
            ->whereHas('items', fn ($q) => $q->whereColumn('qty_invoiced', '<', 'qty'))->orderBy('id')->get();

        foreach ($pos as $po) {
            if (isset($masihBayar[$po->id])) continue;

            yield [
                'kunci'         => "selesaikan-po:{$po->id}",
                'judul'         => $po->po_number . ' — ' . ($po->supplier?->name ?? '-'),
                'taskable_type' => PurchaseOrder::class,
                'taskable_id'   => $po->id,
                'dibuat_at'     => $po->created_at,
                'meta'          => ['pending' => 'Menunggu barang datang'],
            ];
        }
    }

    /** Kartunya langsung Pending; tanpa tenggat karena kedatangan barang di luar kendali admin. */
    public function setelahDibuat(Task $task, array $kerja): void
    {
        $task->tenggat_at = null;
        $task->pending_alasan = 'Menunggu barang datang';
        app(MejaKerjaService::class)->ubahStatus($task, 'pending', null, 'Pending: menunggu barang datang');
    }

    public function keadaan(Task $task): ?array
    {
        $po = PurchaseOrder::with('items')->find($task->taskable_id);
        if (!$po || $po->status === 'cancelled') return ['cancelled', 'PO dibatalkan.'];
        if ($po->status === 'closed' || $po->items->every(fn ($i) => (float) $i->qty_invoiced >= (float) $i->qty)) {
            return ['done', 'Faktur barang dibuat.'];
        }

        return null;
    }

    public function aksi(Task $task): array
    {
        return [
            ['label' => 'Lihat PO', 'url' => route('purchasing.orders.show', $task->taskable_id)],
            ['label' => 'Faktur sebagian', 'url' => route('purchasing.invoices.create', ['po_id' => $task->taskable_id])],
        ];
    }

    public function selesaiManual(): bool { return false; }

    /** Pending-nya menunggu barang, jadi tombol Pending & Lanjutkan tidak relevan. */
    public function kendaliSendiri(): bool { return true; }

    public function formKartu(Task $task): array
    {
        return ['jenis' => 'selesaikan', 'url' => route('tasks.meja.selesaikan', $task->id)];
    }
}
