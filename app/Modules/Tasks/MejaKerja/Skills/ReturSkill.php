<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

use App\Modules\Marketplace\Shopee\Models\ShopeeReturn;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Tasks\MejaKerja\Contracts\JadiSkillManajer;
use App\Modules\Tasks\MejaKerja\Contracts\SkillAdmin;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Retur: draft retur → kartu di Meja Layanan. Retur Shopee mengikuti status pengajuannya di
 * Shopee (shopee_returns, ditarik shopee:tarik-retur); tiap tahap kartunya sendiri:
 *
 *   manual  — bukan Shopee, atau tanpa pengajuan retur di Shopee (gagal kirim / paket hilang):
 *             "Selesaikan retur", selesai sendiri saat returnya diposting.
 *   periksa — retur berjalan: periksa barang, terima atau ajukan banding. Tenggat = batas
 *             respons penjual dari Shopee. Admin menandai Selesai setelah menjawab di Seller
 *             Centre; selesai sendiri juga begitu Shopee mencatat banding / hasil final.
 *   banding — menunggu keputusan Shopee: TIDAK ada kartu (keputusan user 31 Okt 2026 — tanpa
 *             Pending supaya tidak membingungkan).
 *   hasil   — semua pengajuan final → kartu BARU "Banding sudah selesai / Retur selesai di
 *             Shopee — periksa & posting". Draftnya diisi skill admin.retur-isi-hasil dari
 *             rincian dana Shopee; keterangan kartu ikut diperbarui saat draft terisi.
 *
 * Draft lama (lahir sebelum skill dinyalakan) yang BUKAN Shopee langsung Pending sampai admin
 * mengeceknya; kartu Shopee tidak pernah Pending.
 */
class ReturSkill implements SkillAdmin
{
    use JadiSkillManajer;

    /** Daftar retur di Seller Centre — halaman detail butuh id numerik, nomor retur disalin. */
    public const SELLER_CENTRE = 'https://seller.shopee.co.id/portal/sale/returnrefundcancel';

    public function key(): string { return 'retur'; }

    public function label(): string { return 'Retur penjualan'; }

    public function deskripsi(): string
    {
        return 'Tiap draft retur → kartu "Selesaikan retur" dengan tombol ke form retur. Retur Shopee: kartu "Periksa barang retur — terima atau banding" '
            . '(tenggat = batas respons Shopee, tandai Selesai setelah menjawab di Seller Centre), lalu kartu baru "Banding sudah selesai — periksa & posting" '
            . 'begitu hasilnya keluar. Selesai sendiri saat retur diposting.';
    }

    public function peranBawaan(): string { return 'layanan'; }

    public function bobotBawaan(): int { return 6; }

    public function slaMenitBawaan(): int { return 1440; }

    public function tersedia(): bool { return true; }

    public function pindai(Carbon $sejak): iterable
    {
        foreach ($this->draft() as $d) {
            $f = $this->fase($d);
            if ($f['fase'] === 'banding') continue;

            $lama = Carbon::parse($d->created_at)->lte($sejak);
            $pending = $lama && $f['order_sn'] === null ? 'Retur lama — menunggu dicek admin.' : null;

            yield [
                'kunci'         => "retur:{$d->id}:{$f['fase']}",
                'judul'         => $f['judul'],
                'deskripsi'     => $f['deskripsi'],
                'taskable_type' => SalesReturn::class,
                'taskable_id'   => (int) $d->id,
                'meta'          => array_filter([
                    'return_number'       => $d->return_number,
                    'order_sn'            => $f['order_sn'],
                    'return_sn'           => $f['return_sn'],
                    'fase'                => $f['fase'],
                    'diisi'               => $f['diisi'] ?: null,
                    'crm_conversation_id' => $d->so_id ? (DB::table('crm_pesanan_chat')->where('sales_order_id', $d->so_id)->value('conversation_id') ?: null) : null,
                    'pending'             => $pending,
                ], fn ($v) => $v !== null),
                'dibuat_at'     => $lama ? now() : Carbon::parse($d->created_at),
                'tenggat_at'    => $f['tenggat'],
            ];
        }
    }

    /** Draft lama non-Shopee langsung Pending sampai admin mengeceknya. */
    public function setelahDibuat(Task $task, array $kerja): void
    {
        if ($alasan = $kerja['meta']['pending'] ?? null) {
            $task->pending_alasan = mb_substr($alasan, 0, 255);
            app(MejaKerjaService::class)->ubahStatus($task, 'pending', null, 'Pending: ' . $alasan);
        }
    }

    public function keadaan(Task $task): ?array
    {
        $status = DB::table('sales_returns')->where('id', $task->taskable_id)->value('status');
        if ($status === 'posted') return ['done', 'Retur diposting.'];
        if ($status !== 'draft') return ['cancelled', 'Retur dibatalkan / tidak jadi.'];

        // Kartu periksa tuntas begitu Shopee mencatat jawaban penjual (banding) atau hasil final.
        if (($task->meta['fase'] ?? null) === 'periksa' && ($d = $this->draft($task->taskable_id)->first())) {
            $fase = $this->fase($d)['fase'];
            if ($fase === 'banding') return ['done', 'Banding tercatat di Shopee — menunggu keputusan Shopee.'];
            if ($fase === 'hasil') return ['done', 'Retur sudah final di Shopee — lanjut kartu periksa & posting.'];
        }

        return null;
    }

