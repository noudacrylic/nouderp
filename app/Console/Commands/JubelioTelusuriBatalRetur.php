<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Jubelio\Models\JubelioSetting;
use App\Modules\Marketplace\Jubelio\Services\JubelioOrderSyncService;
use Illuminate\Console\Command;

/**
 * Pilah pesanan batal yang terlanjur jadi retur: sudah atau belum diserahkan ke kurir?
 *
 * Sekali jalan untuk data lama (sebelum ada kolom shipped_at). TIDAK mem-void apa pun —
 * yang "belum dikirim" diputuskan admin lewat tombol di kartu tab Retur.
 * Tanpa --simpan hanya menampilkan; dengan --simpan yang terbukti sudah dikirim
 * dicatatkan shipped_at-nya, sehingga chip & tombol koreksi di kartunya hilang.
 */
class JubelioTelusuriBatalRetur extends Command
{
    protected $signature = 'jubelio:telusuri-batal-retur
                            {--simpan : Catat shipped_at untuk yang terbukti sudah dikirim}
                            {--bukti : Tampilkan isian pengiriman dari Jubelio per pesanan}';

    protected $description = 'Pilah pesanan batal yang terlanjur jadi retur: sudah/belum diserahkan ke kurir (cek ulang ke Jubelio)';

    public function handle(JubelioOrderSyncService $sync): int
    {
        if (!JubelioSetting::singleton()->isConfigured()) {
            $this->warn('Integrasi Jubelio belum aktif/dikonfigurasi.');
            return self::FAILURE;
        }

        $hasil = collect($sync->telusuriBatalTerlanjurRetur((bool) $this->option('simpan')));

        if ($hasil->isEmpty()) {
            $this->info('Tidak ada pesanan batal yang terlanjur jadi retur.');
            return self::SUCCESS;
        }

        $judul = [
            'belum_dikirim' => 'KEMUNGKINAN BELUM DIKIRIM → cek resi, lalu tekan "Bukan retur — batal sebelum dikirim"',
            'sudah_dikirim' => 'SUDAH DISERAHKAN KE KURIR → tetap retur',
            'gagal'         => 'GAGAL DITARIK DARI JUBELIO → cek manual',
        ];

        foreach ($judul as $kunci => $teks) {
            $grup = $hasil->where('hasil', $kunci);
            if ($grup->isEmpty()) {
                continue;
            }

            $this->newLine();
            $this->line("<options=bold>{$teks} ({$grup->count()})</>");
            $this->table(['SO', 'No. Jubelio', 'Resi', 'Alasan batal'], $grup->map(fn ($r) => [
                $r['so'],
                $r['link']->jubelio_salesorder_no,
                $r['link']->tracking_no ?: '-',
                mb_strimwidth((string) ($r['link']->cancel_reason ?: '-'), 0, 45, '…'),
            ])->all());

            if ($this->option('bukti')) {
                foreach ($grup as $r) {
                    $this->line("  {$r['link']->jubelio_salesorder_no}: " . json_encode($r['bukti'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            }
        }

        if ($this->option('simpan')) {
            $this->info("\nshipped_at dicatat untuk {$hasil->where('hasil', 'sudah_dikirim')->count()} pesanan yang sudah dikirim.");
        } else {
            $this->comment("\nBelum ada yang diubah. Jalankan dengan --simpan untuk mencatat yang sudah dikirim.");
        }

        return self::SUCCESS;
    }
}
