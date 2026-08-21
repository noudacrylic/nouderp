<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Lepas operator penaung dari langkah produksi yang terlanjur mencantumkannya.
 *
 * Sejak pilihan eksekutor dibatasi ke pelaku sebenarnya (mesin), catatan lama masih memuat
 * operator penaung sebagai pelaku. Selama itu dibiarkan, satu jam kerja tercatat dua kali —
 * sekali di mesin, sekali di operatornya — dan pembagi kapasitas jadi tidak bisa dipakai.
 *
 * Yang TIDAK disentuh: baris eksekutornya sendiri di `production_department_executors`.
 * Hierarki operator→mesin tetap diperlukan, karena auto-pause bekerja lewat situ: scan
 * pulang operator ikut menghentikan mesin-mesin di bawahnya.
 */
class StripSupervisorExecutors extends Command
{
    protected $signature = 'production:strip-supervisor-executors {--dry-run : Tampilkan dampaknya tanpa mengubah apa pun}';

    protected $description = 'Hapus operator penaung dari daftar pelaku langkah produksi (mesin yang bekerja, bukan operatornya)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $supervisors = DB::table('production_department_executors as e')
            ->whereExists(fn ($q) => $q->from('production_department_executors as c')
                ->whereColumn('c.parent_executor_id', 'e.id'))
            ->pluck('e.name', 'e.id');

        if ($supervisors->isEmpty()) {
            $this->info('Tidak ada operator penaung. Tidak ada yang perlu dibersihkan.');
            return self::SUCCESS;
        }

        $ids = $supervisors->keys()->all();
        $this->line('Operator penaung: ' . $supervisors->map(fn ($n, $i) => "{$n} (#{$i})")->join(', '));

        $pivot  = DB::table('production_order_step_executors')->whereIn('executor_id', $ids)->count();
        $status = DB::table('production_step_executor_status')->whereIn('executor_id', $ids)->count();
        $primary = DB::table('production_order_steps')->whereIn('executor_id', $ids)->count();

        // Langkah yang setelah dibersihkan tidak menyisakan pelaku sama sekali. Sengaja tidak
        // ditebak mesinnya — data tidak tahu mesin yang mana, dan menebak akan memalsukan
        // kapasitas. Langkah ini akan tampil sebagai "Tanpa eksekutor" di Kalender Produksi.
        $yatim = DB::table('production_order_step_executors')
            ->select('step_id')
            ->groupBy('step_id')
            ->havingRaw('SUM(CASE WHEN executor_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') THEN 1 ELSE 0 END) = COUNT(*)', $ids)
            ->pluck('step_id');

        $this->newLine();
        $this->table(['Yang dibersihkan', 'Jumlah'], [
            ['Baris pelaku langkah (production_order_step_executors)', $pivot],
            ['Baris status per-eksekutor (production_step_executor_status)', $status],
            ['Langkah dengan executor_id utama menunjuk penaung', $primary],
            ['Langkah yang jadi tanpa pelaku sama sekali', $yatim->count()],
        ]);

        if ($yatim->isNotEmpty()) {
            $rows = DB::table('production_order_steps as s')
                ->join('production_orders as o', 'o.id', '=', 's.production_order_id')
                ->whereIn('s.id', $yatim)
                ->select('s.id', 'o.order_number', 's.name', 's.started_at')
                ->get();

            $this->warn('Langkah berikut akan tampil sebagai "Tanpa eksekutor" — mesinnya tidak diketahui, tidak ditebak:');
            $this->table(['Step', 'OP', 'Langkah', 'Mulai'], $rows->map(fn ($r) => [
                $r->id, $r->order_number, $r->name, $r->started_at,
            ])->all());
        }

        if ($dry) {
            $this->newLine();
            $this->info('Dry-run: tidak ada yang diubah. Jalankan tanpa --dry-run untuk menerapkan.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($ids) {
            DB::table('production_order_step_executors')->whereIn('executor_id', $ids)->delete();
            DB::table('production_step_executor_status')->whereIn('executor_id', $ids)->delete();

            // executor_id utama dialihkan ke pelaku yang tersisa; kalau tidak ada, dikosongkan.
            foreach (DB::table('production_order_steps')->whereIn('executor_id', $ids)->pluck('id') as $stepId) {
                $pengganti = DB::table('production_order_step_executors')
                    ->where('step_id', $stepId)->orderBy('id')->value('executor_id');

                DB::table('production_order_steps')->where('id', $stepId)->update(['executor_id' => $pengganti]);
            }
        });

        $this->newLine();
        $this->info('Selesai. Hierarki operator→mesin tidak diubah — auto-pause tetap bekerja lewat situ.');

        return self::SUCCESS;
    }
}
