<?php

namespace App\Modules\Sales\Services;

class SalesCalculationService
{
    public function calculate(array $data): array
    {
        $qty = (float) ($data['qty'] ?? 0);
        $unitPrice = (float) ($data['unit_price'] ?? 0);

        $discountType = $data['discount_type'] ?? 'nominal';
        $discountValue = (float) ($data['discount_value'] ?? 0);

        $lineSubtotal = $qty * $unitPrice;

        // === Item Discount ===
        if ($discountType === 'percent') {
            $lineDiscount = $lineSubtotal * ($discountValue / 100);
        } else {
            $lineDiscount = $discountValue;
        }

        $lineTotal = $lineSubtotal - $lineDiscount;

        // === Global Discount ===
        $globalType = $data['global_discount_type'] ?? 'nominal';
        $globalValue = (float) ($data['global_discount_value'] ?? 0);

        if ($globalType === 'percent') {
            if ($globalValue > 100) {
                throw new \Exception("Global discount percent tidak boleh lebih dari 100%");
            }
            $globalDiscount = $lineTotal * ($globalValue / 100);
        } else {
            $globalDiscount = $globalValue;
        }

        $dpp = $lineTotal - $globalDiscount;

        // === Tax ===
        $ppnPercent = (float) ($data['ppn_percent'] ?? 0);
        $pphPercent = (float) ($data['pph_percent'] ?? 0);

        $ppnAmount = $dpp * ($ppnPercent / 100);
        $pphAmount = $dpp * ($pphPercent / 100);

        $shipping = (float) ($data['shipping_cost'] ?? 0);
        $additional_fee = (float) ($data['additional_fee'] ?? $data['selling_expense'] ?? $data['expense'] ?? 0);

        $grandTotal = $dpp + $ppnAmount - $pphAmount + $shipping + $additional_fee;

        return [
            'line_subtotal' => $lineSubtotal,
            'line_discount' => $lineDiscount,
            'line_total' => $lineTotal,
            'global_discount' => $globalDiscount,
            'dpp' => $dpp,
            'ppn_amount' => $ppnAmount,
            'pph_amount' => $pphAmount,
            'grand_total' => $grandTotal,
        ];
    }
}
