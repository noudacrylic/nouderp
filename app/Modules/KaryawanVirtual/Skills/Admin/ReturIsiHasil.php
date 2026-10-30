<?php

namespace App\Modules\KaryawanVirtual\Skills\Admin;

use App\Modules\KaryawanVirtual\Contracts\Skill;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeClient;
use App\Modules\Marketplace\Shopee\Services\ShopeeReturLamaService;

/**
 * Manajer Admin membantu admin menulis hasil retur/banding Shopee: begitu returnya final di
 * Shopee dan pesanannya tuntas, draft retur diisi kasus, hasil banding, dan jurnal dana dari
 * rincian dana Shopee. Draft TIDAK diposting — kartu retur di Meja Layanan kembali Antre
 * "periksa & posting". Pembungkus ShopeeReturLamaService::isiHasil.
 *
 * Jadwal sendiri (tiap jam, `shopee:retur-isi-hasil`) — memanggil API Shopee.
 */
class ReturIsiHasil implements Skill
{
    public const KEY = 'admin.retur-isi-hasil';

    public function __construct(private ShopeeReturLamaService $service, private ShopeeClient $client) {}

    public function label(): string
    {
        return 'Isi hasil retur Shopee ke draft';
    }

    public function deskripsi(): string
    {
        return 'Retur Shopee yang sudah final (diterima, ditolak, atau banding sudah diputus) dan pesanannya sudah tuntas: '
            . 'draft returnya diisi kasus, hasil banding, serta jurnal Pembalikan & Penyelesaian dari rincian dana Shopee. '
            . 'Tidak memposting — admin memeriksa kondisi barang lalu memposting sendiri.';
    }

    public function jadwal(): string
    {
        return 'Tiap jam';
    }

    public function satuan(): string
    {
        return 'retur';
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
        if (!$this->client->siap() || !($shop = ShopeeShop::aktif()) || $shop->perluHubungkanUlang()) {
            return [];
        }

        return array_values(array_filter(
            $this->service->isiHasil($shop, $simulasi),
            fn ($r) => $r['status'] !== 'dilewati'
        ));
    }

    public function rincian(array $hasil): array
    {
        return array_map(fn (array $r) => [
            'status'  => match ($r['status']) {
                'selesai' => self::DIUBAH,
                'akan'    => self::AKAN_DIBUAT,
                'gagal'   => self::GAGAL,
                default   => self::DILEWATI,
            },
            'judul'   => $r['retur'] . ' — pesanan SP-' . $r['sn'],
            'alasan'  => $r['status'] === 'gagal'
                ? $r['pesan']
                : 'Draft diisi (' . $r['kasus'] . ': ' . $r['pesan'] . '). Dana cair ' . rupiah($r['cair'])
                    . ', potongan Shopee ' . rupiah($r['potongan']) . ' — menunggu admin memposting.',
            'dokumen' => $r['retur'],
            'tautan'  => route('sales.returns.edit', $r['retur_id']),
        ], $hasil);
    }
}
