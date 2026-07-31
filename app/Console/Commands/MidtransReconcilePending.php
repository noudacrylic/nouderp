<?php

namespace App\Console\Commands;

use App\Modules\Payment\Services\MidtransService;
use Illuminate\Console\Command;

/**
 * Menyusul status pembayaran yang notifikasinya tidak sampai.
 *
 * Webhook adalah jalur utama; ini jaring pengamannya. Dijadwalkan tiap 15 menit,
 * dan bisa dipanggil manual dari Pengaturan → Midtrans.
 */
class MidtransReconcilePending extends Command
{
    protected $signature = 'midtrans:reconcile-pending
                            {--limit=200 : Batas transaksi yang dicek sekali jalan}
                            {--dry-run   : Tampilkan yang akan berubah tanpa menyimpan}';

    protected $description = 'Tarik status transaksi Midtrans yang masih pending (jaring pengaman kalau webhook gagal)';

    public function handle(MidtransService $midtrans): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit  = max(1, (int) $this->option('limit'));

        if ($dryRun) {
            $this->warn('MODE UJI COBA — tidak ada data yang disimpan.');
        }

        $r = $midtrans->reconcilePending($limit, $dryRun);

        $this->line("Dicek        : {$r['checked']} transaksi pending");
        $this->line("Masih pending: {$r['unchanged']}");
        $this->line("Tidak dikenal: {$r['not_found']} (tidak pernah dibuat di Midtrans)");

        if ($r['updated']) {
            $this->newLine();
            $this->info('Status berubah:');
            $this->table(
                ['Order ID', 'Dari', 'Menjadi'],
                array_map(fn ($u) => [$u['order_id'], $u['from'], $u['to']], $r['updated']),
            );
        } else {
            $this->line('Status berubah: tidak ada');
        }

        if ($r['failed']) {
            $this->newLine();
            $this->error('Gagal dicek (' . count($r['failed']) . '):');
            foreach ($r['failed'] as $f) {
                $this->line("  {$f['order_id']} — {$f['error']}");
            }

            // Gagal sebagian tetap dianggap gagal supaya cron/monitor ikut menandainya;
            // transaksi yang berhasil sudah terlanjur diperbarui, jadi tidak ada yang hilang.
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
