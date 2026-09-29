<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink;
use App\Modules\Marketplace\Jubelio\Services\JubelioOrderSyncService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Terbitkan faktur SUSULAN untuk pesanan marketplace yang barangnya sudah keluar tapi tak
 * pernah difakturkan.
 *
 * LATAR. Dulu faktur marketplace hanya terbit saat pesanan berstatus "selesai" di Jubelio.
 * Pesanan yang berakhir retur atau paket hilang tidak pernah mencapai status itu, sehingga
 * fakturnya tak pernah ada. Akibatnya permanen: omzetnya tak pernah diakui, dan karena Surat
 * Jalan TIDAK menjurnal apa pun, harga pokoknya juga tak pernah masuk buku besar — barang yang
 * fisiknya sudah keluar tetap dihitung sebagai Persediaan.
 *
 * Sejak faktur terbit saat pengiriman, kasus baru tidak muncul lagi. Command ini membereskan
 * yang terlanjur.
 *
 * YANG DILEWATI (sengaja): pesanan yang returnya SUDAH DIPOSTING. Retur gaya lama itu membalik
 * Uang Muka (bukan Penjualan), jadi sebagian saldo uang muka sudah terpakai. Menerbitkan faktur
 * sekarang akan memakainya sekali lagi dan membuat Uang Muka jadi minus. Pesanan tersebut masuk
 * tumpukan data lama yang perlu keputusan tersendiri.
 *
 * Retur yang masih DRAFT ikut dipindahkan ke faktur baru — draft belum punya jurnal, dan
 * membiarkannya menempel ke SO akan meledak saat diposting nanti (mendebit Uang Muka yang sudah
 * habis dipakai faktur).
 *
 * Tanggal faktur = HARI INI, bukan tanggal kirim: periode bulan-bulan lalu umumnya sudah
 * ditutup. Konsekuensinya omzet & HPP pengiriman lama jatuh di bulan berjalan.
 */
class MarketplaceFakturSusulan extends Command
{
    protected $signature = 'marketplace:faktur-susulan
        {--dry-run : Hanya tampilkan yang akan difakturkan, tanpa menulis apa pun}
        {--order= : Batasi ke satu nomor SO (untuk uji satu pesanan lebih dulu)}
        {--limit= : Batasi jumlah pesanan yang diproses}';

    protected $description = 'Terbitkan faktur susulan untuk pesanan marketplace yang sudah dikirim tapi belum berfaktur';

    public function handle(JubelioOrderSyncService $sync): int
    {
        $dry = (bool) $this->option('dry-run');

        $q = SalesOrder::query()
            ->whereHas('customer', fn ($c) => $c->where('is_marketplace', true))
            ->where('status', '!=', 'void')
            // Barang benar-benar sudah keluar.
            ->whereExists(fn ($w) => $w->select(DB::raw(1))->from('sales_deliveries as sd')
                ->whereColumn('sd.sales_order_id', 'sales_orders.id')->where('sd.status', 'posted'))
            // Belum punya faktur aktif.
            ->whereNotExists(fn ($w) => $w->select(DB::raw(1))->from('sales_invoices as si')
                ->whereColumn('si.sales_order_id', 'sales_orders.id')->where('si.status', '!=', 'void'))
            ->orderBy('id');

        if ($order = $this->option('order')) {
            $q->where('order_number', $order);
        }
        if ($limit = (int) $this->option('limit')) {
            $q->limit($limit);
        }

        $orders = $q->get();
        $this->info(($dry ? '[DRY-RUN] ' : '') . "Ditemukan {$orders->count()} pesanan marketplace sudah dikirim tapi belum berfaktur.");

        $dibuat = 0; $dilewati = 0; $gagal = 0; $returDipindah = 0; $nilai = 0.0; $belumKotor = 0;

        foreach ($orders as $so) {
            $returPosted = SalesReturn::where('sales_order_id', $so->id)->where('status', 'posted')->count();
            if ($returPosted > 0) {
                $this->warn("  • {$so->order_number}: DILEWATI — returnya sudah diposting gaya lama (uang muka sudah terpakai).");
                $dilewati++;
                continue;
            }

            // URUTAN WAJIB: `marketplace:dp-ke-kotor` harus jalan LEBIH DULU. Kalau SO masih
            // memakai nilai bersih, fakturnya ikut terbit bersih — padahal settlement nanti
            // akan mengurangkan biaya admin sekali lagi dari nilai yang sudah bersih, dan
            // dp-ke-kotor tak bisa lagi memperbaikinya (ia melewati SO yang sudah berfaktur).
            $kotor = round((float) $so->subtotal + (float) $so->shipping_cost + (float) ($so->additional_fee ?? 0), 2);
            if (abs($kotor - (float) $so->grand_total) > 0.01) {
                $this->warn("  • {$so->order_number}: DILEWATI — nilainya masih bersih ({$so->grand_total} vs kotor {$kotor}). Jalankan `marketplace:dp-ke-kotor` dulu.");
                $belumKotor++;
                continue;
            }

            $this->line("  • {$so->order_number}: faktur " . number_format((float) $so->grand_total, 0, ',', '.'));
            $nilai += (float) $so->grand_total;

            if ($dry) {
                $dibuat++;
                continue;
            }

            try {
                DB::transaction(function () use ($so, $sync, &$dibuat, &$returDipindah) {
                    $draftSebelum = $this->draftDiSO($so);

                    // Retur draft SO ikut dipindah di dalam terbitkanFakturPengiriman.
                    $invoice = $sync->terbitkanFakturPengiriman($so);
                    if (!$invoice) {
                        $this->warn("    tak ada baris terkirim yang belum difakturkan — dilewati.");
                        return;
                    }

                    $tertinggal = $this->draftDiSO($so);
                    $returDipindah += $draftSebelum - $tertinggal;
                    if ($tertinggal > 0) {
                        $this->warn("    {$tertinggal} retur draft dibiarkan di SO — ada baris yang tak terpetakan ke faktur.");
                    }

                    JubelioOrderLink::where('sales_order_id', $so->id)
                        ->update(['invoice_posted' => true]);

                    $dibuat++;
                });
            } catch (\Throwable $e) {
                $gagal++;
                $this->error("    GAGAL: " . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info(($dry ? '[DRY-RUN] ' : '') . sprintf(
            'Difakturkan: %d (Rp %s) · retur draft dipindah: %d · dilewati (retur posted): %d · dilewati (masih bersih): %d · gagal: %d',
            $dibuat, number_format($nilai, 0, ',', '.'), $returDipindah, $dilewati, $belumKotor, $gagal
        ));

        if ($belumKotor > 0) {
            $this->warn("Ada {$belumKotor} pesanan yang nilainya masih bersih. Jalankan `marketplace:dp-ke-kotor` dulu, baru ulangi command ini.");
        }

        if ($dry) {
            $this->comment('Tidak ada yang ditulis. Jalankan tanpa --dry-run untuk menerapkan.');
        }

        return self::SUCCESS;
    }

    private function draftDiSO(SalesOrder $so): int
    {
        return SalesReturn::where('sales_order_id', $so->id)->where('status', 'draft')->count();
    }
}
