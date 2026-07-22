<?php

namespace App\Modules\Sales\Contracts;

/**
 * Sumber konfirmasi pembayaran otomatis (uang masuk).
 * Implementasi: EmailMutationProvider (IMAP notifikasi bank), MootaProvider (webhook).
 * Bisa ditukar tanpa mengubah PaymentMatchingService.
 */
interface PaymentConfirmationProvider
{
    /**
     * Ambil daftar kredit (uang masuk) yang belum diproses.
     *
     * @return array<int, array{amount: float, reference: string, occurred_at: ?\Carbon\CarbonInterface}>
     */
    public function fetchCredits(): array;

    /** Nama pendek provider untuk dicatat di confirmed_via (email|moota). */
    public function name(): string;
}
