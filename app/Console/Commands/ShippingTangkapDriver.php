<?php

namespace App\Console\Commands;

use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Shipping\Services\DriverKurirService;
use Illuminate\Console\Command;

/**
 * Jaring pengaman penangkap driver: webhook belum tentu datang tepat setelah driver ditemukan,
 * padahal datanya hilang begitu paket terkirim. Hanya paket yang masih di jalan yang dicek.
 */
class ShippingTangkapDriver extends Command
{
    protected $signature = 'shipping:tangkap-driver {--hari=3 : Abaikan Surat Jalan yang lebih tua dari ini}';

    protected $description = 'Simpan nama & nomor driver kurir instan selagi pengiriman berjalan';

    public function handle(DriverKurirService $driver): int
    {
        $berubah = 0;

        SalesDelivery::query()
            ->where('shipping_provider', 'jubelio_shipment')
            ->where('status', 'posted')
            ->whereNull('delivered_at')
            ->whereNotNull('tracking_number')
            ->where('created_at', '>=', now()->subDays((int) $this->option('hari')))
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->each(function (SalesDelivery $d) use ($driver, &$berubah) {
                $berubah += $driver->tangkap($d) ? 1 : 0;
            });

        $this->info("shipping:tangkap-driver selesai — driver baru/berubah: {$berubah}.");

        return self::SUCCESS;
    }
}
