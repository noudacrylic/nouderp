<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

use App\Modules\CRM\Models\CrmConversation;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Tasks\MejaKerja\Contracts\JadiSkillManajer;
use App\Modules\Tasks\MejaKerja\Contracts\SkillAdmin;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaSkill;
use App\Modules\Tasks\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Desain pesanan: tiap SO berisi produk yang PASTI butuh desain → satu task desain. Daftar
 * produknya = pola SKU yang diatur admin di halaman Pengaturan skill (bawaan: semua KS & BC,
 * NM cetak, FF cetak foto). ±12–16 per hari (data Jul–Okt 2026), 97% marketplace.
 *
 * Selesai ditandai admin (desain disetujui pembeli). Otomatis selesai bila pesanan sudah
 * dikirim, batal bila SO di-void.
 */
class DesainSkill implements SkillAdmin
{
    use JadiSkillManajer;

    /** Pola SKU (LIKE) bawaan sebelum admin mengubahnya. */
    public const POLA_SKU = ['KS-%', 'BC-%', 'NM-%TXT', 'FF-%Foto%'];

    /** Teks pembuka bawaan "Mulai Chat Shopee" (dikirim setelah kartu pesanan; admin bisa mengubah sebelum kirim). */
    public const SAPAAN = 'Halo kak, terima kasih sudah order di Noud Acrylic 🙏 Untuk desain pesanannya mau dibuat seperti apa kak? Boleh kirim logo/tulisan/contoh gambar yang diinginkan ya.';

    public function key(): string { return 'desain'; }

    public function label(): string { return 'Desain pesanan'; }

    public function deskripsi(): string
    {
        return 'Pesanan berisi produk yang butuh desain (daftarnya di Pengaturan: Kotak Saran, Box Charger, Nama Meja cetak, Frame Foto cetak, …) → task desain dengan tombol ke chat pembeli.';
    }

    /** @return string[] pola SKU (LIKE) yang berlaku sekarang */
    public function polaSku(): array
    {
        $opsi = MejaKerjaSkill::where('skill', $this->key())->value('opsi');
        $opsi = is_string($opsi) ? json_decode($opsi, true) : $opsi;
        return $opsi['pola_sku'] ?? self::POLA_SKU;
    }

    public function urlPengaturan(string $kode): string
    {
        return route('karyawan-virtual.admin.produk-desain', $kode);
    }

    public function peranBawaan(): string { return 'desain'; }

    public function bobotBawaan(): int { return 8; }

    public function slaMenitBawaan(): int { return 120; }

    public function tersedia(): bool { return true; }

    /** Mendesain punya masa kerja nyata → kartu ber-Mulai (lihat MejaKerjaService::butuhMulai). */
    public function butuhMulai(): bool { return true; }

