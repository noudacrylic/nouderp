<?php

namespace App\DTO;

class JournalEntryDTO
{
    public function __construct(
        public string $date,
        public string $reference_type,
        public ?int $reference_id,
        public string $description,
        public array $lines,
        public bool $is_initial_balance = false,
        public ?string $reference_number = null
    ) {
    }
}
