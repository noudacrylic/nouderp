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
        public ?string $reference_number = null,
        // Izinkan lebih dari satu jurnal AKTIF untuk pasangan (reference_type, reference_id)
        // yang sama. Dipakai oleh event yang memang bisa terjadi berulang pada satu dokumen
        // — mis. konsumsi material OP yang tertunda karena stok belum ada — dan idempotensinya
        // dijaga di sumber lain (qty_consumed), bukan oleh pengecekan double-posting.
        public bool $allow_repeat = false
    ) {
    }
}
