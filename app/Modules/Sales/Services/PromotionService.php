<?php

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Models\Promotion;
use Illuminate\Support\Collection;

/**
 * Logika matching promo terpusat. Dipakai Kasir, SO, Invoice, Penawaran.
 *
 * Prinsip: service ini TIDAK menyimpan apa-apa & tidak mengubah finansial transaksi.
 * Ia hanya menghitung nilai diskon yang lalu diisi ke field diskon existing
 * (diskon item per baris, diskon ongkir, diskon global).
 *
 * Catatan nilai nominal:
 *  - Diskon ITEM nominal = PER UNIT (mengikuti math existing: discount = qty * value).
 *  - Tier shipping / cart_total nominal = FLAT (sekali potong), sesuai field diskon ongkir/global.
 */
class PromotionService
{
    /**
     * Diskon per produk untuk type 'item'.
     * @param array $items list of ['product_id','qty','unit_price']
     * @return array map product_id => [promotion_id, promotion_name, discount_type, discount_value, original_price, discount_amount]
     */
    public function resolveItemDiscounts(array $items, ?string $voucherCode = null): array
    {
        $promos = $this->candidates('item', $voucherCode);
        if ($promos->isEmpty()) {
            return [];
        }

        $result = [];
        foreach ($items as $it) {
            $pid = (int) ($it['product_id'] ?? 0);
            if (!$pid) {
                continue;
            }
            $qty   = (float) ($it['qty'] ?? 1) ?: 1.0;
            $price = (float) ($it['unit_price'] ?? 0);

            $best = null;
            $bestAmt = -1.0;
            foreach ($promos as $promo) {
                $covers = $promo->applies_to_all || in_array($pid, $promo->productIds(), true);
                if (!$covers) {
                    continue;
                }
                $amt = $promo->discount_type === 'percent'
                    ? round($qty * $price * ((float) $promo->discount_value / 100), 2)
                    : round($qty * (float) $promo->discount_value, 2); // nominal = per unit
                if ($amt <= 0) {
                    continue;
                }
                if ($this->beats($promo, $amt, $best, $bestAmt)) {
                    $best = $promo;
                    $bestAmt = $amt;
                }
            }

            if ($best) {
                $result[$pid] = [
                    'promotion_id'    => $best->id,
                    'promotion_name'  => $best->name,
                    'discount_type'   => $best->discount_type,
                    'discount_value'  => (float) $best->discount_value, // per-unit utk nominal
                    'original_price'  => $price,
                    'discount_amount' => $bestAmt,
                ];
            }
        }

        return $result;
    }

    /** Diskon ongkir bertingkat. Diskon dihitung atas shipping_gross. */
    public function resolveShippingDiscount(float $subtotal, float $shippingGross, ?string $voucherCode = null): ?array
    {
        return $this->resolveTier('shipping', $subtotal, $shippingGross, $voucherCode);
    }

    /** Diskon berdasarkan total belanja (mengisi diskon global). Diskon dihitung atas subtotal. */
    public function resolveCartTotalDiscount(float $subtotal, ?string $voucherCode = null): ?array
    {
        return $this->resolveTier('cart_total', $subtotal, $subtotal, $voucherCode);
    }

    /** Cari voucher aktif berdasarkan kode (case-insensitive). */
    public function lookupVoucher(string $code): ?Promotion
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        return Promotion::active()
            ->where('is_voucher', true)
            ->whereRaw('LOWER(voucher_code) = ?', [mb_strtolower($code)])
            ->first();
    }

    /**
     * Resolusi lengkap untuk satu konteks transaksi.
     * @param array $context ['items','subtotal','shipping_gross','voucher_code']
     */
    public function resolve(array $context): array
    {
        $items        = $context['items'] ?? [];
        $subtotal     = (float) ($context['subtotal'] ?? 0);
        $shippingGross = (float) ($context['shipping_gross'] ?? 0);
        $voucher      = $context['voucher_code'] ?? null;

        return [
            'item_discounts'  => $this->resolveItemDiscounts($items, $voucher),
            'shipping'        => $this->resolveShippingDiscount($subtotal, $shippingGross, $voucher),
            'cart_total'      => $this->resolveCartTotalDiscount($subtotal, $voucher),
        ];
    }

    // ───────────────────────── internal ─────────────────────────

    private function resolveTier(string $type, float $subtotal, float $base, ?string $voucherCode): ?array
    {
        $promos = $this->candidates($type, $voucherCode);
        if ($promos->isEmpty()) {
            return null;
        }

        $best = null;
        $bestAmt = -1.0;
        $bestTier = null;
        foreach ($promos as $promo) {
            // tier tertinggi yang min_spend-nya <= subtotal
            $tier = $promo->tiers
                ->filter(fn ($t) => $subtotal >= (float) $t->min_spend)
                ->sortByDesc('min_spend')
                ->first();
            if (!$tier) {
                continue;
            }
            $amt = $tier->discount_type === 'percent'
                ? round($base * ((float) $tier->discount_value / 100), 2)
                : round((float) $tier->discount_value, 2); // flat
            if ($amt <= 0) {
                continue;
            }
            if ($this->beats($promo, $amt, $best, $bestAmt)) {
                $best = $promo;
                $bestAmt = $amt;
                $bestTier = $tier;
            }
        }

        if (!$best) {
            return null;
        }

        return [
            'promotion_id'    => $best->id,
            'promotion_name'  => $best->name,
            'discount_type'   => $bestTier->discount_type,
            'discount_value'  => (float) $bestTier->discount_value,
            'discount_amount' => $bestAmt,
            'min_spend'       => (float) $bestTier->min_spend,
        ];
    }

    /** Precedence: priority tertinggi → diskon terbesar → terbaru (sudah ter-sort id desc). */
    private function beats(Promotion $promo, float $amt, ?Promotion $best, float $bestAmt): bool
    {
        if ($best === null) {
            return true;
        }
        if ($promo->priority !== $best->priority) {
            return $promo->priority > $best->priority;
        }
        return $amt > $bestAmt; // tie priority → amount lebih besar; tie amount → tetap yg lama (lebih baru, krn sort id desc)
    }

    /**
     * Kandidat promo aktif untuk satu type: auto-apply (bukan voucher) selalu masuk;
     * voucher hanya masuk bila kodenya cocok. Ter-sort priority desc, id desc (terbaru).
     */
    private function candidates(string $type, ?string $voucherCode): Collection
    {
        $code = $voucherCode !== null ? mb_strtolower(trim($voucherCode)) : '';

        return Promotion::active()
            ->ofType($type)
            ->where(function ($q) use ($code) {
                $q->where('is_voucher', false);
                if ($code !== '') {
                    $q->orWhere(fn ($w) => $w->where('is_voucher', true)
                        ->whereRaw('LOWER(voucher_code) = ?', [$code]));
                }
            })
            ->with(['tiers', 'products'])
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->get();
    }
}
