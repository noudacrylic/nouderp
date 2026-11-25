<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

use App\Models\User;
use App\Modules\Galeri\Services\FotoProdukService;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Services\ProductionOrderService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Tasks\MejaKerja\Contracts\JadiSkillManajer;
use App\Modules\Tasks\MejaKerja\Contracts\PunyaJawaban;
use App\Modules\Tasks\MejaKerja\Contracts\SkillAdmin;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Langkah Desain di OP (cetak & tempel stiker, atau cetak saja lalu ditempel Assembling): begitu
 * langkah sebelumnya selesai, giliran Admin Desain → kartu di meja Desain. Tanpa timer — satu
 * tombol menutup langkah OP-nya, lalu langkah berikutnya (mis. CNC kedua) boleh jalan.
 *
 * Kartu bertanda ⚠️ selama gambarnya belum disetujui pelanggan (kartu Desain gambar/Desain
 * pesanan OP/SO itu masih terbuka) — tetap boleh dikerjakan, owner/admin yang menimbang.
 *
 * Kunci memuat waktu selesai langkah sebelumnya: bila produksi dikembalikan ke langkah Desain
 * lalu maju lagi, kartunya muncul lagi.
 */
class LangkahDesainSkill implements SkillAdmin, PunyaJawaban
{
    use JadiSkillManajer, TahapFoto;

    private const PERINGATAN = '⚠️ ';

    public function key(): string { return 'langkah-desain'; }

    public function label(): string { return 'Cetak & tempel stiker (langkah OP)'; }

    public function deskripsi(): string
    {
        return 'OP yang punya langkah Desain → kartu di meja Desain saat gilirannya tiba (langkah sebelumnya selesai). Tanpa '
            . 'timer: tombol "Selesai" menutup langkah OP dan meneruskan produksi ke langkah berikutnya.';
    }

    public function peranBawaan(): string { return 'desain'; }

    public function bobotBawaan(): int { return 9; }

    /** Produksi menunggu langkah ini. */
    public function slaMenitBawaan(): int { return 240; }

    public function tersedia(): bool { return Schema::hasColumn('production_order_steps', 'desain'); }

    public function pindai(Carbon $sejak): iterable
    {
        $steps = ProductionOrderStep::with(['productionOrder.outputs.product:id,sku,name', 'productionOrder.salesOrder:id,order_number', 'productionOrder.steps'])
            ->whereNotNull('desain')->where('status', 'pending')
            ->whereHas('productionOrder', fn ($q) => $q->whereIn('status', ['confirmed', 'in_progress', 'partial']))
            ->get();

        foreach ($steps as $step) {
            $op = $step->productionOrder;
            $prev = $op->steps->firstWhere('step_number', $step->step_number - 1);
            if ($prev && $prev->status !== 'completed') continue;

            $siap = $prev?->completed_at ?? $op->created_at;
            $hasil = $op->outputs->where('output_type', 'main')
                ->map(fn ($o) => ($o->qty_planned + 0) . '× ' . ($o->product?->name ?? $o->product?->sku))->implode(', ');
            $next = $op->steps->firstWhere('step_number', $step->step_number + 1);
            $belumDisetujui = $this->gambarBelumDisetujui($op);

            yield [
                'kunci'         => "langkah-desain:step:{$step->id}:" . $siap->timestamp,
                'judul'         => ($belumDisetujui ? self::PERINGATAN : '') . $this->judul($step, $op),
                'deskripsi'     => trim("Hasil: $hasil."
                    . ($step->desain === 'cetak' ? "\nCetak saja — stiker ditempel tim Assembling di langkah berikutnya." : "\nCetak lalu tempel stikernya sendiri.")
                    . ($next ? "\nSesudah ini: langkah {$next->step_number} {$next->name} (menunggu langkah ini)." : "\nIni langkah terakhir — OP lanjut ke finalisasi.")
                    . ($belumDisetujui ? "\n⚠️ Gambar belum ditandai disetujui pelanggan." : '')
                    . ($op->description ? "\nInstruksi OP: {$op->description}" : '')),
                'taskable_type' => ProductionOrder::class,
                'taskable_id'   => $op->id,
                'meta'          => ['order_number' => $op->order_number, 'step_id' => $step->id],
                'dibuat_at'     => $siap->max($sejak),
            ];
        }
    }

