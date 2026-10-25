<?php

namespace App\Modules\KaryawanVirtual\Skills\Produksi;

use App\Core\Inventory\Product;
use App\Modules\KaryawanVirtual\Contracts\BisaDiusulkan;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Services\NmDariBnmService;

/**
 * Isi antrean Assembling yang kosong dengan merakit BNM → NM. Pembungkus
 * NmDariBnmService::isiAntreanRakit — syarat (jam kerja, antrean kosong, BNM ≥ 10,
 * urutan stok NM terendah) dan penaikan skor OP pengisi milik service itu.
 *
 * Mode "Minta persetujuan": satu usulan per NM terpilih; saat disetujui antrean &
 * stok BNM diperiksa ulang. Penaikan skor ke Otomatis (NM menipis) tidak diusulkan —
 * hanya mengubah urutan OP yang sudah disetujui, jadi langsung dikerjakan kecuali Bayangan.
 */
class OpIsiAntreanRakit implements BisaDiusulkan
{
    public const KEY = 'produksi.isi-antrean-rakit';

    public function __construct(private NmDariBnmService $service) {}

    public function label(): string
    {
        return 'Isi antrean Assembling yang kosong';
    }

    public function deskripsi(): string
    {
        return 'Di jam kerja (Senin–Sabtu 08–16), bila divisi Assembling tidak punya pekerjaan di antrean, '
            . 'membuat satu OP rakit BNM → NM untuk NM berstok terendah: 150 pcs bila BNM ≥ 150, selebihnya '
            . 'sebanyak BNM (minimal ' . NmDariBnmService::PENGISI_MIN_BNM . '), tanpa potong lembar. '
            . 'Skor antrean Prioritas Rendah; dinaikkan ke Otomatis bila NM-nya menipis selagi OP masih berjalan.';
    }

    public function jadwal(): string
    {
        return 'Tiap 30 menit di jam kerja (bersama auto produksi)';
    }

    public function satuan(): string
    {
        return 'pemeriksaan';
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
        $naikkanSkor = !$simulasi || app(KaryawanVirtualService::class)->mode(self::KEY) === self::PERSETUJUAN;

        return $this->service->isiAntreanRakit($simulasi, $naikkanSkor);
    }

    public function usulan(array $hasil): array
    {
        $usulan = [];
        foreach ($hasil as $i => $r) {
            if (($r['jenis'] ?? null) !== 'buat' || !($r['simulasi'] ?? false)) continue;
            $usulan[$i] = [
                'kunci'  => 'rakit:' . $r['product_id'],
                'judul'  => "Isi antrean Assembling: OP {$r['sku']} — {$r['nama']}",
                'alasan' => $r['reason'] ?? null,
                'data'   => ['product_id' => $r['product_id']],
            ];
        }
        return $usulan;
    }

    public function kerjakanUsulan(array $data): array
    {
        $nm = Product::find($data['product_id'] ?? 0);
        if (!$nm) {
            return [[
                'jenis' => 'buat', 'product_id' => $data['product_id'] ?? null, 'sku' => 'produk #' . ($data['product_id'] ?? '?'),
                'nama' => '', 'created' => false, 'simulasi' => false, 'order_number' => null, 'reason' => 'Produk sudah dihapus.',
            ]];
        }
        return [$this->service->isiAntreanRakitUntuk($nm)];
    }

    /** Antrean kosong cepat berubah — usulan berlaku sehari kerja. */
    public function masaBerlakuJam(): int
    {
        return 8;
    }

    public function rincian(array $hasil): array
    {
        $opIds = ProductionOrder::whereIn('order_number', collect($hasil)->pluck('order_number')->filter())
            ->pluck('id', 'order_number');

        return array_map(function (array $r) use ($opIds) {
            $status = match (true) {
                (bool) ($r['diubah'] ?? false)   => self::DIUBAH,
                (bool) ($r['created'] ?? false)  => self::DIBUAT,
                (bool) ($r['simulasi'] ?? false) => self::AKAN_DIBUAT,
                str_starts_with((string) $r['reason'], 'Gagal') => self::GAGAL,
                default => self::DILEWATI,
            };
            $nomor = $r['order_number'] ?? null;

            return [
                'status'  => $status,
                'judul'   => trim($r['sku'] . ' — ' . $r['nama'], ' —') . ($nomor && $status === self::DIUBAH ? " ({$nomor})" : ''),
                'alasan'  => $r['reason'] ?? null,
                'dokumen' => $nomor,
                'tautan'  => $nomor && isset($opIds[$nomor]) ? route('production.orders.show', $opIds[$nomor]) : null,
            ];
        }, $hasil);
    }
}
