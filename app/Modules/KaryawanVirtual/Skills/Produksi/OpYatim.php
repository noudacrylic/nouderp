<?php

namespace App\Modules\KaryawanVirtual\Skills\Produksi;

use App\Modules\KaryawanVirtual\Contracts\Skill;
use App\Modules\Production\Services\OpYatimService;

/**
 * Pesanan pre-order umum dibatalkan di tengah jalan. Pembungkus OpYatimService:
 * OP yang masih antre dibatalkan; yang sudah dikerjakan dilepas jadi yatim (Prioritas
 * Rendah) sampai pesanan baru yang kekurangan barang mengambil alih.
 *
 * Tanpa mode persetujuan: tindakannya hanya pemilik & skor antrean OP (bahan dan uang
 * tak tersentuh — pembatalan OP antre memakai guard ProductionOrderService::cancel yang
 * juga dipakai void SO), dan user memutuskan aturan ini selalu berlaku.
 */
class OpYatim implements Skill
{
    public const KEY = OpYatimService::SKILL;

    public function __construct(private OpYatimService $service) {}

    public function label(): string
    {
        return 'Urus OP pre-order yang pesanannya batal';
    }

    public function deskripsi(): string
    {
        return 'Untuk pre-order umum (bukan produk dibuat khusus seperti CS): bila pesanan dibatalkan dan OP-nya '
            . 'masih antre, OP dibatalkan. Bila OP sudah dikerjakan (dipotong / di Assembling), OP dilepas dari '
            . 'pesanan — tanpa pemilik, skor Prioritas Rendah. Begitu ada pesanan baru produk yang sama yang '
            . 'kekurangan barang (stok rak didahulukan, pesanan ber-DP paling lama dulu), pesanan itu jadi pemilik '
            . 'OP dan skornya kembali Otomatis. Bila tetap dikerjakan tanpa pemilik, hasilnya jadi stok biasa.';
    }

    public function jadwal(): string
    {
        return 'Tiap 30 menit (bersama auto produksi)';
    }

    public function satuan(): string
    {
        return 'OP';
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
        return $this->service->rapikan($simulasi);
    }

    public function rincian(array $hasil): array
    {
        return array_map(function (array $r) {
            $status = match (true) {
                $r['jenis'] === 'gagal'    => self::GAGAL,
                $r['jenis'] === 'dilewati' => self::DILEWATI,
                $r['simulasi']             => self::AKAN_DIBUAT,
                default                    => self::DIUBAH,
            };
            $label = match ($r['jenis']) {
                'batal' => 'Batal',
                'lepas' => 'Dilepas',
                'ambil' => 'Diambil alih',
                default => null,
            };

            return [
                'status'  => $status,
                'judul'   => trim(($label ? "{$label}: " : '') . $r['order_number'] . ' — ' . $r['sku'] . ' ' . $r['nama']),
                'alasan'  => $r['reason'],
                'dokumen' => $r['order_number'],
                'tautan'  => route('production.orders.show', $r['production_order_id']),
            ];
        }, $hasil);
    }
}
