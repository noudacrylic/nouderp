<?php

namespace App\Modules\KaryawanVirtual\Skills\Produksi;

use App\Modules\KaryawanVirtual\Contracts\BisaDiusulkan;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Services\OpAkrilikSignService;
use App\Modules\Tasks\MejaKerja\BebanAdmin;

/**
 * OP stok Akrilik Sign (AS-*). Pembungkus OpAkrilikSignService — pemicu, jalur cetak/tempel dan
 * OP pengisi milik service itu. Usulan per SKU (kunci "as:<sku>"); saat disetujui keadaan admin &
 * alat tempel dihitung ulang, jadi jalurnya mengikuti keadaan saat itu.
 */
class OpAkrilikSign implements BisaDiusulkan
{
    public const KEY = OpAkrilikSignService::SKILL;

    public function __construct(private OpAkrilikSignService $service) {}

    public function label(): string
    {
        return 'Buat OP Akrilik Sign (cetak & tempel)';
    }

    public function deskripsi(): string
    {
        return 'Akrilik Sign (SKU ' . OpAkrilikSignService::PREFIX . '*, BOM tanpa auto-produksi, berlangkah Desain) tersedia ≤ min stok & '
            . 'belum ada OP → OP sejumlah "Jumlah Siklus Biasa" BOM. Admin Desain LONGGAR (sudah absen, ≤' . BebanAdmin::ANTRE_MAKS
            . ' kartu antre, tak ada yang lewat tenggat, sisa jam kerja ≥' . BebanAdmin::SISA_MENIT_MIN . ' mnt) dan belum memegang '
            . 'langkah tempel → Admin cetak & tempel; selain itu Admin cetak, Assembling menempel (alat tempel cuma satu). '
            . 'Tak ada yang menipis + Admin Desain NGANGGUR (antre kosong) + alat kosong → 1 OP pengisi Prioritas Rendah untuk '
            . 'SKU terlaris ' . OpAkrilikSignService::HARI_LARIS . ' hari yang stoknya < min + sekali produksi.';
    }

    public function jadwal(): string
    {
        return 'Tiap 30 menit (bersama auto produksi)';
    }

    public function satuan(): string
    {
        return 'pemeriksaan';
    }

    public function modeTersedia(): array
    {
        return [self::NONAKTIF, self::BAYANGAN, self::PERSETUJUAN, self::OTOMATIS];
    }

    /** Disepakati owner: Bayangan dulu, cek jurnalnya, baru Otomatis. */
    public function modeBawaan(): string
    {
        return self::BAYANGAN;
    }

    public function alasanTerkunci(): ?string
    {
        return null;
    }

    public function jalankan(bool $simulasi): array
    {
        return $this->service->periksa($simulasi);
    }

    public function usulan(array $hasil): array
    {
        $usulan = [];
        foreach ($hasil as $i => $r) {
            if (!($r['simulasi'] ?? false) || !$r['sku'] || $r['jenis'] !== 'akan') continue;
            $usulan[$i] = [
                'kunci'  => 'as:' . $r['sku'],
                'judul'  => "OP Akrilik Sign {$r['sku']}",
                'alasan' => $r['reason'],
                'data'   => ['sku' => $r['sku']],
            ];
        }
        return $usulan;
    }

    public function kerjakanUsulan(array $data): array
    {
        return $this->service->periksa(false, $data['sku'] ?? null);
    }

    public function masaBerlakuJam(): int
    {
        return 8;
    }

    public function rincian(array $hasil): array
    {
        $opIds = ProductionOrder::whereIn('order_number', collect($hasil)->pluck('order_number')->filter())
            ->pluck('id', 'order_number');

        return array_map(function (array $r) use ($opIds) {
            $status = match ($r['jenis']) {
                'dibuat' => self::DIBUAT,
                'akan'   => self::AKAN_DIBUAT,
                'diubah' => self::DIUBAH,
                'gagal'  => self::GAGAL,
                default  => self::DILEWATI,
            };
            $nomor = $r['order_number'];

            return [
                'status'  => $status,
                'judul'   => 'Akrilik Sign' . ($r['sku'] ? " {$r['sku']}" : ''),
                'alasan'  => $r['reason'],
                'dokumen' => $nomor,
                'tautan'  => $nomor && isset($opIds[$nomor]) ? route('production.orders.show', $opIds[$nomor]) : null,
            ];
        }, $hasil);
    }
}
