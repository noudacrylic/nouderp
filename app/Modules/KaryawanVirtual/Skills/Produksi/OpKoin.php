<?php

namespace App\Modules\KaryawanVirtual\Skills\Produksi;

use App\Modules\KaryawanVirtual\Contracts\BisaDiusulkan;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Services\OpKoinService;

/**
 * OP koin kotak saran (KOIN-TPM/PH/PK/SPH dari satu BOM). Pembungkus OpKoinService —
 * pemicu, jumlah lembar besar dan pembagian lembar per koin milik service itu.
 *
 * Mode "Minta persetujuan": satu usulan (kunci tetap 'koin'); saat disetujui stok koin
 * dihitung ulang, jadi pembagian lembar mengikuti keadaan saat itu.
 */
class OpKoin implements BisaDiusulkan
{
    public const KEY = OpKoinService::SKILL;

    public function __construct(private OpKoinService $service) {}

    public function label(): string
    {
        return 'Buat OP koin kotak saran';
    }

    public function deskripsi(): string
    {
        return 'Bila koin mana pun (TPM, PH, PK, SPH) tersedia ≤ min stok dan belum ada OP koin berjalan, membuat '
            . 'OP dari BOM koin: 1 lembar akrilik besar = 20 lembar kecil × ' . OpKoinService::KOIN_PER_LEMBAR . ' koin. '
            . 'Lembar kecil dibagi supaya sesudah produksi stok TPM ≈ 3× stok tiap koin lain dan PH/PK/SPH hampir sama; '
            . 'ditambah lembar besar bila 1 lembar belum mengangkat semua koin di atas min stok.';
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

    /** Skill baru dalam percobaan: OP koin minta persetujuan dulu. */
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
        return [$this->service->periksa($simulasi)];
    }

    public function usulan(array $hasil): array
    {
        $usulan = [];
        foreach ($hasil as $i => $r) {
            if (!($r['simulasi'] ?? false)) continue;
            $usulan[$i] = [
                'kunci'  => 'koin',
                'judul'  => "OP koin {$r['siklus']} lembar besar",
                'alasan' => $r['reason'],
                'data'   => [],
            ];
        }
        return $usulan;
    }

    public function kerjakanUsulan(array $data): array
    {
        return [$this->service->periksa(false)];
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
                (bool) $r['created']  => self::DIBUAT,
                (bool) $r['simulasi'] => self::AKAN_DIBUAT,
                str_starts_with((string) $r['reason'], 'Gagal') => self::GAGAL,
                default => self::DILEWATI,
            };
            $nomor = $r['order_number'];

            return [
                'status'  => $status,
                'judul'   => 'Koin kotak saran' . ($r['siklus'] ? " — {$r['siklus']} lembar besar" : ''),
                'alasan'  => $r['reason'],
                'dokumen' => $nomor,
                'tautan'  => $nomor && isset($opIds[$nomor]) ? route('production.orders.show', $opIds[$nomor]) : null,
            ];
        }, $hasil);
    }
}
