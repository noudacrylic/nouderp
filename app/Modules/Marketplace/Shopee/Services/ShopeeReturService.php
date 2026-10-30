<?php

namespace App\Modules\Marketplace\Shopee\Services;

use App\Modules\Marketplace\Shopee\Models\ShopeeReturn;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
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
        $waktu = fn ($ts) => $ts ? Carbon::createFromTimestamp((int) $ts) : null;
        $teks  = fn ($v) => is_string($v) && $v !== '' ? $v : null;

        foreach ($this->retur->tarikRetur($shop, now()->subDays($hari)) as $orderSn => $list) {
            foreach ($list as $x) {
                ShopeeReturn::updateOrCreate(
                    ['env' => $shop->env, 'shop_id' => $shop->shop_id, 'return_sn' => (string) $x['return_sn']],
                    [
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
                    ]
                );
                $n++;
            }
        }

        return $n;
    }
}
