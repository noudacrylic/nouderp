<?php

namespace App\Modules\KaryawanVirtual\Skills\Admin;

use App\Modules\KaryawanVirtual\Contracts\Skill;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeClient;
use App\Modules\Marketplace\Shopee\Services\ShopeeReturLamaService;

/**
 * Manajer Admin menuntaskan "retur tidak jadi" Shopee: draft retur yang pengajuannya
 * dibatalkan pembeli di Shopee (refund 0) dan pesanannya sudah selesai. Draft ditutup
 * (Retur Tidak Jadi) dan faktur dicairkan sesuai rincian dana Shopee — potongan asli,
 * bukan taksiran. Pembungkus ShopeeReturLamaService::selesaikanTidakJadi.
 *
 * Jadwal sendiri (tiap jam, `shopee:retur-tidak-jadi`) — bukan ikut sinkron Meja Kerja
 * tiap 2 menit, karena tiap pemeriksaan memanggil API Shopee.
 */
class ReturTidakJadi implements Skill
{
    public const KEY = 'admin.retur-tidak-jadi';

    public function __construct(private ShopeeReturLamaService $service, private ShopeeClient $client) {}

    public function label(): string
    {
        return 'Selesaikan retur tidak jadi (Shopee)';
    }

    public function deskripsi(): string
    {
        return 'Mencari draft retur pesanan Shopee yang pengajuan returnya dibatalkan pembeli dan pesanannya sudah selesai, '
            . 'lalu menutupnya sebagai Retur Tidak Jadi dan mencairkan fakturnya sesuai rincian dana Shopee '
            . '(dana cair & potongan asli, termasuk pajak). Retur yang masih berjalan atau dananya belum final tidak disentuh.';
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

        // Yang dilewati karena pesanannya belum selesai tak perlu masuk jurnal tiap jam.
        return array_values(array_filter(
            $this->service->selesaikanTidakJadi($shop, $simulasi),
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
                : 'Retur dibatalkan pembeli di Shopee. Nilai ' . rupiah($r['nilai']) . ', dana cair ' . rupiah($r['cair'])
                    . ', potongan Shopee ' . rupiah($r['potongan']) . '.',
            'dokumen' => $r['retur'],
            'tautan'  => route('sales.returns.show', $r['retur_id']),
        ], $hasil);
    }
}
