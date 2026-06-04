<?php

namespace App\DTO;

class SalesReturnDTO
{
    /**
     * @param int $invoice_id
     * @param int $customer_id
     * @param array $items Array of items: [ ['invoice_item_id' => int, 'qty' => float, 'condition' => 'good'|'damaged'] ]
     * @param string $date
     */
    public function __construct(
        public int $customer_id,
        public array $items,
        public string $date,
        public ?int $invoice_id = null,
        public ?int $sales_order_id = null,
    ) {}
}
