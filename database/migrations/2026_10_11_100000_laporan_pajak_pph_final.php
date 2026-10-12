<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Laporan Pajak PPh Final UMKM 0,5%.
 *
 * 1. marketplace_configs.tax_start_date — marketplace memungut PPh 22 hanya atas pesanan yang
 *    MASUK sejak tanggal ini (dipotong saat pesanan selesai; Shopee, Tokopedia/TikTok, Lazada,
 *    Blibli: pesanan 2 Okt 2026 dst). Pesanan yang masuk sebelumnya tetap disetor sendiri
 *    walau selesainya sesudah tanggal itu.
 * 2. sales_invoices.mp_tax_withheld / mp_tax_source — pajak ASLI yang dipotong marketplace per
 *    faktur (dari laporan penghasilan marketplace / Jubelio / isian manual). Kosong = laporan
 *    memakai jurnal settlement atau taksiran tax_percent.
 * 3. tax_payments — setoran PPh Final per masa pajak (NTPN), bertaut ke Pengeluaran akun 5012
 *    (kosong = setoran lama yang dibukukan di luar ERP).
 * 4. tax_omzet_adjustments — omzet per masa yang tidak tercatat sebagai faktur ERP (mis. Jan–Mei
 *    2026 sebelum ERP dipakai) supaya jatah bebas Rp500 jt & batas Rp4,8 M dihitung setahun penuh.
 */
return new class extends Migration
{
    private const PEMUNGUT = ['shopee', 'tokopedia', 'tiktokshop', 'lazada', 'blibli'];

    public function up(): void
    {
        Schema::table('marketplace_configs', function (Blueprint $table) {
            $table->date('tax_start_date')->nullable()->after('tax_percent');
        });

        // marketplace_code sering kosong di data lama → cocokkan juga lewat nama pelanggan.
        $customerIds = DB::table('customers')
            ->where(function ($q) {
                $q->whereIn('marketplace_code', self::PEMUNGUT);
                foreach (['shopee', 'tokopedia', 'tiktok', 'lazada', 'blibli'] as $nama) {
                    $q->orWhere('name', 'like', "%$nama%");
                }
            })
            ->pluck('id');
        DB::table('marketplace_configs')->whereIn('customer_id', $customerIds)
            ->update(['tax_start_date' => '2026-10-02']);

        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->decimal('mp_tax_withheld', 18, 2)->nullable()->after('marketplace_fee');
            $table->string('mp_tax_source', 20)->nullable()->after('mp_tax_withheld');
        });

        Schema::create('tax_payments', function (Blueprint $table) {
            $table->id();
            $table->string('tax_type', 30)->default('pph_final_umkm');
            $table->date('period');                       // tanggal 1 masa pajak
            $table->date('paid_at');
            $table->decimal('amount', 18, 2);
            $table->string('ntpn', 40)->nullable();
            $table->string('billing_code', 40)->nullable();
            $table->foreignId('cash_disbursement_id')->nullable()->constrained('cash_disbursements')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tax_type', 'period']);
            $table->unique('cash_disbursement_id');
        });

        Schema::create('tax_omzet_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('tax_type', 30)->default('pph_final_umkm');
            $table->date('period');
            $table->decimal('amount', 18, 2);
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tax_type', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_omzet_adjustments');
        Schema::dropIfExists('tax_payments');

        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn(['mp_tax_withheld', 'mp_tax_source']);
        });

        Schema::table('marketplace_configs', function (Blueprint $table) {
            $table->dropColumn('tax_start_date');
        });
    }
};
