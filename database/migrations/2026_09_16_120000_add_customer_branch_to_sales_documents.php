<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dokumen penjualan menunjuk cabang tujuannya.
 *
 * Nullable, dan null berarti "pakai data induk" — sehingga seluruh dokumen
 * lama tetap benar tanpa disentuh sama sekali.
 *
 * Disimpan di DOKUMEN, bukan dibaca ulang dari pelanggan tiap kali dibuka.
 * SO dan Faktur tidak menyimpan salinan alamat apa pun; keduanya membaca live
 * dari `customers`. Tanpa kolom ini, memindahkan cabang ke alamat baru hari ini
 * akan diam-diam mengubah alamat yang tercetak di faktur enam bulan lalu.
 *
 * `nullOnDelete` dan bukan `cascade`: menghapus cabang tidak boleh ikut
 * menghapus fakturnya. Cabang terpakai memang sudah dijaga agar tak bisa
 * dihapus (CustomerBranchController::terpakai), dan ini pagar keduanya.
 */
return new class extends Migration {
    public function up(): void
    {
        foreach (['sales_quotations', 'sales_orders', 'sales_invoices', 'sales_deliveries'] as $tabel) {
            if (! Schema::hasTable($tabel) || Schema::hasColumn($tabel, 'customer_branch_id')) {
                continue;
            }

            // Surat jalan tidak punya `customer_id` — pelanggannya diturunkan
            // dari pesanannya, jadi kolomnya tidak bisa dititipkan di sebelahnya.
            $sesudah = Schema::hasColumn($tabel, 'customer_id') ? 'customer_id' : null;

            Schema::table($tabel, function (Blueprint $t) use ($sesudah) {
                $kolom = $t->foreignId('customer_branch_id')->nullable();

                if ($sesudah) {
                    $kolom->after($sesudah);
                }

                $kolom->constrained('customer_branches')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['sales_quotations', 'sales_orders', 'sales_invoices', 'sales_deliveries'] as $tabel) {
            if (! Schema::hasTable($tabel) || ! Schema::hasColumn($tabel, 'customer_branch_id')) {
                continue;
            }

            Schema::table($tabel, function (Blueprint $t) {
                $t->dropConstrainedForeignId('customer_branch_id');
            });
        }
    }
};
