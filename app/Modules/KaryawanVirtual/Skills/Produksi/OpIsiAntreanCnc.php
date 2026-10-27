<?php

namespace App\Modules\KaryawanVirtual\Skills\Produksi;

use App\Core\Inventory\Product;
use App\Modules\KaryawanVirtual\Contracts\BisaDiusulkan;
use App\Modules\KaryawanVirtual\Contracts\PunyaPengaturan;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Services\IsiAntreanCncService as Svc;

/**
 * Isi antrean CNC yang hampir habis — 3 tingkatan berjenjang (andalan → hampir menipis →
 * stok cadangan). Pembungkus IsiAntreanCncService; semua syarat milik service itu.
 *
 * Mode "Minta persetujuan": satu usulan per (tingkat, produk); saat disetujui antrean
 * & syarat tingkatnya diperiksa ulang. Penyesuaian OP yang sudah ada (naikkan skor,
 * alihkan hasil cadangan ke gudang jual) tidak diusulkan — langsung, kecuali Bayangan.
 */
class OpIsiAntreanCnc implements BisaDiusulkan, PunyaPengaturan
{
    public const KEY = Svc::SKILL;

    public function __construct(private Svc $service) {}

    public function label(): string
    {
        return 'Isi antrean CNC yang hampir habis';
    }

    public function urlPengaturan(string $kode): string
    {
        return route('karyawan-virtual.cnc.pengaturan', $kode);
    }

    public function deskripsi(): string
    {
        return 'Di jam kerja, bila antrean CNC sebuah kelompok mesin tinggal ' . Svc::SISA_ANTREAN . ' pekerjaan atau kurang, '
            . 'membuat OP untuk kelompok itu: Mesin 1 (double head) ' . Svc::OP_PER_KELOMPOK[Svc::GANDA] . ' OP, '
            . 'Mesin 2 & 3 ' . Svc::OP_PER_KELOMPOK[Svc::TUNGGAL] . ' OP (satu per mesin). Calon dipilih berjenjang, produk berbeda per OP. '
            . 'Tingkat 1: produk andalan walau stok masih banyak — maks ' . Svc::MAKS_OP_ANDALAN
            . ' OP, OP kedua Prioritas Rendah lalu Otomatis setelah OP pertama selesai '
            . '(OP manual Prioritas Rendah ikut dinaikkan dengan aturan yang sama). '
            . 'Tingkat 2: stok di atas minimum tapi cukup ≤ ' . Svc::T2_MAKS_HARI . ' hari penjualan. '
            . 'Tingkat 3: ' . Svc::T3_POLA_SKU . '* laris yang stok jualnya cukup ≥ ' . Svc::T3_MIN_HARI_JUAL
            . ' hari → hasil ke gudang Cadangan (sampai ' . Svc::T3_CADANGAN_HARI . ' hari penjualan); bila stok jual '
            . 'keburu diborong, hasilnya dialihkan ke gudang jual. Bahan harus cukup. '
            . 'Produk dengan BOM alternatif (Urutan pakai) memakai urutan terkecil yang bahannya cukup.';
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
        $sesuaikan = !$simulasi || app(KaryawanVirtualService::class)->mode(self::KEY) === self::PERSETUJUAN;

        return $this->service->jalankan($simulasi, $sesuaikan);
    }

    public function usulan(array $hasil): array
    {
        $usulan = [];
        foreach ($hasil as $i => $r) {
            if ($r['jenis'] !== 'buat' || !$r['simulasi']) continue;
            $usulan[$i] = [
                'kunci'  => "cnc:{$r['tingkat']}:{$r['product_id']}",
                'judul'  => "Isi antrean CNC " . Svc::labelKelompok($r['kelompok'] ?? Svc::TUNGGAL) . " (Tingkat {$r['tingkat']}): OP {$r['sku']} — {$r['nama']}",
                'alasan' => $r['reason'],
                'data'   => ['product_id' => $r['product_id'], 'tingkat' => $r['tingkat']],
            ];
        }
        return $usulan;
    }

    public function kerjakanUsulan(array $data): array
    {
        $p = Product::find($data['product_id'] ?? 0);
        $t = (int) ($data['tingkat'] ?? 0);
        if (!$p || !in_array($t, [1, 2, 3], true)) {
            return [[
                'jenis' => 'buat', 'tingkat' => $t, 'product_id' => $data['product_id'] ?? null, 'sku' => 'produk #' . ($data['product_id'] ?? '?'),
                'nama' => '', 'created' => false, 'simulasi' => false, 'diubah' => false, 'order_number' => null, 'reason' => 'Produk sudah dihapus.',
            ]];
        }
        return [$this->service->kerjakanUntuk($p, $t)];
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
                $r['diubah']   => self::DIUBAH,
                $r['created']  => self::DIBUAT,
                $r['simulasi'] => self::AKAN_DIBUAT,
                str_starts_with((string) $r['reason'], 'Gagal') => self::GAGAL,
                default => self::DILEWATI,
            };
            $nomor = $r['order_number'];

            return [
                'status'  => $status,
                'judul'   => trim($r['sku'] . ' — ' . $r['nama'], ' —') . ($nomor && $status === self::DIUBAH ? " ({$nomor})" : ''),
                'alasan'  => $r['reason'],
                'dokumen' => $nomor,
                'tautan'  => $nomor && isset($opIds[$nomor]) ? route('production.orders.show', $opIds[$nomor]) : null,
            ];
        }, $hasil);
    }
}