    public function keadaan(Task $task): ?array
    {
        $step = ProductionOrderStep::with('productionOrder')->find((int) (($task->meta ?? [])['step_id'] ?? 0));
        if (!$step || !$step->productionOrder || in_array($step->productionOrder->status, ['cancelled', 'merged'], true)) {
            return ['cancelled', 'OP dibatalkan/digabung atau langkahnya dihapus.'];
        }
        // Tahap foto: langkah OP sudah ditutup, kartu menunggu foto produknya.
        if ($this->tahap($task) === 'foto') {
            $soId = $step->productionOrder->sales_order_id;
            if ($soId && DB::table('sales_deliveries')->where('sales_order_id', $soId)->where('status', 'posted')->exists()) {
                return ['done', 'Otomatis: pesanan dikirim sebelum difoto.'];
            }
            return null;
        }
        if ($step->status === 'completed') {
            // Ditutup dari halaman OP: barang tempel sendiri yang wajib difoto tetap masuk tahap foto.
            $soId = $step->productionOrder->sales_order_id;
            $foto = app(FotoProdukService::class);
            if ($step->desain === 'tempel' && $soId && $foto->wajibFoto($soId) && $foto->kekuranganFoto($soId)) {
                $this->keTahap($task, 'foto');
                return null;
            }
            return ['done', 'Langkah Desain di OP sudah ditutup.'];
        }
        if (!$step->isDesain()) return ['cancelled', 'Langkah bukan lagi langkah Desain.'];

        // Tanda ⚠️ dilepas begitu gambar disetujui (kartu desain gambarnya ditutup).
        if (str_starts_with($task->title, self::PERINGATAN) && !$this->gambarBelumDisetujui($step->productionOrder)) {
            $task->title = substr($task->title, strlen(self::PERINGATAN));
            $task->save();
        }
        return null;
    }

    public function aksi(Task $task): array
    {
        $op = ['label' => 'Lihat OP', 'url' => route('production.orders.show', $task->taskable_id)];
        $soId = $this->soId($task);

        return $this->tahap($task) === 'foto' && $soId ? [$this->aksiFoto($soId), $op] : [$op];
    }

    public function soUntukFoto(Task $task): ?int
    {
        return $this->soId($task);
    }

    private function soId(Task $task): ?int
    {
        $id = DB::table('production_orders')->where('id', $task->taskable_id)->value('sales_order_id');
        return $id ? (int) $id : null;
    }

    /** Ditutup lewat jawaban — yang juga menutup langkah OP-nya. */
    public function selesaiManual(): bool { return false; }

    public function jawaban(Task $task): array
    {
        if ($this->tahap($task) === 'foto') return $this->jawabanFoto();

        $step = ProductionOrderStep::find((int) (($task->meta ?? [])['step_id'] ?? 0));
        if (!$step || $step->status === 'completed') return [];

        return [['kunci' => 'selesai', 'label' => $step->desain === 'cetak' ? '✓ Cetak selesai' : '✓ Cetak & tempel selesai', 'utama' => true]];
    }

    public function jawab(Task $task, string $kunci, User $u): string
    {
        $soId = $this->soId($task);
        if ($this->tahap($task) === 'foto') {
            return $this->jawabFoto($task, $kunci, $u, (int) $soId) ?? throw new \RuntimeException('Jawaban tidak dikenal.');
        }

        $stepId = (int) (($task->meta ?? [])['step_id'] ?? 0);
        $step = ProductionOrderStep::find($stepId);
        try {
            $terakhir = app(ProductionOrderService::class)->selesaikanLangkahDesain($stepId, "Ditutup {$u->name} dari Meja Kerja");
        } catch (\Exception $e) {
            throw new \RuntimeException($e->getMessage());
        }

        $no = ($task->meta ?? [])['order_number'] ?? 'OP';
        $lanjut = 'produksi lanjut ke langkah berikutnya';
        if ($terakhir) {
            // Langkah terakhir → langsung masuk stok, tanpa Konfirmasi Output owner.
            $gagal = app(ProductionOrderService::class)->finalisasiOtomatis((int) $step?->production_order_id);
            $lanjut = $gagal === null ? 'OP selesai & hasil masuk stok' : "OP menunggu finalisasi: $gagal";
        }

        // Tempel sendiri → barang sudah berstiker, langsung difoto. Cetak saja → ditempel Assembling,
        // fotonya lewat kartu Foto custom sesudah OP selesai.
        if ($step?->desain === 'tempel'
            && $this->lanjutKeFotoAtauSelesai($task, $u, $soId, 'Langkah Desain OP ditutup')) {
            return "$no: langkah Desain selesai ($lanjut) — tinggal foto produknya.";
        }
        if ($step?->desain !== 'tempel') {
            app(MejaKerjaService::class)->ubahStatus($task, 'done', $u, 'Langkah Desain OP ditutup');
        }

        return "$no: langkah Desain selesai — $lanjut.";
    }

    private function judul(ProductionOrderStep $step, ProductionOrder $op): string
    {
        $utama = $op->outputs->firstWhere('output_type', 'main')?->product?->sku;
        return ($step->desain === 'cetak' ? 'Cetak stiker ' : 'Cetak & tempel stiker ') . $op->order_number . ($utama ? " — $utama" : '');
    }

    /**
     * Kartu desain gambar OP ini (atau Desain pesanan / Desain gambar SO-nya) masih terbuka?
     */
    private function gambarBelumDisetujui(ProductionOrder $op): bool
    {
        $buka = ['open', 'in_progress', 'pending'];
        $q = Task::whereIn('status', $buka)->where(function ($w) use ($op) {
            $w->where(fn ($x) => $x->where('skill', 'desain-gambar-op')->where('taskable_type', ProductionOrder::class)->where('taskable_id', $op->id));
            if ($op->sales_order_id) {
                $w->orWhere(fn ($x) => $x->whereIn('skill', ['desain', 'desain-gambar'])->where('taskable_type', SalesOrder::class)
                    ->where('taskable_id', $op->sales_order_id));
            }
        });

        return $q->exists();
    }
}