    /**
     * Kartu hasil: keterangan diperbarui saat draft selesai diisi Manajer Admin.
     *
     * @return array{status: ?string, catatan: string, title: string, description: ?string, meta: array, tenggat_at: ?Carbon}|null
     */
    public function perbarui(Task $task): ?array
    {
        $m = $task->meta ?? [];
        if (($m['fase'] ?? null) !== 'hasil' || !empty($m['diisi'])) return null;

        $d = $this->draft($task->taskable_id)->first();
        if (!$d || !($f = $this->fase($d))['diisi']) return null;

        return [
            'status'      => null,
            'catatan'     => 'Draft diisi dari rincian dana Shopee — periksa & posting.',
            'title'       => $f['judul'],
            'description' => $f['deskripsi'],
            'meta'        => array_merge($m, ['diisi' => true]),
            'tenggat_at'  => null,
        ];
    }

    public function aksi(Task $task): array
    {
        $m = $task->meta ?? [];
        $aksi = [['label' => 'Buka retur', 'url' => route('sales.returns.edit', $task->taskable_id), 'utama' => true]];

        if (!empty($m['order_sn'])) {
            $aksi[] = ['label' => 'Buka di Seller Centre', 'url' => self::SELLER_CENTRE, 'salin' => $m['return_sn'] ?? $m['order_sn']];
        }
        if (!empty($m['crm_conversation_id'])) {
            $aksi[] = ['label' => 'Chat pembeli', 'url' => route('crm.inbox.show', $m['crm_conversation_id'])];
        }

        return $aksi;
    }

    public function selesaiManual(): bool { return false; }

    /** Hanya kartu periksa yang ditandai selesai admin (setelah menjawab di Seller Centre). */
    public function selesaiManualUntuk(Task $task): bool
    {
        return ($task->meta['fase'] ?? null) === 'periksa';
    }

    /** Draft retur yang masih terbuka (atau satu draft), beserta pesanan & pelanggannya. */
    private function draft(?int $id = null)
    {
        return DB::table('sales_returns as r')
            ->leftJoin('sales_invoices as si', 'si.id', '=', 'r.invoice_id')
            ->leftJoin('sales_orders as so', 'so.id', '=', DB::raw('COALESCE(r.sales_order_id, si.sales_order_id)'))
            ->leftJoin('customers as c', 'c.id', '=', 'r.customer_id')
            ->where('r.status', 'draft')
            ->whereIn('r.stage', SalesReturn::STAGES_AKTIF)
            ->when($id, fn ($q) => $q->where('r.id', $id))
            ->orderBy('r.id')
            ->get(['r.id', 'r.return_number', 'r.created_at', 'r.return_case', 'r.journal_reversal', 'r.journal_settlement',
                   'so.id as so_id', 'so.order_number', 'c.name as pelanggan']);
    }

    /**
     * @return array{fase: string, judul: string, deskripsi: string, tenggat: ?Carbon, order_sn: ?string, return_sn: ?string, diisi: bool}
     */
    private function fase(object $d): array
    {
        $no = $d->return_number;
        $diisi = $d->return_case !== null && ($d->journal_reversal !== null || $d->journal_settlement !== null);
        $hasil = fn (string $fase, string $judul, string $desk, ?Carbon $tenggat = null, ?string $sn = null, ?string $rsn = null) =>
            ['fase' => $fase, 'judul' => $judul, 'deskripsi' => $desk, 'tenggat' => $tenggat, 'order_sn' => $sn, 'return_sn' => $rsn, 'diisi' => $diisi];

        if (!str_starts_with((string) $d->order_number, 'SP-')) {
            return $hasil('manual', "Selesaikan retur $no — " . ($d->pelanggan ?: 'pelanggan'),
                'Periksa barang & dananya, lengkapi kasus retur, lalu posting.' . ($d->order_number ? "\nPesanan: {$d->order_number}" : ''));
        }

        $sn = substr($d->order_number, 3);
        $list = ShopeeReturn::where('order_sn', $sn)->orderBy('dibuat_shopee_at')->get();
        $rsn = $list->last()?->return_sn;
        if ($list->isEmpty()) {
            return $hasil('manual', "Selesaikan retur $no — SP-$sn (tanpa pengajuan retur di Shopee)",
                'Tidak ada pengajuan retur di Shopee (gagal kirim / paket hilang). Cek barang yang kembali, lalu posting.', null, $sn);
        }

        $ringkas = $list->map(fn (ShopeeReturn $x) => "{$x->return_sn}: {$x->status}" . ($x->reason ? " ({$x->reason})" : '')
            . ' · refund ' . rupiah($x->refund_amount) . ($x->needs_logistics ? ' · barang dikirim balik' : ''))->implode("\n");

        $berjalan = $list->reject->final();
        if ($berjalan->contains->disengketakan()) {
            return $hasil('banding', "Banding retur $no", '', null, $sn, $rsn);
        }
        if ($berjalan->isNotEmpty()) {
            $tenggat = $berjalan->pluck('seller_due_at')->filter()->min();
            return $hasil('periksa', "Periksa barang retur $no — SP-$sn: terima atau banding",
                'Periksa barang & bukti pembeli, lalu terima atau ajukan banding di Seller Centre'
                . ($tenggat ? ' sebelum ' . $tenggat->format('d/m H:i') : '') . '. Tandai Selesai setelah menjawab di Shopee.'
                . "\n$ringkas", $tenggat, $sn, $rsn);
        }

        $awal = $list->contains('pernah_disengketakan', true) ? 'Banding sudah selesai' : 'Retur selesai di Shopee';
        return $hasil('hasil', "$awal — periksa & posting $no (SP-$sn)",
            ($diisi ? 'Draft sudah diisi Manajer Admin dari rincian dana Shopee — periksa kondisi barang & jurnalnya, lalu posting.'
                    : 'Menunggu dana pesanan final untuk diisi otomatis — atau lengkapi sendiri lalu posting.') . "\n$ringkas",
            null, $sn, $rsn);
    }
}
