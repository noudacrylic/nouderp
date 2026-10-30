<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeClient;
use App\Modules\Marketplace\Shopee\Services\ShopeeReturLamaService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tuntaskan draft retur Shopee lama dari angka Shopee (lihat ShopeeReturLamaService).
 *
 * TANPA --post = percobaan: setiap draft benar-benar diproses di dalam transaksi lalu
 * dibatalkan (rollback), sehingga jurnal yang akan terbentuk & penjaga posting ikut teruji
 * tanpa meninggalkan bekas. --post menyimpan per draft (satu transaksi per draft).
 */
class ShopeeReturLama extends Command
{
    protected $signature = 'shopee:retur-lama
                            {--dari=2026-06-01 : Tanggal pesanan awal}
                            {--sampai=2026-09-30 : Tanggal pesanan akhir}
                            {--sn=* : Batasi ke nomor pesanan Shopee tertentu}
                            {--kelompok= : Batasi ke kelompok A, B, atau C}
                            {--csv= : Simpan rincian ke berkas CSV}
                            {--post : Simpan sungguhan (tanpa ini hanya percobaan)}';

    protected $description = 'Tuntaskan draft retur Shopee lama berdasarkan rincian dana Shopee';

    public function handle(ShopeeClient $client, ShopeeReturLamaService $svc): int
    {
        if (!$client->siap() || !($shop = ShopeeShop::aktif())) {
            $this->error('Toko Shopee belum terhubung.');

            return self::FAILURE;
        }

        $post = (bool) $this->option('post');
        $rencana = $svc->rencana($shop, Carbon::parse($this->option('dari')), Carbon::parse($this->option('sampai')), $this->option('sn'));
        if ($k = $this->option('kelompok')) {
            $rencana = array_values(array_filter($rencana, fn ($r) => $r['kelompok'] === strtoupper($k)));
        }

        $this->info(($post ? 'POSTING' : 'PERCOBAAN (tidak disimpan)') . ' — ' . count($rencana) . ' draft');
        $csv = [];
        $ringkas = [];

        foreach ($rencana as $r) {
            $d = $r['draft'];
            $baris = [
                $r['kelompok'], $d->return_number, $r['sn'], $r['kasus'] ?? '', $r['nilai'], $r['refund'], $r['ganti'], $r['cair'], $r['dibalik'], $r['potongan'],
            ];
            $hasil = null;
            $galat = null;

            if ($r['kelompok'] !== '-') {
                DB::beginTransaction();
                try {
                    $hasil = $svc->jalankan($r);
                    $post ? DB::commit() : DB::rollBack();
                } catch (Throwable $e) {
                    DB::rollBack();
                    $galat = $e->getMessage();
                }
            }

            $status = $galat ? 'GAGAL: ' . $galat : ($r['kelompok'] === '-' ? 'LEWATI: ' . $r['alasan'] : ($post ? 'OK' : 'siap'));
            $angka = array_map(fn ($v) => number_format($v, 0, ',', '.'), array_slice($baris, 4));
            $this->line(vsprintf('%s %-18s %-15s %-4s nilai %9s refund %9s ganti %9s cair %9s dibalik %9s potongan %7s  %s',
                [$r['kelompok'], $d->return_number, $r['sn'], $r['kasus'] ?? '', ...$angka, $status]));
            if ($r['kelompok'] !== '-' && !$galat) {
                $this->line('    ' . $r['alasan']);
            }
            foreach ($hasil['jurnal'] ?? [] as $j) {
                $this->line(sprintf('    %s %s  %-5s %-32s %12s %12s', $j['journal_number'], $j['date'], $j['code'], mb_substr($j['name'], 0, 32), $j['debit'] > 0 ? number_format($j['debit'], 0, ',', '.') : '', $j['credit'] > 0 ? number_format($j['credit'], 0, ',', '.') : ''));
            }
            foreach ($hasil['catatan'] ?? [] as $c) {
                $this->line('    · ' . $c);
            }

            $kunci = $galat ? 'gagal' : $r['kelompok'];
            $ringkas[$kunci] = ($ringkas[$kunci] ?? 0) + 1;
            $csv[] = array_merge($baris, [$status, implode(' | ', array_map(fn ($j) => "{$j['code']} D{$j['debit']} K{$j['credit']}", $hasil['jurnal'] ?? []))]);
        }

        $this->newLine();
        foreach ($ringkas as $k => $n) {
            $this->line("  {$k}: {$n}");
        }

        if ($path = $this->option('csv')) {
            $f = fopen($path, 'w');
            fputcsv($f, ['kelompok', 'retur', 'pesanan', 'kasus', 'nilai', 'refund', 'diganti', 'cair', 'dibalik', 'potongan', 'status', 'jurnal']);
            foreach ($csv as $row) {
                fputcsv($f, $row);
            }
            fclose($f);
            $this->info("CSV: {$path}");
        }

        return self::SUCCESS;
    }
}
