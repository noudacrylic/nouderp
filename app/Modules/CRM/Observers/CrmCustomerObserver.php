<?php

namespace App\Modules\CRM\Observers;

use App\Models\Customer;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Support\PhoneNumber;
use Illuminate\Support\Facades\Log;

/**
 * Tautkan chat yang masih Lead begitu nomornya tercatat di master pelanggan.
 *
 * Pencocokan nomor di webhook hanya berjalan SAAT PESAN MASUK. Pelanggan yang
 * baru disimpan (atau nomornya baru dibetulkan) sesudah orangnya menyapa tetap
 * terbaca Lead — tanpa riwayat pesanan — sampai ia kebetulan menulis lagi, dan
 * admin yang melihat Lead membuat pelanggan kembar lewat Buat SO.
 *
 * Hanya percakapan yang BELUM tertaut yang disentuh: tautan yang sudah ada
 * (hasil pilihan admin) tidak pernah digeser oleh penyimpanan master.
 */
class CrmCustomerObserver
{
    public function saved(Customer $customer): void
    {
        if (! $customer->wasRecentlyCreated && ! $customer->wasChanged(['phone', 'recipient_phone'])) {
            return;
        }

        try {
            $nomor = array_values(array_unique(array_filter([
                PhoneNumber::normalize($customer->phone),
                PhoneNumber::normalize($customer->recipient_phone),
            ])));

            if (! $nomor) {
                return;
            }

            CrmConversation::query()
                ->whereNull('customer_id')
                ->where('channel', 'like', 'whatsapp%')
                ->whereIn('contact_key', $nomor)
                ->update(['customer_id' => $customer->id]);
        } catch (\Throwable $e) {
            // Gagal menaut tak boleh menggagalkan penyimpanan pelanggan.
            Log::warning('[CRM] gagal menautkan chat ke pelanggan', [
                'customer_id' => $customer->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
