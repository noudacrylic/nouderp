<?php

namespace App\Modules\KaryawanVirtual\Skills\Produksi;

use App\Modules\KaryawanVirtual\Contracts\Skill;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Services\AutoProductionService;

/**
 * Buat OP untuk produk ready yang stoknya menipis/habis. Pembungkus
 * AutoProductionService — syarat buat/lewati sepenuhnya milik service itu
 * (BOM auto aktif, stok tersedia ≤ min, belum ada produksi aktif, dst.).
 */
class OpStokMenipis implements Skill
{
    public function __construct(private AutoProductionService $service) {}

    public function label(): string
    {
        return 'Buat OP saat stok menipis';
    }

    public function deskripsi(): string
    {
        return 'Memeriksa semua BOM yang auto-produksinya aktif. Bila stok tersedia produk utamanya '
            . 'habis atau di bawah stok minimum dan belum ada produksi berjalan, membuat order produksi '
            . 'lalu mengonfirmasinya.';
    }

    public function jadwal(): string
    {
        return 'Tiap 30 menit, dan tombol "Jalankan Auto Produksi" di halaman BOM';
    }

    public function satuan(): string
    {
        return 'BOM';
    }

    public function modeTersedia(): array
    {
        return [self::NONAKTIF, self::BAYANGAN, self::OTOMATIS];
    }

    public function modeBawaan(): string
    {
        return self::OTOMATIS;
    }

    public function alasanTerkunci(): ?string
    {
        return null;
    }

    public function jalankan(bool $simulasi): array
    {
        return $this->service->runAll($simulasi);
    }

    public function rincian(array $hasil): array
    {
        $opIds = ProductionOrder::whereIn('order_number', collect($hasil)->pluck('order_number')->filter())
            ->pluck('id', 'order_number');

        return array_map(function (array $r) use ($opIds) {
            $status = match (true) {
                (bool) ($r['created'] ?? false)  => self::DIBUAT,
                (bool) ($r['simulasi'] ?? false) => self::AKAN_DIBUAT,
                str_starts_with((string) $r['reason'], 'Gagal') => self::GAGAL,
                default => self::DILEWATI,
            };
            $nomor = $r['order_number'] ?? null;

            return [
                'status'  => $status,
                'judul'   => trim(($r['bom_number'] ?? '') . ' — ' . ($r['bom_name'] ?? ''), ' —'),
                'alasan'  => $r['reason'] ?? null,
                'dokumen' => $nomor,
                'tautan'  => $nomor && isset($opIds[$nomor]) ? route('production.orders.show', $opIds[$nomor]) : null,
            ];
        }, $hasil);
    }
}
