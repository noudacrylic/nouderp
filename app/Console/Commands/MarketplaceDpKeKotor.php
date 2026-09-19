<?php

namespace App\Console\Commands;

use App\Core\Journal\Journal;
use App\Core\Journal\JournalPostingService;
use App\DTO\JournalEntryDTO;
use App\DTO\JournalLineDTO;
use App\Models\MarketplaceConfig;
use App\Modules\Sales\Models\SalesAdvance;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Naikkan DP pesanan marketplace LAMA dari nilai BERSIH ke nilai KOTOR.
 *
 * LATAR. Dulu sinkron Jubelio menyimpan `grand_total` SO sebesar nilai BERSIH (sudah
 * dipotong biaya admin marketplace), dan DP diposting sebesar grand_total itu. Sejak
 * aturan "biaya admin di SO hanya estimasi, pemotongan di faktur", SO baru memakai nilai
 * KOTOR. Pesanan lama yang sudah terlanjur ber-DP bersih perlu disamakan.
 *
 * KENAPA PERLU. Dari tiga kemungkinan akhir sebuah pesanan, hanya satu yang bocor:
 *   - Selesai normal → DP bersih = faktur bersih, reklas $gap engine = 0. SEHAT.
 *   - Batal sebelum kirim → void DP membalik nominal yang sama. SEHAT.
 *   - RETUR → SalesReturnService menjurnal Dr Uang Muka / Cr Saldo Ditahan sebesar nilai
 *     KOTOR (dihitung dari harga baris, bukan grand_total), sementara DP hanya bersih →
 *     selisih sebesar biaya admin menggantung SELAMANYA di 2105 & akun Hold. BOCOR.
 * Karena tak ada cara tahu di muka pesanan mana yang akan diretur, semua pesanan yang
 * BELUM berfaktur dinaikkan. Menaikkannya aman untuk kedua akhir yang lain: bila pesanan
 * ternyata selesai normal, baris reklas $gap di MarketplaceEngineService yang menutup
 * selisihnya — memang itu gunanya.
 *
 * CARA. Jurnal PENYESUAIAN baru (Dr Saldo Ditahan / Cr Uang Muka 2105) bertanggal hari
 * ini, BUKAN mengubah jurnal DP lama. Alasannya: jurnal lama ada di periode yang sudah
 * lewat/ditutup. Penyesuaian ini murni antar-akun NERACA, jadi tidak mengubah laba rugi
 * bulan mana pun.
 *
 * BATASAN KETAT — order dilewati bila salah satu tidak terpenuhi:
 *   - sudah punya faktur non-void (sudah settle, jangan disentuh);
 *   - SO void;
 *   - selisih kotor↔bersih tidak sama dengan `marketplace_fee` yang tersimpan;
 *   - total DP posted ke akun Hold tidak persis sama dengan grand_total SO sekarang
 *     (mis. DP sebagian / pola tak dikenal) — dilaporkan sebagai "dilewati".
 *
 * Idempoten lewat reference_type `marketplace_dp_gross_adjustment` per SO.
 * Pakai --dry-run untuk pratinjau tanpa menulis apa pun.
 */
class MarketplaceDpKeKotor extends Command
{
    protected $signature = 'marketplace:dp-ke-kotor
        {--dry-run : Hanya tampilkan yang akan diperbaiki, tanpa menulis apa pun}
        {--order= : Batasi ke satu nomor SO (untuk uji satu pesanan lebih dulu)}';

    protected $description = 'Naikkan DP pesanan marketplace yang belum berfaktur dari nilai bersih ke nilai kotor';

    /** reference_type jurnal penyesuaian — dikenali juga oleh marketplace:fix-hold-phantom. */
    public const REF_TYPE = 'marketplace_dp_gross_adjustment';

