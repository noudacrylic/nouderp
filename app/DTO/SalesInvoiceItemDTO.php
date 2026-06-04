<?php

namespace App\DTO;

class SalesInvoiceItemDTO
{
    public function __construct(
        public readonly ?int $sales_order_item_id,
        public readonly ?int $product_id,
        public readonly string $description,
        public readonly string $item_type,
        public readonly float $qty,
        public readonly float $unit_price,
        public readonly string $discount_type,
        public readonly float $discount_value,
        public readonly float $discount_amount,
        public readonly float $ppn_percent,
        public readonly float $pph_percent,
    ) {
    }
}
