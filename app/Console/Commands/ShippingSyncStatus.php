<?php

namespace App\Console\Commands;

use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Shipping\Providers\JubelioShipmentProvider;
use Illuminate\Console\Command;

/**
 * Segarkan status pengiriman Surat Jalan dari Jubelio Shipment, lalu tandai yang DELIVERED
 * sudah sampai — pesanannya pindah sendiri dari tab "Dikirim" ke "Selesai".
 *
 * Ini JARING PENGAMAN, bukan jalur utama: yang utama adalah webhook Jubelio (real-time).
 * Webhook bisa terlewat — server sedang mati, secret salah, atau resi dibuat sebelum webhook
 * didaftarkan — dan tanpa penyapu berkala paket-paket itu akan menggantung selamanya di
 * "Dikirim". Tombol "Sudah Sampai" di kartu tetap ada sebagai jalan manual.
 */
class ShippingSyncStatus extends Command
{
    protected $signature = 'shipping:sync-status {--limit=200 : Maksimum Surat Jalan yang dicek sekali jalan}
                                                 {--days=45 : Abaikan Surat Jalan yang lebih tua dari ini}';
    protected $description = 'Tarik status kurir Jubelio Shipment & tandai paket yang sudah sampai';

    public function handle(JubelioShipmentProvider $provider): int
    {
        if (!$provider->isReady()) {
            $this->warn('Jubelio Shipment belum dikonfigurasi. Lewati.');

            return self::SUCCESS;
        }

        $deliveries = SalesDelivery::query()
            ->where('status', 'posted')
            ->where('delivery_method', '!=', 'ambil_toko')
            ->whereNull('delivered_at')
            ->whereNotNull('provider_order_id')
            ->where('created_at', '>=', now()->subDays((int) $this->option('days')))
            ->orderByDesc('id')
            ->limit((int) $this->option('limit'))
            ->get();

        $dicek = $sampai = $gagal = 0;

        foreach ($deliveries as $sj) {
            $dicek++;
            $res = $provider->track($sj->provider_order_id);

            if (!$res['success']) {
                $gagal++;
                continue;
            }

            $internal = JubelioShipmentProvider::internalStatus($res['status']);
            if ($internal && $internal !== $sj->shipping_status) {
                $sj->update(['shipping_status' => $internal]);
            }

            if ($internal === 'delivered' && $sj->markDelivered()) {
                $sampai++;
            }
        }

        $this->info("shipping:sync-status selesai — dicek {$dicek}, ditandai sampai {$sampai}, gagal ditarik {$gagal}.");

        return self::SUCCESS;
    }
}
