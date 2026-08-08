<?php

namespace App\Console\Commands;

use App\Models\FreightSetting;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Shipping\Services\ShippingAccountingService;
use Illuminate\Console\Command;

/**
 * Jurnalkan ulang ongkir Surat Jalan yang resinya sudah terbit tapi jurnalnya tidak pernah
 * jadi — kasus khasnya: akun "Saldo Jubelio Shipment" belum diset saat resi dibooking,
 * sehingga ShippingAccountingService melempar DomainException dan ShipmentBookingService
 * hanya menampilkan flash kuning (resi tetap terbit, saldo Coins tetap terpotong di sana,
 * tapi di buku tidak ada catatannya).
 *
 * Memakai postBooking() yang sama dengan jalur live — jadi hasil backfill identik dengan
 * data baru, idempoten (dilindungi activeBookingCount), dan sekalian men-settle selisih
 * ongkir SO yang fakturnya sudah posted.
 *
 * Tanggal jurnal = tanggal Surat Jalan, bukan hari ini. SJ di periode yang sudah DITUTUP
 * karena itu akan gagal dan dilaporkan sebagai "periode tutup" — buka periodenya dulu
 * (Akuntansi → Periode) baru jalankan ulang.
 */
class BackfillShippingBookingJournal extends Command
{
    protected $signature = 'shipping:backfill-booking-journal
                            {--provider=jubelio_shipment : Provider yang di-backfill, atau "all" untuk semua provider berdeposit}
                            {--dry-run : Hanya tampilkan yang akan dijurnal, tanpa menyimpan}';

    protected $description = 'Buat jurnal ongkir untuk Surat Jalan yang sudah booked tapi belum pernah terjurnal (mis. akun saldo provider belum diset saat booking)';

    public function handle(ShippingAccountingService $accounting): int
    {
        $dry      = (bool) $this->option('dry-run');
        $provider = (string) $this->option('provider');

        $providers = $provider === 'all'
            ? ShippingAccountingService::PROVIDERS
            : [$provider];

        foreach ($providers as $p) {
            if (!in_array($p, ShippingAccountingService::PROVIDERS, true)) {
                $this->error("Provider '{$p}' tidak punya saldo deposit — pilihan: "
                    . implode(', ', ShippingAccountingService::PROVIDERS) . ', atau all.');

                return self::FAILURE;
            }
        }

        // Cek akun dulu supaya tidak menghasilkan ratusan baris error yang sebabnya sama.
        $setting = FreightSetting::singleton();
        foreach ($providers as $p) {
            if (!$setting->saldoAccountId($p)) {
                $label = FreightSetting::providerLabel($p);
                $this->error("Akun 'Saldo {$label}' belum diset di Settings → Pengaturan Ongkir. Isi dulu, baru jalankan lagi.");

                return self::FAILURE;
            }
        }

        $deliveries = SalesDelivery::whereIn('shipping_provider', $providers)
            ->where('shipping_status', 'booked')
            ->where('status', '!=', 'void')
            ->where('shipping_cost', '>', 0)
            ->orderBy('id')
            ->get();

        $this->info(($dry ? '[DRY-RUN] ' : '') . "Memeriksa {$deliveries->count()} Surat Jalan ber-resi...");

        $sudah = 0;         // sudah ada jurnal booking aktif → dilewati
        $baris = [];        // kandidat / hasil
        $gagal = [];
        $total = 0.0;

        foreach ($deliveries as $delivery) {
            if ($accounting->activeBookingCount($delivery) > 0) {
                $sudah++;
                continue;
            }

            $cost = (float) $delivery->shipping_cost;
            $total += $cost;

            if (!$dry) {
                try {
                    $accounting->postBooking($delivery->fresh('order'));
                } catch (\Throwable $e) {
                    $gagal[] = [$delivery->delivery_number, $e->getMessage()];
                    $total -= $cost;
                    continue;
                }
            }

            $baris[] = [
                $delivery->delivery_number,
                $delivery->delivery_date,
                $delivery->tracking_number ?: '-',
                number_format($cost, 0, ',', '.'),
            ];
        }

        if ($baris) {
            $this->newLine();
            $this->table(['Surat Jalan', 'Tanggal', 'Resi', 'Ongkir'], $baris);
        }

        if ($gagal) {
            $this->newLine();
            $this->error('Gagal dijurnal:');
            $this->table(['Surat Jalan', 'Sebab'], $gagal);
        }

        $this->newLine();
        $this->table(['Metrik', 'Jumlah'], [
            ['SJ diperiksa', $deliveries->count()],
            ['Sudah terjurnal (dilewati)', $sudah],
            [$dry ? 'Akan dijurnal' : 'Berhasil dijurnal', count($baris)],
            ['Gagal', count($gagal)],
            ['Total ongkir', 'Rp ' . number_format($total, 0, ',', '.')],
        ]);

        if ($dry) {
            $this->comment('Ini pratinjau. Jalankan tanpa --dry-run untuk menyimpan jurnalnya.');
        } elseif ($baris) {
            $this->comment('Selesai. Cek Settings → Pengaturan Ongkir untuk membandingkan saldo buku dengan Coins asli di dashboard Jubelio.');
        }

        return $gagal ? self::FAILURE : self::SUCCESS;
    }
}
