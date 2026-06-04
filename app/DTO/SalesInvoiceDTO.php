<?php

namespace App\DTO;

class SalesInvoiceDTO
{
    public function __construct(
        public readonly ?int $sales_order_id,
        public readonly int $customer_id,
        public readonly int $warehouse_id,
        public readonly string $invoice_date,

        public readonly ?string $global_discount_type,
        public readonly float $global_discount_value,

        public readonly float $ppn_percent,
        public readonly float $pph_percent,

        public readonly float $shipping_cost,
        public readonly float $additional_fee,
        public readonly float $advance_applied,
        public readonly ?string $notes,

        /** @var SalesInvoiceItemDTO[] */
        public readonly array $items,
    ) {
    }
}
