<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Services\OpKoinService;
use App\Modules\Tasks\MejaKerja\Contracts\JadiSkillManajer;
use App\Modules\Tasks\MejaKerja\Contracts\SkillAdmin;
use App\Modules\Tasks\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cetak stiker: OP berjalan yang bahannya memuat stiker (produk bernama "Stiker …", mis. OP
 * koin kotak saran dari Manager Produksi) → task cetak di peran Desain & Konten. Dikerjakan
 * BERSAMAAN dengan langkah CNC pertama (bukan langkah OP), supaya stiker siap sebelum
 * Assembling menempelnya.
 *
 * Selesai: tombol Selesai, atau otomatis begitu langkah Assembling OP dimulai (stiker pasti
 * sudah ada). Batal bila OP batal/digabung.
 */
class CetakStikerSkill implements SkillAdmin
{
    use JadiSkillManajer;

    private const STATUS_OP = ['draft', 'confirmed', 'in_progress', 'partial', 'pending'];

    public function key(): string { return 'cetak-stiker'; }

    public function label(): string { return 'Cetak stiker untuk OP'; }

    public function deskripsi(): string
    {
        return 'OP yang bahannya memakai stiker (mis. OP koin kotak saran) → task cetak stiker berisi jumlah lembar per '
            . 'jenis. Dikerjakan bersamaan dengan CNC; selesai saat ditandai atau saat langkah Assembling OP dimulai.';
    }

    public function peranBawaan(): string { return 'desain'; }

    public function bobotBawaan(): int { return 8; }

    /** Stiker harus siap sebelum Assembling — kira-kira selama CNC potong lembar. */
    public function slaMenitBawaan(): int { return 120; }

    public function tersedia(): bool { return true; }

    public function pindai(Carbon $sejak): iterable
    {
        $ops = ProductionOrder::with(['outputs.product', 'materials.product'])
            ->whereIn('status', self::STATUS_OP)
            ->where('created_at', '>=', $sejak)
            ->whereHas('materials.product', fn ($q) => $q->where('name', 'like', 'Stiker%'))
            ->get();

        foreach ($ops as $op) {
            if ($this->assemblingMulai($op->id)) continue;

            $stiker = $op->materials->filter(fn ($m) => str_starts_with((string) $m->product?->name, 'Stiker'))
                ->map(fn ($m) => $m->product->name . ' ' . $this->fmt($m->qty_required) . ' ' . ($m->unit ?: 'lembar'))
                ->implode(', ');
            $hasil = $op->outputs->filter(fn ($o) => (float) $o->qty_planned > 0)
                ->map(fn ($o) => $o->product?->sku . ' ' . $this->fmt($o->qty_planned)
                    . (str_starts_with((string) $o->product?->sku, 'KOIN-')
                        ? ' (' . $this->fmt($o->qty_planned / OpKoinService::KOIN_PER_LEMBAR) . ' lembar stiker)' : ''))
                ->implode(', ');
            $utama = $op->outputs->firstWhere('output_type', 'main')?->product?->sku;

            yield [
                'kunci'         => "cetak-stiker:op:{$op->id}",
                'judul'         => "Cetak stiker {$op->order_number}" . ($utama ? " — {$utama}" : ''),
                'deskripsi'     => "Hasil: {$hasil}.\nBahan: {$stiker}.\nStiker harus siap sebelum langkah Assembling.",
                'taskable_type' => ProductionOrder::class,
                'taskable_id'   => $op->id,
                'meta'          => ['order_number' => $op->order_number],
                'dibuat_at'     => $op->created_at->max($sejak),
            ];
        }
    }

    public function keadaan(Task $task): ?array
    {
        $op = DB::table('production_orders')->where('id', $task->taskable_id)->value('status');
        if (!$op || in_array($op, ['cancelled', 'merged'], true)) return ['cancelled', 'OP dibatalkan/digabung.'];
        if ($op === 'finalized' || $this->assemblingMulai((int) $task->taskable_id)) {
            return ['done', 'Langkah Assembling sudah dimulai — stiker sudah dipakai.'];
        }
        return null;
    }

    public function aksi(Task $task): array
    {
        return [['label' => 'Lihat OP', 'url' => route('production.orders.show', $task->taskable_id), 'utama' => true]];
    }

    public function selesaiManual(): bool { return true; }

    /** Langkah di divisi Assembling OP ini sudah mulai/selesai? */
    private function assemblingMulai(int $opId): bool
    {
        return DB::table('production_order_steps as s')
            ->join('production_departments as d', 'd.id', '=', 's.department_id')
            ->where('s.production_order_id', $opId)
            ->where('d.name', 'like', 'Assembling%')
            ->where(fn ($q) => $q->where('s.status', '!=', 'pending')->orWhereNotNull('s.started_at'))
            ->exists();
    }

    private function fmt($n): string
    {
        return number_format((float) $n, 0, ',', '.');
    }
}
