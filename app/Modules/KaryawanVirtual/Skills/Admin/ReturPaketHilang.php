<?php

namespace App\Modules\KaryawanVirtual\Skills\Admin;

use App\Modules\KaryawanVirtual\Contracts\Skill;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeClient;
use App\Modules\Marketplace\Shopee\Services\ShopeeReturLamaService;

/**
 * Manajer Admin menuntaskan retur "paket hilang" Shopee yang dananya sudah diganti penuh oleh
 * Shopee: draft retur kasus PH1, tak ada yang dibalik ke pembeli, pesanan sudah final. Diposting
 * tiga blok dengan dana cair = penggantian Shopee; barang dianggap tidak kembali (memang hilang).
 * Pembungkus ShopeeReturLamaService::selesaikanPaketHilang.
 *
 * Jadwal sendiri (tiap jam, `shopee:retur-paket-hilang`) — bukan ikut sinkron Meja Kerja
 * tiap 2 menit, karena tiap pemeriksaan memanggil API Shopee.
 */
class ReturPaketHilang implements Skill
{
    public const KEY = 'admin.retur-paket-hilang';

    public function __construct(private ShopeeReturLamaService $service, private ShopeeClient $client) {}

    public function label(): string
    {
        return 'Selesaikan paket hilang yang diganti Shopee';
    }

    public function deskripsi(): string
    {
        return 'Mencari draft retur pesanan Shopee yang paketnya hilang dan dananya sudah diganti penuh oleh Shopee, '
            . 'lalu memposting returnya sesuai rincian dana Shopee (dana penggantian masuk Saldo Penjualan Shopee, '
            . 'HPP barang hilang ke Beban Kerugian Retur). Paket hilang yang belum diganti, diganti sebagian, '
            . 'atau pesanannya belum tuntas tidak disentuh.';
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

        // Yang dilewati (belum diganti / bukan paket hilang) tak perlu masuk jurnal tiap jam.
        return array_values(array_filter(
            $this->service->selesaikanPaketHilang($shop, $simulasi),
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
                : 'Paket hilang, dana diganti Shopee. Nilai ' . rupiah($r['nilai']) . ', dana cair ' . rupiah($r['cair'])
                    . ', potongan Shopee ' . rupiah($r['potongan']) . '.',
            'dokumen' => $r['retur'],
            'tautan'  => route('sales.returns.show', $r['retur_id']),
        ], $hasil);
    }
}
