<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

use App\Core\Inventory\Product;
use App\Models\User;
use App\Modules\Purchasing\Services\StokBahanService;
use App\Modules\Tasks\MejaKerja\Contracts\JadiSkillManajer;
use App\Modules\Tasks\MejaKerja\Contracts\PunyaJawaban;
use App\Modules\Tasks\MejaKerja\Contracts\SkillAdmin;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskHistory;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Purchasing non stok (lem, lakban, sekrup — stoknya tak dilacak, masuk overhead): pemicunya
 * pola beli selama ini. Saat sudah ±80% dari jeda beli biasanya → task CEK persediaan, bukan
 * beli. Jawaban petugas:
 *   - "Masih ada ±N hari" → task selesai, cek lagi N hari kemudian;
 *   - "Perlu beli" → task yang sama naik ke tahap Beli, selesai otomatis saat PO dibuat.
 * Barang yang baru sekali dibeli belum punya pola → menunggu laporan divisi (Lapor Stok).
 */
class PurchasingNonStokSkill implements SkillAdmin, PunyaJawaban
{
    use JadiSkillManajer;

    /** Cek mulai saat jeda sejak beli terakhir ≥ 80% jeda biasanya. */
    public const AMBANG = 0.8;

    /** Pilihan "masih cukup ±N hari lagi". */
    public const CUKUP_HARI = [3, 7, 14];

    public function __construct(private StokBahanService $stok) {}

    public function key(): string { return 'purchasing-nonstok'; }

    public function label(): string { return 'Purchasing non stok'; }

    public function deskripsi(): string
    {
        return 'Barang non stok (lem, lakban, sekrup — masuk overhead) yang sudah mendekati jadwal beli biasanya (dari riwayat PO, min. 2× beli) → task CEK persediaan. "Masih ada" → dicek lagi sesuai perkiraan; "Perlu beli" → jadi task Beli, selesai saat PO dibuat.';
    }

    public function peranBawaan(): string { return 'layanan'; }

    public function bobotBawaan(): int { return 5; }

    public function slaMenitBawaan(): int { return 480; }

    public function tersedia(): bool { return true; }

    public function pindai(Carbon $sejak): iterable
    {
        $pola = $this->stok->polaBeliNonStok();
        $ids = $pola->pluck('id')->all();
        $ditunggu = $this->stok->adaPoTerbuka($ids);
        $tasks = Task::where('skill', $this->key())->whereIn('taskable_id', $ids)->get(['id', 'taskable_id', 'status', 'meta', 'created_at'])->groupBy('taskable_id');

        foreach ($pola as $p) {
            $milik = $tasks->get($p->id, collect());
            if (isset($ditunggu[$p->id]) || $milik->contains(fn ($t) => in_array($t->status, ['open', 'in_progress', 'pending'], true))) continue;

            // Sejak beli terakhir: jawaban "masih ada" terbaru menentukan kapan cek lagi.
            $sesudahBeli = $milik->filter(fn ($t) => $t->created_at->gte($p->terakhir));
            $cekLagi = $sesudahBeli->map(fn ($t) => $t->meta['cek_lagi'] ?? null)->filter()->max();
            $jatuh = $cekLagi ? Carbon::parse($cekLagi)->startOfDay() : $p->terakhir->copy()->addDays((int) ceil($p->jeda * self::AMBANG));
            if ($jatuh->isFuture()) continue;

            $hariLalu = (int) $p->terakhir->diffInDays(today());
            yield [
                'kunci'         => "cek:{$p->id}:" . $p->terakhir->format('Ymd') . ':' . ($sesudahBeli->count() + 1),
                'judul'         => "Cek persediaan {$p->name} ({$p->sku})",
                'deskripsi'     => "Biasa dibeli tiap ±{$p->jeda} hari ({$p->kali}× pembelian). Terakhir dibeli {$p->terakhir->format('d/m/Y')} ({$hariLalu} hari lalu)."
                    . ($cekLagi ? "\nCek sebelumnya: masih ada." : ''),
                'taskable_type' => Product::class,
                'taskable_id'   => $p->id,
                'meta'          => ['tahap' => 'cek', 'sku' => $p->sku, 'terakhir_beli' => $p->terakhir->toDateString(), 'jeda' => $p->jeda],
                'dibuat_at'     => $jatuh->max($sejak),
            ];
        }
    }

    public function keadaan(Task $task): ?array
    {
        $sejak = $task->meta['beli_sejak'] ?? $task->created_at;
        $po = DB::table('purchase_order_items as i')->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')
            ->where('i.product_id', $task->taskable_id)->where('o.status', '!=', 'cancelled')
            ->where('o.created_at', '>', $sejak)->value('o.po_number');
        if ($po) return ['done', "Dibeli lewat PO $po."];
        if (!DB::table('products')->where('id', $task->taskable_id)->where('is_active', true)->exists()) {
            return ['cancelled', 'Barang diarsipkan.'];
        }
        return null;
    }

    public function aksi(Task $task): array
    {
        $beli = ['label' => 'Buat PO', 'url' => route('purchasing.orders.create', ['product_id' => $task->taskable_id])];
        return ($task->meta['tahap'] ?? 'cek') === 'beli' ? [$beli + ['utama' => true]] : [];
    }

    public function selesaiManual(): bool { return false; }

    public function jawaban(Task $task): array
    {
        if (($task->meta['tahap'] ?? 'cek') !== 'cek') return [];

        return [
            ...array_map(fn ($h) => ['kunci' => "ada-$h", 'label' => "Masih ada ±$h hari"], self::CUKUP_HARI),
            ['kunci' => 'beli', 'label' => 'Perlu beli', 'utama' => true],
        ];
    }

    public function jawab(Task $task, string $kunci, User $u): string
    {
        $meja = app(MejaKerjaService::class);

        if ($kunci === 'beli') {
            $task->meta = array_merge($task->meta ?? [], ['tahap' => 'beli', 'beli_sejak' => now()->toDateTimeString(), 'jawaban' => 'perlu_beli']);
            $task->title = mb_substr(preg_replace('/^Cek persediaan /', 'Beli ', $task->title), 0, 255);
            $task->save();
            TaskHistory::create(['task_id' => $task->id, 'user_id' => $u->id, 'action' => 'perlu_beli', 'summary' => 'Hasil cek: perlu beli', 'created_at' => now()]);
            return 'Dicatat: perlu beli. Task ini selesai sendiri saat PO dibuat.';
        }

        $hari = (int) substr($kunci, 4);
        abort_unless(str_starts_with($kunci, 'ada-') && in_array($hari, self::CUKUP_HARI, true), 422, 'Jawaban tidak dikenal.');
        $task->meta = array_merge($task->meta ?? [], ['jawaban' => 'masih_ada', 'cukup_hari' => $hari, 'cek_lagi' => today()->addDays($hari)->toDateString()]);
        $meja->ubahStatus($task, 'done', $u, "Hasil cek: masih ada ±$hari hari");

        return "Dicatat: masih ada. Dicek lagi " . today()->addDays($hari)->format('d/m') . '.';
    }
}