    public function handle(JournalPostingService $poster): int
    {
        $dry = (bool) $this->option('dry-run');

        $advanceAccountId = (int) DB::table('accounts')->where('code', '2105')->value('id');
        if (!$advanceAccountId) {
            $this->error('Akun Uang Muka Customer (2105) tidak ditemukan.');
            return self::FAILURE;
        }

        $holdIds = MarketplaceConfig::where('is_active', true)
            ->whereNotNull('account_receivable_hold_id')
            ->pluck('account_receivable_hold_id')->unique()->map(fn ($i) => (int) $i)->all();
        if (empty($holdIds)) {
            $this->error('Tidak ada MarketplaceConfig aktif dengan akun Saldo Ditahan.');
            return self::FAILURE;
        }

        $q = SalesOrder::query()
            ->where('status', '!=', 'void')
            ->where('marketplace_fee', '>', 0)
            // Sudah berfaktur = sudah settle → JANGAN disentuh.
            ->whereNotExists(function ($s) {
                $s->select(DB::raw(1))->from('sales_invoices as si')
                  ->whereColumn('si.sales_order_id', 'sales_orders.id')
                  ->where('si.status', '!=', 'void');
            })
            ->whereExists(function ($s) use ($holdIds) {
                $s->select(DB::raw(1))->from('sales_advances as sa')
                  ->whereColumn('sa.sales_order_id', 'sales_orders.id')
                  ->where('sa.status', 'posted')
                  ->whereIn('sa.bank_account_id', $holdIds);
            })
            ->orderBy('id');

        if ($order = $this->option('order')) {
            $q->where('order_number', $order);
        }

        $orders = $q->get();
        $this->info(($dry ? '[DRY-RUN] ' : '') . "Ditemukan {$orders->count()} pesanan marketplace belum berfaktur.");

        $naik = 0; $lewat = 0; $sudah = 0; $total = 0.0;

        foreach ($orders as $so) {
            $kotor   = round((float) $so->subtotal + (float) $so->shipping_cost + (float) $so->additional_fee, 2);
            $selisih = round($kotor - (float) $so->grand_total, 2);

            if ($selisih <= 0.005) { $sudah++; continue; } // sudah kotor

            if (abs($selisih - (float) $so->marketplace_fee) > 0.01) {
                $this->warn("  • {$so->order_number}: DILEWATI — selisih {$selisih} != marketplace_fee {$so->marketplace_fee}");
                $lewat++; continue;
            }

            $exists = Journal::where('reference_type', self::REF_TYPE)
                ->where('reference_id', $so->id)->where('status', '!=', 'void')->exists();
            if ($exists) { $sudah++; continue; }

            $deposits = SalesAdvance::where('sales_order_id', $so->id)
                ->where('status', 'posted')->whereIn('bank_account_id', $holdIds)->get();
            $deposited = round((float) $deposits->sum('amount'), 2);

            if (abs($deposited - (float) $so->grand_total) > 0.01) {
                $this->warn("  • {$so->order_number}: DILEWATI — DP {$deposited} != grand_total {$so->grand_total}");
                $lewat++; continue;
            }

            // Alokasi pembayaran harus sejalan dgn DP-nya. Kalau tidak, pembayarannya
            // menaungi lebih dari dokumen ini (atau datanya tak utuh) — jangan diutak-atik.
            $alokasi = round((float) DB::table('customer_payment_allocations')
                ->where('sales_order_id', $so->id)->sum('amount'), 2);
            if (abs($alokasi - $deposited) > 0.01) {
                $this->warn("  • {$so->order_number}: DILEWATI — alokasi pembayaran {$alokasi} != DP {$deposited}");
                $lewat++; continue;
            }

            // Akun hold tempat DP benar-benar berada (marketplace = selalu satu akun).
            $holdAcctId = (int) $deposits->groupBy('bank_account_id')
                ->map(fn ($g) => (float) $g->sum('amount'))->sortDesc()->keys()->first();

            $this->line("  • {$so->order_number}: {$so->grand_total} → {$kotor} (naik {$selisih})");
            $naik++; $total += $selisih;

            if ($dry) { continue; }

            DB::transaction(function () use ($poster, $so, $selisih, $kotor, $holdAcctId, $advanceAccountId, $deposits) {
                $poster->post(new JournalEntryDTO(
                    date: now()->toDateString(),
                    reference_type: self::REF_TYPE,
                    reference_id: $so->id,
                    description: 'Penyesuaian DP marketplace ke nilai kotor - ' . $so->order_number,
                    lines: [
                        new JournalLineDTO(
                            account_id: $holdAcctId, debit: $selisih, credit: 0,
                            description: 'Saldo ditahan marketplace (dari nilai bersih ke kotor)'
                        ),
                        new JournalLineDTO(
                            account_id: $advanceAccountId, debit: 0, credit: $selisih,
                            customer_id: $so->customer_id,
                            description: 'Uang muka marketplace (dari nilai bersih ke kotor)'
                        ),
                    ]
                ));

                // Samakan angka dokumen dengan jurnalnya. Nilai lama ditambah selisih —
                // bukan ditimpa kotor — supaya pembayaran yang kebetulan menaungi lebih dari
                // satu dokumen tidak ikut dirombak.
                $paymentIds = DB::table('customer_payment_allocations')
                    ->where('sales_order_id', $so->id)->pluck('customer_payment_id')->unique()->all();

                DB::table('customer_payment_allocations')
                    ->where('sales_order_id', $so->id)
                    ->update(['amount' => DB::raw('amount + ' . $selisih)]);

                if (!empty($paymentIds)) {
                    DB::table('customer_payments')->whereIn('id', $paymentIds)
                        ->update(['amount' => DB::raw('amount + ' . $selisih)]);
                }

                SalesAdvance::whereIn('id', $deposits->pluck('id'))
                    ->update(['amount' => DB::raw('amount + ' . $selisih)]);

                $so->forceFill([
                    'grand_total' => $kotor,
                    'paid_amount' => round((float) $so->paid_amount + $selisih, 2),
                ])->save();
            });
        }

        $this->newLine();
        $this->info(($dry ? '[DRY-RUN] ' : '') . sprintf(
            'Dinaikkan: %d (Rp %s) · sudah kotor/sudah disesuaikan: %d · dilewati: %d',
            $naik, number_format($total, 0, ',', '.'), $sudah, $lewat
        ));

        if ($dry) {
            $this->comment('Tidak ada yang ditulis. Jalankan tanpa --dry-run untuk menerapkan.');
        }

        return self::SUCCESS;
    }
}
