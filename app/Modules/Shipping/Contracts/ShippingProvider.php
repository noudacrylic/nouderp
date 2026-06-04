<?php

namespace App\Modules\Shipping\Contracts;

/**
 * Kontrak adapter kurir (Biteship, Kirimin Aja, dst).
 * Setiap provider mengubah payload internal kita ke format API masing-masing,
 * dan menormalkan response balik ke bentuk seragam.
 */
interface ShippingProvider
{
    /** Kode provider, mis. 'biteship'. */
    public function key(): string;

    /** Provider aktif & terkonfigurasi (API key terisi). */
    public function isReady(): bool;

    /**
     * Cek ongkir.
     *
     * @param array $payload [
     *   'origin_postal_code' => int|string, 'destination_postal_code' => int|string,
     *   'origin_area_id' => ?string, 'destination_area_id' => ?string,
     *   'couriers' => ?string (csv, kosong = semua aktif),
     *   'items' => [['name','value','weight','quantity'], ...],
     * ]
     * @return array{success:bool, rates:array<array{provider,courier_code,courier_name,service_code,service_name,price,etd,raw}>, error:?string}
     */
    public function rates(array $payload): array;

    /**
     * Cari area (untuk mapping alamat → area_id).
     * @return array{success:bool, areas:array<array{id,name,postal_code,raw}>, error:?string}
     */
    public function searchAreas(string $query): array;

    /**
     * Booking pengiriman → nomor resi. (Fase 3)
     * @return array{success:bool, order_id:?string, tracking_id:?string, raw:array, error:?string}
     */
    public function createOrder(array $payload): array;

    /** Tracking status. (Fase 4) */
    public function track(string $orderId): array;

    /** Batalkan booking. */
    public function cancel(string $orderId): array;
}
