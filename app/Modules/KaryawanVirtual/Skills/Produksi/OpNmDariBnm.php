<?php

namespace App\Modules\KaryawanVirtual\Skills\Produksi;

use App\Core\Inventory\Product;
use App\Modules\KaryawanVirtual\Contracts\BisaDiusulkan;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Services\NmDariBnmService;

/**
 * Buat OP Nama Meja (NM) dari BNM saat stok NM menipis. Pembungkus
 * NmDariBnmService — aturan jumlah (150 / 50–149 / <50 + potong lembar) milik service.
 *
 * Mode "Minta persetujuan": satu usulan per NM; saat disetujui NM itu diperiksa
 * ulang lewat runForNm (stok NM, OP aktif, dan stok BNM terbaru).
 */
class OpNmDariBnm implements BisaDiusulkan
{
    public function __construct(private NmDariBnmService $service) {}

    public function label(): string
    {
        return 'Buat OP Nama Meja dari BNM';
    }

    public function deskripsi(): string
    {
        return 'Bila stok tersedia produk NM habis atau di bawah stok minimum dan belum ada produksi berjalan, '
            . 'membuat OP dari bahan BNM pasangannya: BNM ≥ 150 → 150 pcs; BNM 50–149 → sebanyak BNM; '
            . 'BNM < 50 → seluruh BNM ditambah potong lembar dari BOM NM, siklusnya dipilih agar total paling dekat ke 150. '
            . 'Skor antrean otomatis.';
    }

    public function jadwal(): string
    {
        return 'Tiap 30 menit (bersama auto produksi)';
    }

    public function satuan(): string
    {
        return 'produk NM';
    }

    public function modeTersedia(): array
    {
        return [self::NONAKTIF, self::BAYANGAN, self::PERSETUJUAN, self::OTOMATIS];
    }

    /** Skill baru dalam percobaan: tiap OP minta persetujuan dulu. */
    public function modeBawaan(): string
    {
        return self::PERSETUJUAN;
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
                'kunci'  => 'nm:' . $r['product_id'],
                'judul'  => "Buat OP {$r['sku']} — {$r['nama']}",
                'alasan' => $r['reason'] ?? null,
                'data'   => ['product_id' => $r['product_id']],
            ];
        }
        return $usulan;
    }

    /** runForNm memeriksa ulang stok NM, OP aktif, dan menghitung ulang dari stok BNM terbaru. */
    public function kerjakanUsulan(array $data): array
    {
        $nm = Product::find($data['product_id'] ?? 0);
        if (!$nm) {
            return [[
                'product_id' => $data['product_id'] ?? null, 'sku' => 'produk #' . ($data['product_id'] ?? '?'), 'nama' => '',
                'created' => false, 'simulasi' => false, 'order_number' => null, 'reason' => 'Produk sudah dihapus.',
            ]];
        }
        return [$this->service->runForNm($nm)];
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
                'judul'   => trim($r['sku'] . ' — ' . $r['nama'], ' —'),
                'alasan'  => $r['reason'] ?? null,
                'dokumen' => $nomor,
                'tautan'  => $nomor && isset($opIds[$nomor]) ? route('production.orders.show', $opIds[$nomor]) : null,
            ];
        }, $hasil);
    }
}
