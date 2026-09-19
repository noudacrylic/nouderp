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
        /** Jenis kasus retur — kunci SalesReturn::RETURN_TYPES. NULL = belum didefinisikan. */
        public ?string $return_type = null,
        /** Nomor retur dari marketplace (yang tertempel di paket saat barang datang). */
        public ?string $external_return_number = null,
        /** Catatan bebas penanganan: riwayat banding, video packing yang dikirim, dll. */
        public ?string $notes = null,
    ) {}
}
