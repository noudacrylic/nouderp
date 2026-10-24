<?php

namespace App\Modules\KaryawanVirtual\Skills\Produksi;

use App\Modules\KaryawanVirtual\Contracts\BisaDiusulkan;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Services\AutoProductionService;

/**
 * Buat OP untuk produk ready yang stoknya menipis/habis. Pembungkus
 * AutoProductionService — syarat buat/lewati sepenuhnya milik service itu
 * (BOM auto aktif, stok tersedia ≤ min, belum ada produksi aktif, dst.).
 *
 * Mode "Minta persetujuan": satu usulan per BOM; saat disetujui BOM itu
 * diperiksa ulang lewat runForBom, jadi persetujuan yang telat tidak membuat
 * OP yang sudah tak diperlukan.
 */
class OpStokMenipis implements BisaDiusulkan
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
        return [self::NONAKTIF, self::BAYANGAN, self::PERSETUJUAN, self::OTOMATIS];
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

    public function usulan(array $hasil): array
    {
        $usulan = [];
        foreach ($hasil as $i => $r) {
            if (!($r['simulasi'] ?? false)) continue;
            $usulan[$i] = [
                'kunci'  => 'bom:' . $r['bom_id'],
                'judul'  => 'Buat OP ' . trim(($r['bom_number'] ?? '') . ' — ' . ($r['bom_name'] ?? ''), ' —'),
                'alasan' => $r['reason'] ?? null,
                'data'   => ['bom_id' => $r['bom_id']],
            ];
        }
        return $usulan;
    }

    /** runForBom memeriksa ulang semua syarat (stok, produksi aktif, BOM auto) sebelum membuat OP. */
    public function kerjakanUsulan(array $data): array
    {
        $bom = Bom::find($data['bom_id'] ?? 0);
        if (!$bom) {
            return [[
                'bom_id' => $data['bom_id'] ?? null, 'bom_number' => null, 'bom_name' => 'BOM #' . ($data['bom_id'] ?? '?'),
                'created' => false, 'order_number' => null, 'simulasi' => false, 'reason' => 'BOM sudah dihapus.',
            ]];
        }
        return [$this->service->runForBom($bom)];
    }

    public function masaBerlakuJam(): int
    {
        return 24;
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
