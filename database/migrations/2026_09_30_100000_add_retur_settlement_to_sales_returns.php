<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Posting retur = penyelesaian faktur marketplace yang belum cair.
 *
 * `sales_invoices.returned_amount` — bagian PIUTANG faktur yang sudah dihapus retur. Dulu
 * jurnal retur mengkredit Piutang tapi fakturnya tidak tahu, jadi sisa tagihannya tetap
 * penuh: faktur yang seluruhnya diretur menggantung "Belum Cair" selamanya, dan penagihan
 * bisa mengejar tagihan yang di buku besar sudah nol.
 *
 * `sales_returns.ar_credited` — nilai piutang yang dihapus retur ini (dikurangkan lagi saat
 * void). `settlement_journal_id` + `settlement_ar_applied` — jurnal pencairan Saldo Ditahan
 * → Saldo Penjualan yang dipicu retur ini, supaya void retur bisa membatalkannya juga.
 *
 * Backfill: retur posted yang sudah ada diisi `ar_credited`-nya dari jurnal masing-masing,
 * dan faktur ikut diisi `returned_amount`-nya. Tidak ada jurnal yang ditulis — ini hanya
 * menyamakan sisa tagihan faktur dengan apa yang sudah tercatat di buku besar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->decimal('returned_amount', 18, 2)->default(0)->after('advance_applied');
        });

        Schema::table('sales_returns', function (Blueprint $table) {
            $table->decimal('ar_credited', 18, 2)->default(0)->after('fee_reversed');
            $table->unsignedBigInteger('settlement_journal_id')->nullable()->after('ar_credited');
            $table->decimal('settlement_ar_applied', 18, 2)->default(0)->after('settlement_journal_id');
        });

        $arId = DB::table('accounts')->where('code', '1120')->value('id');
        if (!$arId) {
            return;
        }

        $perRetur = DB::table('journal_lines as jl')
            ->join('journals as j', 'j.id', '=', 'jl.journal_id')
            ->join('sales_returns as r', 'r.id', '=', 'j.reference_id')
            ->where('j.reference_type', 'sales_return')
            ->where('j.status', '!=', 'void')
            ->where('r.status', 'posted')
            ->whereNotNull('r.invoice_id')
            ->where('jl.account_id', $arId)
            ->groupBy('r.id', 'r.invoice_id')
            ->selectRaw('r.id, r.invoice_id, ROUND(SUM(jl.credit) - SUM(jl.debit), 2) v')
            ->get();

        foreach ($perRetur as $row) {
            if ((float) $row->v <= 0) {
                continue;
            }
            DB::table('sales_returns')->where('id', $row->id)->update(['ar_credited' => $row->v]);
            DB::table('sales_invoices')->where('id', $row->invoice_id)->increment('returned_amount', (float) $row->v);
        }
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropColumn(['ar_credited', 'settlement_journal_id', 'settlement_ar_applied']);
        });
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn('returned_amount');
        });
    }
};
