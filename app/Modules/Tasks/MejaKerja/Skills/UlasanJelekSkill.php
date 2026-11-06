<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

use App\Modules\CRM\Services\ChatPesananService;
use App\Modules\Marketplace\Shopee\Models\ShopeeUlasan;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Tasks\MejaKerja\Contracts\JadiSkillManajer;
use App\Modules\Tasks\MejaKerja\Contracts\SkillAdmin;
use App\Modules\Tasks\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Ulasan Shopee bintang 1–3 yang belum dibalas → kartu "Balas ulasan" di Meja Layanan.
 * Draf balasan publik & draf chat dari AI (shopee:tarik-ulasan); admin memeriksa lalu mengirim.
 * Kartu selesai saat ulasan sudah berbalasan — dari kartu ini atau dari Seller Centre.
 */
class UlasanJelekSkill implements SkillAdmin
{
    use JadiSkillManajer;

    private const SELLER_CENTRE = 'https://seller.shopee.co.id/portal/settings/shop/rating';

    private const SAPAAN = 'Halo Kak, kami dari Noud Acrylic. Mohon maaf atas pengalaman pesanannya yang kurang menyenangkan. Boleh diceritakan kendalanya? Kami ingin bantu menyelesaikannya.';

    public function key(): string { return 'ulasan-jelek'; }

    public function label(): string { return 'Balas ulasan jelek Shopee'; }

    public function deskripsi(): string
    {
        return 'Ulasan Shopee bintang 1–3 yang belum dibalas → kartu berisi ulasan, foto, dan draf balasan AI. Admin memeriksa lalu klik Kirim balasan; bila pembeli masih bisa mengubah ulasannya, kartu juga mengajak chat untuk menyelesaikan masalahnya.';
    }

    public function peranBawaan(): string { return 'layanan'; }

    public function bobotBawaan(): int { return 4; }

    public function slaMenitBawaan(): int { return 1440; }

    public function tersedia(): bool
    {
        return Schema::hasTable('shopee_ulasan');
    }

    /** Ulasan lama ikut (diputuskan 6 Nov): semua ulasan jelek yang belum dibalas, berapa pun umurnya. */
    public function pindai(Carbon $sejak): iterable
    {
        foreach (ShopeeUlasan::perluDibalas()->orderBy('dibuat_shopee_at')->get() as $u) {
            yield [
                'kunci'         => "ulasan:{$u->id}",
                'judul'         => "Balas ulasan {$u->bintang()} — SP-{$u->order_sn}",
                'deskripsi'     => $this->keterangan($u),
                'taskable_type' => ShopeeUlasan::class,
                'taskable_id'   => (int) $u->id,
                'meta'          => ['order_sn' => $u->order_sn, 'bintang' => $u->rating_star],
                'dibuat_at'     => ($u->dibuat_shopee_at ?? now())->max($sejak),
            ];
        }
    }

    private function keterangan(ShopeeUlasan $u): string
    {
        return ($u->comment ? "“{$u->comment}”" : '(Pembeli hanya memberi bintang, tanpa tulisan.)')
            . ($u->buyer_username ? "\nPembeli: {$u->buyer_username}" : '')
            . ($u->dibuat_shopee_at ? ' · ' . $u->dibuat_shopee_at->format('d/m/Y H:i') : '')
            . ($u->bisaDiubahPembeli()
                ? "\nPembeli masih bisa mengubah ulasannya — chat untuk menyelesaikan masalahnya (jangan minta ubah bintang)."
                : "\nUlasan sudah pernah diubah pembeli — cukup balas.");
    }

    public function keadaan(Task $task): ?array
    {
        $u = ShopeeUlasan::find($task->taskable_id);
        if (!$u) return ['cancelled', 'Ulasan tidak ditemukan.'];
        if ($u->balasan !== null) return ['done', 'Ulasan sudah dibalas.'];
        if ($u->hidden) return ['cancelled', 'Ulasan disembunyikan Shopee.'];
        if ($u->rating_star > ShopeeUlasan::BINTANG_JELEK) return ['cancelled', "Pembeli mengubah ulasan jadi {$u->rating_star} bintang."];

        return null;
    }

    public function aksi(Task $task): array
    {
        $u = ShopeeUlasan::find($task->taskable_id);
        if (!$u) return [];

        $aksi = [[
            'label'      => 'Kirim balasan',
            'kirim'      => route('tasks.meja.ulasan.balas', $u->id),
            'teks'       => $u->draf_balasan ?? '',
            'keterangan' => ($u->draf_balasan ? 'Draf dari AI — periksa dulu. ' : ($u->draf_galat ? 'Draf AI gagal, tulis sendiri. ' : 'Draf AI belum siap (±15 menit), atau tulis sendiri. '))
                . 'Tampil PUBLIK di halaman produk & hanya bisa dikirim sekali.',
            'tombol'     => 'Kirim balasan ke Shopee',
            'tab_ini'    => true,
            'utama'      => true,
        ]];

        if ($u->bisaDiubahPembeli() && ($so = SalesOrder::where('customer_po_number', 'SP-' . $u->order_sn)->first(['id']))) {
            $chat = app(ChatPesananService::class)->untuk([$so->id => 'SP-' . $u->order_sn])[$so->id];
            if ($chat['shopee']) {
                $aksi[] = ['label' => 'Chat pembeli', 'url' => route('crm.inbox.show', $chat['shopee']), 'warna' => 'oren'];
            } elseif ($chat['bisa_sapa']) {
                $aksi[] = ['label' => 'Chat pembeli', 'kirim' => route('tasks.meja.chat-shopee', $so->id),
                           'teks' => $u->draf_chat ?: self::SAPAAN, 'warna' => 'oren'];
            }
        }

        foreach ($u->fotoUrl() as $i => $url) {
            $aksi[] = ['label' => 'Foto ' . ($i + 1), 'url' => $url];
        }
        $aksi[] = ['label' => 'Seller Centre', 'url' => self::SELLER_CENTRE, 'salin' => $u->order_sn];

        return $aksi;
    }

    /** Admin boleh memutuskan tidak membalas (mis. ulasan salah alamat). */
    public function selesaiManual(): bool { return true; }
}
