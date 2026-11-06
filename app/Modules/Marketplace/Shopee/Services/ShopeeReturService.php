<?php

namespace App\Modules\Marketplace\Shopee\Services;

use App\Modules\Marketplace\Shopee\Models\ShopeeReturn;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Sales\Models\SalesReturn;
use Carbon\Carbon;

/**
 * Tarik pengajuan retur Shopee ke shopee_returns (baca saja). Jendela pengajuan $hari ke
 * belakang; status retur lama yang berubah ikut diperbarui karena barisnya ditimpa.
 */
class ShopeeReturService
{
    public function __construct(private ShopeeReturLamaService $retur) {}

    /** @return int jumlah pengajuan retur yang disimpan */
    public function tarik(ShopeeShop $shop, int $hari = 60): int
    {
        $n = 0;
        // Carbon 3: createFromTimestamp() tanpa zona = UTC → jam tersimpan mundur 7 jam.
        $waktu = fn ($ts) => $ts ? Carbon::createFromTimestamp((int) $ts, config('app.timezone')) : null;
        $teks  = fn ($v) => is_string($v) && $v !== '' ? $v : null;

        foreach ($this->retur->tarikRetur($shop, now()->subDays($hari)) as $orderSn => $list) {
            foreach ($list as $x) {
                $baris = ShopeeReturn::firstOrNew(['env' => $shop->env, 'shop_id' => $shop->shop_id, 'return_sn' => (string) $x['return_sn']]);
                $baris->fill([
                    'order_sn'                   => (string) $orderSn,
                    'status'                     => (string) $x['status'],
                    'reason'                     => $teks($x['reason'] ?? null),
                    'text_reason'                => $teks($x['text_reason'] ?? null),
                    'refund_amount'              => $x['refund_amount'] ?? null,
                    'needs_logistics'            => (bool) ($x['needs_logistics'] ?? false),
                    'negotiation_status'         => $teks($x['negotiation_status'] ?? ($x['negotiation']['negotiation_status'] ?? null)),
                    'seller_proof_status'        => $teks($x['seller_proof_status'] ?? null),
                    'seller_compensation_status' => $teks($x['seller_compensation_status'] ?? null),
                    'seller_due_at'              => $waktu($x['return_seller_due_date'] ?? null),
                    'dibuat_shopee_at'           => $waktu($x['create_time'] ?? null),
                    'diperbarui_shopee_at'       => $waktu($x['update_time'] ?? null),
                    'raw'                        => $x,
                    'disinkron_at'               => now(),
                ]);
                // Status akhir tak lagi menyebut banding — penandanya dipertahankan sekali terlihat.
                $baris->pernah_disengketakan = $baris->pernah_disengketakan || $baris->disengketakan();
                $baris->save();
                $n++;
            }
        }

        return $n;
    }
    /**
     * Isi Jenis retur draft yang masih kosong dari pengajuan retur Shopee-nya, plus nomor
     * retur Shopee & alasan pembeli di catatan. Pilihan manual tak pernah ditimpa (hanya
     * return_type NULL); Kasus & jurnal tetap urusan shopee:retur-isi-hasil saat dana final.
     *
     *   ada pengajuan, alasan "tidak diterima" → Paket Hilang
     *   ada pengajuan lain                     → Diajukan Konsumen
     *   semua dibatalkan / tanpa pengajuan     → dibiarkan (gagal kirim vs paket hilang
     *                                            tak terbaca dari data retur)
     *
     * @return array<string, string> nomor retur ERP => jenis yang diisi
     */
    public function isiJenis(ShopeeShop $shop): array
    {
        $drafts = SalesReturn::with(['salesOrder:id,order_number', 'invoice:id,invoice_number'])
            ->where('status', 'draft')->whereNull('return_type')->get()
            ->keyBy(fn (SalesReturn $r) => $r->id)
            ->map(fn (SalesReturn $r) => [$r, $r->orderSnShopee()])
            ->filter(fn ($x) => $x[1] !== null);
        if ($drafts->isEmpty()) {
            return [];
        }

        $pengajuan = ShopeeReturn::where('env', $shop->env)->where('shop_id', $shop->shop_id)
            ->whereIn('order_sn', $drafts->pluck(1)->unique()->values())
            ->orderBy('dibuat_shopee_at')->get()->groupBy('order_sn');

        $hasil = [];
        foreach ($drafts as [$retur, $sn]) {
            $list = ($pengajuan[$sn] ?? collect())->reject(fn (ShopeeReturn $x) => $x->status === 'CANCELLED');
            if ($list->isEmpty()) {
                continue;
            }
            $jenis = $list->contains('reason', 'NOT_RECEIPT') ? 'paket_hilang' : 'diajukan_konsumen';
            $alasan = $list->map(fn (ShopeeReturn $x) => $x->alasanLabel() . ($x->text_reason ? ' — "' . trim($x->text_reason) . '"' : ''))
                ->unique()->implode('; ');

            $retur->forceFill([
                'return_type'            => $jenis,
                'external_return_number' => $retur->external_return_number ?: $list->pluck('return_sn')->implode(', '),
                'notes'                  => trim(($retur->notes ? $retur->notes . "\n" : '')
                    . '[Data Shopee ' . now()->format('d/m/Y') . '] Alasan pembeli: ' . $alasan),
            ])->save();
            $hasil[$retur->return_number] = $jenis;
        }

        return $hasil;
    }
}
