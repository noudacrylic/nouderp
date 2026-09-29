<?php

namespace App\Observers;

use App\Models\Customer;
use App\Services\CustomerMergeService;
use Illuminate\Support\Facades\Log;

/**
 * Gabung otomatis begitu pelanggan disimpan dengan nomor + nama yang sudah ada.
 *
 * Jalur manualnya (halaman Pelanggan Kembar) dan sapuan terjadwal memakai service
 * yang sama; di sini hanya memeriksa nomor pelanggan yang barusan disimpan.
 */
class CustomerMergeObserver
{
    public function __construct(private CustomerMergeService $merge)
    {
    }

    public function saved(Customer $customer): void
    {
        if (! $customer->wasRecentlyCreated && ! $customer->wasChanged(['phone', 'name'])) {
            return;
        }

        try {
            $this->merge->gabungOtomatisUntuk($customer);
        } catch (\Throwable $e) {
            // Gagal menggabung tak boleh menggagalkan penyimpanan pelanggan —
            // sapuan terjadwal akan mencobanya lagi.
            Log::warning('Gabung pelanggan kembar gagal', ['customer_id' => $customer->id, 'error' => $e->getMessage()]);
        }
    }
}
