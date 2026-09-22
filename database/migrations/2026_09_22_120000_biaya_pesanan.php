<?php

use App\Core\Accounting\Account;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Biaya Pesanan: pengeluaran (tukang pasang dari luar, jasa antar, sewa alat) yang
 * ditautkan ke SATU pesanan supaya ikut jadi HPP pesanan itu.
 *
 * Biaya belum boleh jadi beban selama pendapatannya belum diakui — dibayar hari ini,
 * difakturkan bulan depan, Laba Rugi bulan ini akan rugi palsu. Karena itu kas yang keluar
 * diparkir dulu di 1204 (aset), lalu dipindah ke akun bebannya saat faktur di-post.
 * Lihat SalesOrderCostService.
 */
return new class extends Migration {
    private const DEFERRED_CODE = '1204';
    private const COGS_CODE     = '5008';

    public function up(): void
    {
        // Dicek per kolom: DDL MySQL tidak ikut transaksi, jadi percobaan yang gagal di tengah
        // (mis. bentrok kode akun di bawah) meninggalkan kolom yang sudah terlanjur dibuat.
        $cols = [
            'sales_order_id'              => ['sales_invoice_id', 'sales_orders'],
            // Faktur yang menanggung biaya ini, dan jurnal pemindahan 1204 → beban.
            // Keduanya kosong = biaya masih menunggu faktur.
            'cost_invoice_id'             => ['sales_order_id', 'sales_invoices'],
            'cost_recognition_journal_id' => ['cost_invoice_id', 'journals'],
        ];
        foreach ($cols as $col => [$after, $refTable]) {
            if (!Schema::hasColumn('cash_disbursement_lines', $col)) {
                Schema::table('cash_disbursement_lines', function (Blueprint $table) use ($col, $after, $refTable) {
                    $table->foreignId($col)->nullable()->after($after)->constrained($refTable)->nullOnDelete();
                });
            }
        }

        $this->ensureAccount(self::DEFERRED_CODE, 'Biaya Pesanan Ditangguhkan', 'asset');
        $this->ensureAccount(self::COGS_CODE, 'HPP Jasa Pihak Ketiga', 'expense');
    }

    /**
     * Kode akun unik TERMASUK baris yang sudah dihapus (soft delete) — firstOrCreate biasa
     * tidak melihatnya lalu bentrok. Di data produksi 5008 pernah dibuat sebagai duplikat
     * "Beban Overhead Packing" lalu dihapus tanpa pernah dijurnal; baris seperti itu aman
     * dipakai ulang. Akun AKTIF dengan nama lain tidak diambil alih diam-diam.
     */
    private function ensureAccount(string $code, string $name, string $type): void
    {
        $acc = Account::withTrashed()->where('code', $code)->first();

        if (!$acc) {
            Account::create([
                'code' => $code, 'name' => $name, 'type' => $type,
                'normal_balance' => 'debit', 'is_active' => 1,
            ]);
            return;
        }

        if (!$acc->trashed()) {
            if ($acc->name !== $name) {
                throw new RuntimeException("Kode akun {$code} sudah dipakai \"{$acc->name}\". "
                    . 'Pindahkan akun itu ke kode lain dulu, atau ubah kode di migrasi & AccountCodeEnum.');
            }
            return;
        }

        if (DB::table('journal_lines')->where('account_id', $acc->id)->exists()) {
            throw new RuntimeException("Kode akun {$code} milik akun terhapus \"{$acc->name}\" yang masih punya jurnal — tidak bisa dipakai ulang.");
        }

        $acc->restore();
        $acc->update([
            'name' => $name, 'type' => $type, 'normal_balance' => 'debit',
            'account_category' => null, 'is_active' => 1,
        ]);
    }

    public function down(): void
    {
        Schema::table('cash_disbursement_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cost_recognition_journal_id');
            $table->dropConstrainedForeignId('cost_invoice_id');
            $table->dropConstrainedForeignId('sales_order_id');
        });

        // Akun yang sudah kena jurnal TIDAK dihapus — rollback tidak boleh memutus histori.
        foreach (Account::whereIn('code', [self::DEFERRED_CODE, self::COGS_CODE])->pluck('id') as $id) {
            if (!DB::table('journal_lines')->where('account_id', $id)->exists()) {
                Account::where('id', $id)->forceDelete();
            }
        }
    }
};
