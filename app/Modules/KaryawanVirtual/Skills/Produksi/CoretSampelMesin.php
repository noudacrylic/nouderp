<?php

namespace App\Modules\KaryawanVirtual\Skills\Produksi;

use App\Modules\Analysis\Services\SampelMesinService;
use App\Modules\KaryawanVirtual\Contracts\Skill;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;

/**
 * Coret sampel Waktu Produksi yang CNC-nya dikerjakan di mesin lain kelompok
 * (Mesin 1 double head vs Mesin 2 & 3 single head). Pembungkus SampelMesinService.
 *
 * Tanpa mode persetujuan: hanya menyaring data analisa (tidak membuat dokumen / uang),
 * dan user memutuskan sampel seperti ini selalu dicoret.
 */
class CoretSampelMesin implements Skill
{
    public const KEY = 'produksi.coret-sampel-mesin';

    public function __construct(private SampelMesinService $service) {}

    public function label(): string
    {
        return 'Coret sampel waktu yang mesinnya tak sesuai';
    }

    public function deskripsi(): string
    {
        return 'Mencari OP selesai yang langkah CNC-nya dikerjakan di mesin lain kelompok — produk Mesin 1 (double head) '
            . 'di Mesin 2/3 atau sebaliknya — lalu mencoretnya dari sampel Analisa › Waktu Produksi beserta alasannya, '
            . 'karena kecepatan double head dan single head jauh berbeda. Sampel yang dicentang ulang akan dicoret lagi.';
    }

    public function jadwal(): string
    {
        return 'Tiap 30 menit (bersama auto produksi)';
    }

    public function satuan(): string
    {
        return 'sampel';
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
        $kv = app(KaryawanVirtualService::class);

        return $this->service->coret($simulasi, $kv->akun($kv->pemilik(self::KEY))->id);
    }

    public function rincian(array $hasil): array
    {
        return array_map(fn (array $r) => [
            'status'  => $r['dicoret'] ? self::DIUBAH : self::AKAN_DIBUAT,
            'judul'   => trim($r['order_number'] . ' — ' . ($r['sku'] ?? '') . ' ' . ($r['nama'] ?? '')),
            'alasan'  => $r['reason'],
            'dokumen' => $r['sku'] ? 'Waktu Produksi ' . $r['sku'] : null,
            'tautan'  => $r['product_id'] ? route('analisa.waktu-produksi.show', $r['product_id']) : null,
        ], $hasil);
    }
}
