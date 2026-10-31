<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

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

    public function aksi(Task $task): array
    {
        $m = $task->meta ?? [];
        $aksi = [['label' => 'Lihat SO', 'url' => route('sales.orders.show', $task->taskable_id)]];

        if (!empty($m['crm_conversation_id'])) {
            $aksi[] = ['label' => 'Chat WA', 'url' => route('crm.inbox.show', $m['crm_conversation_id']), 'utama' => true];
        }
        $no = (string) ($m['no_marketplace'] ?? '');
        if (str_starts_with($no, 'SP-')) {
            // Seller Centre belum bisa dibuka langsung ke pesanan tertentu tanpa order_id
            // numerik — nomor pesanan disalin, admin tinggal tempel di kolom cari.
            $aksi[] = ['label' => 'Chat di Seller Centre', 'url' => 'https://seller.shopee.co.id/portal/sale/order',
                       'salin' => substr($no, 3), 'utama' => empty($m['crm_conversation_id'])];
        } elseif ($no !== '') {
            $aksi[] = ['label' => 'Salin no. pesanan', 'salin' => preg_replace('/^[A-Z]{2}-/', '', $no)];
        }

        return $aksi;
    }

    public function selesaiManual(): bool { return true; }
}