    public function pindai(Carbon $sejak): iterable
    {
        $pola = $this->polaSku();
        if (!$pola) return;

        $rows = DB::table('sales_orders as s')
            ->join('sales_order_items as i', 'i.sales_order_id', '=', 's.id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('s.status', 'confirmed')
            ->where('s.created_at', '>=', $sejak)
            ->where(fn ($w) => collect($pola)->each(fn ($p) => $w->orWhere('p.sku', 'like', $p)))
            ->whereNotExists(fn ($q) => $q->from('sales_deliveries as d')->whereColumn('d.sales_order_id', 's.id')->where('d.status', 'posted'))
            ->select('s.id', 's.order_number', 's.customer_po_number', 's.notes', 's.created_at', 'p.sku', 'i.qty')
            ->orderBy('s.id')
            ->get()
            ->groupBy('id');

        foreach ($rows as $soId => $baris) {
            $so = $baris->first();
            $barang = $baris->map(fn ($b) => ($b->qty + 0) . '× ' . $b->sku)->implode(', ');
            $chat = DB::table('crm_pesanan_chat')->where('sales_order_id', $soId)->value('conversation_id');

            yield [
                'kunci'         => "desain:so:$soId",
                'judul'         => "Desain {$so->order_number} — " . $baris->pluck('sku')->unique()->implode(', '),
                'deskripsi'     => trim("Barang: $barang" . ($so->notes ? "\nCatatan pembeli: {$so->notes}" : '')),
                'taskable_type' => SalesOrder::class,
                'taskable_id'   => (int) $soId,
                'meta'          => array_filter([
                    'order_number' => $so->order_number,
                    'no_marketplace' => $so->customer_po_number,
                    'crm_conversation_id' => $chat ? (int) $chat : null,
                ]),
                'dibuat_at'     => Carbon::parse($so->created_at),
            ];
        }
    }

    public function keadaan(Task $task): ?array
    {
        $so = DB::table('sales_orders')->where('id', $task->taskable_id)->first(['status']);
        if (!$so || $so->status === 'void') return ['cancelled', 'Pesanan dibatalkan.'];
        if (DB::table('sales_deliveries')->where('sales_order_id', $task->taskable_id)->where('status', 'posted')->exists()) {
            return ['done', 'Otomatis: pesanan sudah dikirim.'];
        }
        return null;
    }

    /**
     * Tombol chat dicari saat kartu tampil (chat bisa muncul setelah task dibuat): pesanan
     * Shopee → chat Shopee di CRM (oranye); pesanan yang tertaut chat WhatsApp → Chat WA
     * (hijau) — pesanan Shopee pun bisa punya keduanya, desain kadang dikirim lewat WA.
     */
    public function aksi(Task $task): array
    {
        $m = $task->meta ?? [];
        $aksi = [['label' => 'Lihat SO', 'url' => route('sales.orders.show', $task->taskable_id)]];
        $no = (string) ($m['no_marketplace'] ?? '');
        $shopee = str_starts_with($no, 'SP-');

        if ($shopee && ($cid = $this->chatShopee((int) $task->taskable_id, substr($no, 3)))) {
            $aksi[] = ['label' => 'Chat Shopee', 'url' => route('crm.inbox.show', $cid), 'warna' => 'oren', 'utama' => true];
        } elseif ($shopee) {
            // Pembeli belum pernah chat → toko menyapa duluan lewat API (kartu pesanan + teks),
            // percakapannya langsung masuk CRM. Seller Centre tinggal cadangan: ID pembeli belum
            // tertarik, atau admin ingin membuka di sana — nomor disalin, tempel di kolom cari.
            $bisaSapa = DB::table('shopee_orders')->where('order_sn', substr($no, 3))->whereNotNull('buyer_user_id')->exists();
            if ($bisaSapa) {
                $aksi[] = ['label' => 'Mulai Chat Shopee', 'kirim' => route('tasks.meja.chat-shopee', $task->taskable_id),
                           'teks' => self::SAPAAN, 'warna' => 'oren', 'utama' => true];
            }
            $aksi[] = ['label' => $bisaSapa ? 'Seller Centre' : 'Chat di Seller Centre', 'url' => 'https://seller.shopee.co.id/portal/sale/order',
                       'salin' => substr($no, 3), 'warna' => $bisaSapa ? null : 'oren', 'utama' => !$bisaSapa];
        }

        if ($wa = $this->chatWa((int) $task->taskable_id)) {
            $aksi[] = ['label' => 'Chat WA', 'url' => route('crm.inbox.show', $wa), 'warna' => 'hijau', 'utama' => !$shopee];
        }
        if (!$shopee && $no !== '') {
            $aksi[] = ['label' => 'Salin no. pesanan', 'salin' => preg_replace('/^[A-Z]{2}-/', '', $no)];
        }

        return $aksi;
    }

    /** Percakapan WhatsApp CRM yang tertaut ke SO. */
    private function chatWa(int $soId): ?int
    {
        $id = DB::table('crm_pesanan_chat as pc')->join('crm_conversations as c', 'c.id', '=', 'pc.conversation_id')
            ->where('pc.sales_order_id', $soId)->where('c.channel', 'like', 'whatsapp%')->value('c.id');

        return $id ? (int) $id : null;
    }

    /**
     * Percakapan Shopee CRM milik pembeli pesanan: tautan pesanan↔chat (kartu pesanan di
     * chat), atau lewat ID pembeli di salinan pesanan Shopee → chat Shopee pembeli itu.
     */
    private function chatShopee(int $soId, string $orderSn): ?int
    {
        $id = DB::table('crm_pesanan_chat as pc')->join('crm_conversations as c', 'c.id', '=', 'pc.conversation_id')
            ->where('pc.sales_order_id', $soId)->where('c.channel', CrmConversation::KANAL_SHOPEE)->value('c.id');
        if ($id) return (int) $id;

        $order = DB::table('shopee_orders')->where('order_sn', $orderSn)->whereNotNull('buyer_user_id')
            ->first(['shop_id', 'buyer_user_id']);
        if (!$order) return null;

        $id = DB::table('shopee_chats')->where('shop_id', $order->shop_id)->where('to_id', $order->buyer_user_id)
            ->whereNotNull('crm_conversation_id')->orderByDesc('last_message_at')->value('crm_conversation_id');

        return $id ? (int) $id : null;
    }

    public function selesaiManual(): bool { return true; }
}
