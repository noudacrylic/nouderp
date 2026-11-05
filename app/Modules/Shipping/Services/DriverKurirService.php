<?php

namespace App\Modules\Shipping\Services;

use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Shipping\Providers\JubelioShipmentProvider;

/**
 * Menyimpan driver kurir instan selagi masih ada. Halaman lacak Jubelio mengosongkan
 * driver_info setelah paket terkirim, jadi data yang sudah tersimpan tidak pernah dihapus;
 * yang diperbarui hanya bila driver berganti di tengah jalan.
 */
class DriverKurirService
{
    public function __construct(private JubelioShipmentProvider $provider)
    {
    }

    /** @return bool true bila data driver berubah */
    public function tangkap(SalesDelivery $delivery): bool
    {
        $url = data_get($delivery->shipping_raw, 'tracking_url');
        if (! filled($url)) {
            return false;
        }

        $driver = $this->provider->publicTracking($url)['driver_info'] ?? null;
        $nama   = is_array($driver) ? mb_substr(trim((string) ($driver['name'] ?? '')), 0, 100) : '';
        $nomor  = is_array($driver) ? mb_substr(trim((string) ($driver['phone'] ?? '')), 0, 30) : '';

        if ($nama === '' && $nomor === '') {
            return false;
        }
        if ($nama === (string) $delivery->driver_name && $nomor === (string) $delivery->driver_phone) {
            return false;
        }

        $delivery->forceFill([
            'driver_name'        => $nama ?: null,
            'driver_phone'       => $nomor ?: null,
            'driver_captured_at' => now(),
        ])->save();

        return true;
    }
}
