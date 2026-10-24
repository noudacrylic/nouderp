<?php

namespace App\Modules\KaryawanVirtual\Skills\Produksi;

use App\Core\Inventory\Product;
use App\Modules\KaryawanVirtual\Contracts\Skill;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Services\PreorderAutoProductionService;

/**
 * Pastikan tiap pesanan pre-order yang DP-nya masuk punya OP. Pembungkus
 * PreorderAutoProductionService: pemicu DP (SalesAdvanceObserver) dan Ganti
 * Pesanan tetap memanggil service itu langsung lalu mencatat ke jurnal; jadwal
 * & tombol manual menjalankan sapuannya lewat skill ini.
 *
 * Mode TERKUNCI otomatis: ini pesanan pelanggan yang sudah membayar DP —
 * mematikannya berarti pesanan menggantung tanpa produksi.
 */
class OpPreorder implements Skill
{
    public function __construct(private PreorderAutoProductionService $service) {}

    public function label(): string
    {
        return 'Buat OP untuk pesanan pre-order';
    }

    public function deskripsi(): string
    {
        return 'Begitu DP pesanan pre-order diposting, membuat order produksi sebesar kebutuhan yang belum '
            . 'tertutup stok atau produksi berjalan. Sapuan berkala memeriksa ulang pesanan yang masih menunggu, '
            . 'misalnya bila stok yang tadinya menutupi ternyata hilang.';
    }

    public function jadwal(): string
    {
        return 'Saat DP diposting / Ganti Pesanan, sapuan tiap 30 menit, dan tombol "Jalankan Auto Produksi"';
    }

    public function satuan(): string
    {
        return 'baris pesanan';
    }

    public function modeTersedia(): array
    {
        return [self::OTOMATIS];
    }

    public function modeBawaan(): string
    {
        return self::OTOMATIS;
    }

    public function alasanTerkunci(): ?string
    {
        return 'Selalu otomatis: pesanan pelanggan yang sudah DP tidak boleh menunggu persetujuan.';
    }

    public function jalankan(bool $simulasi): array
    {
        return $this->service->sweepPendingSalesOrders();
    }

    public function rincian(array $hasil): array
    {
        // Baris non-pre-order di pesanan campuran bukan urusan skill ini — buang dari jurnal.
        $hasil = array_values(array_filter($hasil, fn ($r) => !str_contains((string) ($r['reason'] ?? ''), 'Bukan produk preorder')));

        $sku   = Product::whereIn('id', collect($hasil)->pluck('product_id')->filter())->pluck('sku', 'id');
        $opIds = ProductionOrder::whereIn('order_number', collect($hasil)->pluck('order_number')->filter())
            ->pluck('id', 'order_number');

        return array_map(function (array $r) use ($sku, $opIds) {
            $status = match (true) {
                (bool) ($r['created'] ?? false) => self::DIBUAT,
                str_starts_with((string) $r['reason'], 'Gagal') => self::GAGAL,
                default => self::DILEWATI,
            };
            $nomor = $r['order_number'] ?? null;

            return [
                'status'  => $status,
                'judul'   => trim(($r['sales_order_number'] ?? ('SO #' . $r['sales_order_id']))
                    . ' · ' . ($sku[$r['product_id']] ?? 'produk #' . $r['product_id'])),
                'alasan'  => $r['reason'] ?? null,
                'dokumen' => $nomor,
                'tautan'  => $nomor && isset($opIds[$nomor]) ? route('production.orders.show', $opIds[$nomor]) : null,
            ];
        }, $hasil);
    }
}
