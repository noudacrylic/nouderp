<?php

namespace App\DTO;

class JournalLineDTO
{
    public function __construct(
        public int $account_id,
        public float $debit,
        public float $credit,
        public ?string $description = null,
        public ?int $customer_id = null
    ) {
    }
}
