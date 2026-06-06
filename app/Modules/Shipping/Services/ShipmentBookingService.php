<?php

namespace App\Modules\Shipping\Services;

use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Shipping\Providers\BiteshipProvider;

/**
 * Logika booking resi Biteship — dipakai oleh booking tunggal (Surat Jalan / POS) maupun
 * booking massal (POS → Telah Diproses). Mengembalikan array hasil (bukan redirect) supaya
 * bisa di-loop. Override berat/dimensi opsional; bila kosong → pakai default SO/produk.
 */
class ShipmentBookingService
{
    public function __construct(protected BiteshipProvider $biteship) {}

    /**
     * @param array{weight_gram?:mixed,package_length?:mixed,package_width?:mixed,package_height?:mixed} $overrides
     * @return array{success:bool, level:string, message:string, tracking:?string}
     */
    public function book(SalesDelivery $delivery, ?string $collectionMethod = null, array $overrides = []): array
    {
        $delivery->loadMissing(['order.customer', 'invoice.customer', 'items.product']);

        if ($delivery->status !== 'posted') {
            return $this->fail($delivery, 'Surat Jalan harus diposting dulu sebelum booking resi.');
        }
        if ($delivery->delivery_method === 'ambil_toko') {
            return $this->fail($delivery, 'Surat Jalan Ambil di Toko tidak perlu booking kurir.');
        }
        if ($delivery->isBooked()) {
            return $this->fail($delivery, 'sudah punya resi: ' . $delivery->tracking_number);
        }
        if (!$this->biteship->isReady()) {
            return $this->fail($delivery, 'Biteship belum aktif. Aktifkan di Settings → Integrasi → Biteship.');
        }

        $src = $delivery->order ?: $delivery->invoice;
        $courierCode = $src->shipping_courier_code ?? null;
        if (!$courierCode) {
            return $this->fail($delivery, 'Kurir belum dipilih di SO. Pilih kurir lewat Cek Ongkir di Sales Order dulu.');
        }

        $serviceCode = $src->shipping_service_code ?: $this->biteship->serviceCodeFor($courierCode, $src->shipping_service_name);
        if (!$serviceCode) {
            return $this->fail($delivery, 'Kode layanan kurir tidak dikenali. Pilih ulang kurir lewat Cek Ongkir di SO.');
        }
        if (empty($src->shipping_service_code)) {
            $src->update(['shipping_service_code' => $serviceCode]);
        }

        $warehouse = \App\Core\Inventory\Warehouse::find($delivery->warehouse_id);
        $profile   = \App\Models\BusinessProfile::instance();
        $customer  = $delivery->order?->customer ?: $delivery->invoice?->customer;

        if (!$warehouse || (empty($warehouse->biteship_area_id) && empty($warehouse->postal_code))) {
            return $this->fail($delivery, 'Gudang asal belum punya area/kode pos.');
        }
        if (!$customer || (empty($customer->biteship_area_id) && empty($customer->postal_code))) {
            return $this->fail($delivery, 'Alamat tujuan customer belum punya area/kode pos.');
        }
        $destPhone = $customer->recipient_phone ?: $customer->phone;
        if (empty($destPhone)) {
            return $this->fail($delivery, 'No. HP penerima kosong.');
        }

        $originPhone = $warehouse->contact_phone ?: ($profile->phone ?: $profile->whatsapp);
        if (empty($originPhone)) {
            return $this->fail($delivery, "No. HP kontak gudang \"{$warehouse->name}\" kosong.");
        }
        $originName = $warehouse->contact_name ?: $profile->name;

        // Item paket: berat & dimensi per-produk (default).
        $items = [];
        foreach ($delivery->items as $it) {
            $p = $it->product;
            if (!$p) continue;
            if (in_array($p->sale_type, ['service', 'non_stock'], true)) continue;

            $item = [
                'name'        => $p->name,
                'description' => (string) ($p->sku ?? ''),
                'value'       => 0,
                'quantity'    => max(1, (int) ceil((float) $it->qty)),
                'weight'      => max(1, (int) ($p->weight_gram ?? 0)),
            ];
            if ((float) ($p->height_cm ?? 0) > 0) $item['height'] = (float) $p->height_cm;
            if ((float) ($p->length_cm ?? 0) > 0) $item['length'] = (float) $p->length_cm;
            if ((float) ($p->width_cm ?? 0)  > 0) $item['width']  = (float) $p->width_cm;

            $items[] = $item;
        }
        if (empty($items)) {
            return $this->fail($delivery, 'Tidak ada item fisik untuk dikirim.');
        }

        // Override berat/dimensi (popup) > dimensi paket SO/Invoice > per-produk.
        $reqWeight = (int) clean_number($overrides['weight_gram'] ?? 0);
        $pkgL = isset($overrides['package_length']) && $overrides['package_length'] !== '' ? (float) clean_number($overrides['package_length']) : (float) ($src->package_length ?? 0);
        $pkgW = isset($overrides['package_width'])  && $overrides['package_width']  !== '' ? (float) clean_number($overrides['package_width'])  : (float) ($src->package_width ?? 0);
        $pkgH = isset($overrides['package_height']) && $overrides['package_height'] !== '' ? (float) clean_number($overrides['package_height']) : (float) ($src->package_height ?? 0);

        $totalWeight = 0;
        foreach ($items as $it) {
            $totalWeight += ($it['weight'] ?? 0) * ($it['quantity'] ?? 1);
        }
        $useWeight = $reqWeight > 0 ? $reqWeight : $totalWeight;

        if ($reqWeight > 0 || $pkgL > 0 || $pkgW > 0 || $pkgH > 0) {
            $pkgItem = [
                'name'     => 'Paket ' . $delivery->delivery_number,
                'value'    => 0,
                'quantity' => 1,
                'weight'   => max(1, (int) $useWeight),
            ];
            if ($pkgL > 0) $pkgItem['length'] = $pkgL;
            if ($pkgW > 0) $pkgItem['width']  = $pkgW;
            if ($pkgH > 0) $pkgItem['height'] = $pkgH;
            $items = [$pkgItem];
        }

        $collection = in_array($collectionMethod, ['pickup', 'dropoff'], true)
            ? $collectionMethod
            : (in_array(\App\Models\ShippingSetting::for('biteship')->config['courier_collection'][$courierCode] ?? 'pickup', ['pickup', 'dropoff'], true)
                ? (\App\Models\ShippingSetting::for('biteship')->config['courier_collection'][$courierCode] ?? 'pickup')
                : 'pickup');

        $payload = array_filter([
            'origin_contact_name'  => $originName,
            'origin_contact_phone' => $originPhone,
            'origin_address'       => $warehouse->address ?: $profile->address,
            'origin_postal_code'   => $warehouse->postal_code ?: null,
            'origin_area_id'       => $warehouse->biteship_area_id ?: null,

            'destination_contact_name'  => $customer->name,
            'destination_contact_phone' => $destPhone,
            'destination_address'       => $customer->shipping_address ?: $customer->address,
            'destination_postal_code'   => $customer->postal_code ?: null,
            'destination_area_id'       => $customer->biteship_area_id ?: null,

            'origin_coordinate'      => ($warehouse->latitude !== null && $warehouse->longitude !== null)
                ? ['latitude' => (float) $warehouse->latitude, 'longitude' => (float) $warehouse->longitude] : null,
            'destination_coordinate' => ($customer->latitude !== null && $customer->longitude !== null)
                ? ['latitude' => (float) $customer->latitude, 'longitude' => (float) $customer->longitude] : null,

            'courier_company' => $courierCode,
            'courier_type'    => $serviceCode,
            'delivery_type'   => 'now',
            'order_note'      => 'SJ ' . $delivery->delivery_number . ' · ' . ($collection === 'dropoff' ? 'Drop off' : 'Pickup'),
            'metadata'        => ['delivery_number' => $delivery->delivery_number, 'collection_method' => $collection],
            'items'           => $items,
        ], fn ($v) => $v !== null && $v !== '');

        $result = $this->biteship->createOrder($payload);

        if (!$result['success']) {
            $delivery->update(['shipping_status' => 'failed', 'shipping_raw' => $result['raw'] ?? null]);
            return $this->fail($delivery, 'booking gagal: ' . ($result['error'] ?? 'tidak diketahui'));
        }

        $actualCost = (float) ($result['raw']['price'] ?? $src->shipping_gross ?? 0);

        $delivery->update([
            'shipping_provider'     => 'biteship',
            'shipping_courier_code' => $courierCode,
            'shipping_service_code' => $serviceCode,
            'provider_order_id'     => $result['order_id'],
            'tracking_number'       => $result['tracking_id'] ?: $delivery->tracking_number,
            'courier_name'          => strtoupper($courierCode) . ' ' . ($src->shipping_service_name ?? $serviceCode),
            'collection_method'     => $collection,
            'shipping_status'       => 'booked',
            'shipping_cost'         => $actualCost,
            'shipping_raw'          => $result['raw'] ?? null,
        ]);

        $tracking = $result['tracking_id'] ?: $delivery->tracking_number;

        // Fase 5 — jurnal ongkir. Gagal jurnal ≠ gagal booking (resi sudah terbit).
        try {
            app(\App\Modules\Shipping\Services\ShippingAccountingService::class)->postBooking($delivery->fresh('order'));
        } catch (\Throwable $e) {
            return [
                'success'  => true,
                'level'    => 'warning',
                'tracking' => $tracking,
                'message'  => "{$delivery->delivery_number}: resi terbuat ({$tracking}) tetapi jurnal ongkir gagal: {$e->getMessage()}",
            ];
        }

        return [
            'success'  => true,
            'level'    => 'success',
            'tracking' => $tracking,
            'message'  => "{$delivery->delivery_number}: resi {$tracking}",
        ];
    }

    private function fail(SalesDelivery $delivery, string $reason): array
    {
        return [
            'success'  => false,
            'level'    => 'error',
            'tracking' => null,
            'message'  => "{$delivery->delivery_number}: {$reason}",
        ];
    }
}
