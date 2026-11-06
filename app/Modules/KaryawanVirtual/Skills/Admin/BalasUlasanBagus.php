<?php

namespace App\Modules\KaryawanVirtual\Skills\Admin;

use App\Modules\KaryawanVirtual\Contracts\Skill;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeClient;
use App\Modules\Marketplace\Shopee\Services\ShopeeUlasanService;

/**
 * Manajer Admin membalas ulasan Shopee bintang 4–5 sendiri: tanpa tulisan → template
 * (config/ulasan_bagus.php, diputar), bertulisan → balasan AI. Yang menyelipkan keluhan
 * diserahkan ke kartu "Balas ulasan" di Meja Layanan. Pembungkus
 * ShopeeUlasanService::balasUlasanBagus; dijalankan shopee:tarik-ulasan tiap 15 menit.
 */
class BalasUlasanBagus implements Skill
{
    public const KEY = 'admin.ulasan-bagus';

    public function __construct(private ShopeeUlasanService $service, private ShopeeClient $client) {}

    public function label(): string
    {
        return 'Balas ulasan bagus Shopee';
    }

    public function deskripsi(): string
    {
        return 'Membalas ulasan Shopee bintang 4–5 (' . (int) config('ulasan_bagus.jendela_hari', 7) . ' hari terakhir) yang belum dibalas: '
            . 'ulasan tanpa tulisan dengan template ucapan terima kasih yang diputar, ulasan bertulisan dengan balasan AI yang menanggapi isinya. '
            . 'Ulasan bagus yang menyelipkan keluhan (mis. "bagus tapi retak") tidak dibalas — diserahkan ke kartu Balas ulasan di Meja Layanan.';
    }

    public function jadwal(): string
    {
        return 'Tiap 15 menit';
    }

    public function satuan(): string
    {
        return 'ulasan';
    }

    public function modeTersedia(): array
    {
        return [self::NONAKTIF, self::BAYANGAN, self::OTOMATIS];
    }

    public function modeBawaan(): string
    {
        return self::NONAKTIF;
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

        return $this->service->balasUlasanBagus($shop, $simulasi);
    }

    public function rincian(array $hasil): array
    {
        return array_map(fn (array $r) => [
            'status'  => match ($r['status']) {
                'terkirim' => self::DIBUAT,
                'akan'     => self::AKAN_DIBUAT,
                'gagal'    => self::GAGAL,
                default    => self::DILEWATI,
            },
            'judul'   => str_repeat('⭐', $r['bintang']) . ' SP-' . $r['sn'] . ($r['sumber'] ? ' · ' . ($r['sumber'] === 'ai' ? 'AI' : 'template') : ''),
            'alasan'  => match ($r['status']) {
                'keluhan' => 'Ada keluhan — diserahkan ke kartu Balas ulasan: ' . $r['pesan'],
                'gagal'   => $r['pesan'],
                default   => $r['teks'],
            },
            'dokumen' => 'SP-' . $r['sn'],
            'tautan'  => null,
        ], $hasil);
    }
}
