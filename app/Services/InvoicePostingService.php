<?php

namespace App\Services;

use App\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;

class InvoicePostingService
{
    public function __construct(
        protected \App\Core\Period\PeriodService $periodService
    ) {
    }

    /**
     * MAPPING AKUN ERP (Final Blueprint Phase 1)
     */
    protected function account($key)
    {
        return match ($key) {
            'ar'        => '1120', // Piutang
            'revenue'   => '4001', // Penjualan
            'discount'  => '4003', // Diskon Penjualan
            'hpp'       => '5001', // HPP
            'inventory' => '1130', // Persediaan (Opsi 1)
            'ppn'       => '2103', // PPN Keluaran
            'pph'       => '1109', // PPh 23 Dibayar Dimuka (aset/kredit pajak — dipotong customer)
            'titipan'   => '1203', // Titipan Ongkir
            'advance'   => '2105', // Uang Muka Customer
            'additional_revenue' => '4002', // Pendapatan Tambahan
            default     => throw new \Exception("Account mapping not found for key: $key"),
        };
    }

    public function post(SalesInvoice $invoice)
    {
        if ($invoice->status === \App\Enums\InvoiceStatusEnum::POSTED) {
            throw new \Exception('Invoice already posted');
        }

        // 🔒 0. DOUBLE POST GUARD (WAJIB)
        $exists = \App\Core\Journal\Journal::where('reference_type', 'sales_invoice')
            ->where('reference_id', $invoice->id)
            ->exists();

        if ($exists) {
            throw new \Exception('Journal already exists for this invoice');
        }

        // 🔒 PERIOD GUARD (Sudah di-cast di Model)
        $this->periodService->ensureOpen(\Carbon\Carbon::parse($invoice->invoice_date));

        DB::transaction(function () use ($invoice) {

            // 🔥 1. HANDLE DELIVERY (multi-SJ partial + settle COGS ditunda + SJ sisa)
            $deliveries = $this->handleDelivery($invoice);

            // 🔥 2. AMBIL HPP DARI SEMUA SJ
            $this->assignHppFromDelivery($invoice, $deliveries);

            // 🔥 3. BUAT JOURNAL HEADER
            $this->createJournalHeader($invoice);

            // 🔥 4. POST HPP
            $this->postHpp($invoice);

            // 🔥 5. POST REVENUE
            $this->postRevenue($invoice);

            // 🔥 6. TITIPAN ONGKIR
            $this->postTitipan($invoice);

            // 🔥 7. APPLY ADVANCE
            $this->applyAdvance($invoice);

            // 🔥 8. VALIDASI BALANCE
            $this->validateBalance($invoice);

            // 🔥 9. FINALIZE
            $invoice->status = \App\Enums\InvoiceStatusEnum::POSTED;
            $invoice->save();

            // 🔥 9b. SETTLE SELISIH ONGKIR BITESHIP (Fase 5) — kalau SO punya booking Biteship.
            if ($invoice->sales_order_id) {
                $so = \App\Modules\Sales\Models\SalesOrder::find($invoice->sales_order_id);
                if ($so) {
                    app(\App\Modules\Shipping\Services\ShippingAccountingService::class)->settleOrder($so);
                }
            }

            // 🔥 10. TRIGGER MARKETPLACE ENGINE (New Blueprint Phase 3)
            //
            // HANYA faktur gaya LAMA yang di-settle di sini. Faktur gaya baru
            // (`fee_at_settlement`) terbit saat PENGIRIMAN, jadi saat ini pesanannya belum
            // selesai dan biaya adminnya belum diketahui — settlement-nya menunggu sinyal
            // "pesanan selesai" dari marketplace (lihat JubelioOrderSyncService::ensureSettlement).
            // Menjalankannya di sini akan melepas Saldo Ditahan terlalu cepat & membebankan
            // fee yang masih nol.
            if (!$invoice->fee_at_settlement) {
                app(\App\Modules\Sales\Services\MarketplaceEngineService::class)->handle($invoice);
            }
        });
    }

    /**
     * 🔥 HANDLE DELIVERY (SJ)
     * Menggunakan SalesDeliveryService tunggal dari modul Sales
     */
    protected function handleDelivery($invoice)
    {
        $svc = app(\App\Modules\Sales\Services\SalesDeliveryService::class);
        $soId = $invoice->sales_order_id;

        // 1. Kumpulkan SJ non-void untuk SO ini yang BELUM ter-link invoice (untuk diklaim)
        //    atau yang sudah ter-link ke invoice INI. SJ milik invoice lain TIDAK disentuh.
        $deliveries = collect();
        if ($soId) {
            $deliveries = \App\Modules\Sales\Models\SalesDelivery::where('sales_order_id', $soId)
                ->where('status', '!=', 'void')
                ->where(function ($q) use ($invoice) {
                    $q->whereNull('invoice_id')->orWhere('invoice_id', $invoice->id);
                })
                ->get();
        }
        $linked = \App\Modules\Sales\Models\SalesDelivery::where('invoice_id', $invoice->id)
            ->where('status', '!=', 'void')
            ->get();
        foreach ($linked as $l) {
            if (!$deliveries->contains('id', $l->id)) {
                $deliveries->push($l);
            }
        }

        // 2. Hubungkan ke invoice, post yang masih draft, lalu SETTLE COGS yang ditunda.
        //    settleDeferredCogs() melempar (blok invoice) jika produksi belum selesai.
        foreach ($deliveries as $d) {
            if (!$d->invoice_id) {
                $d->update(['invoice_id' => $invoice->id]);
            }
            if ($d->status !== 'posted') {
                $svc->post($d->id);
            }
            $svc->settleDeferredCogs($d->id);
            $d->load('items.product');
        }

        // 3. Buat SJ otomatis untuk SISA yang belum dikirim (qty invoice − total terkirim).
        $alreadyDelivered = [];
        foreach ($deliveries as $d) {
            foreach ($d->items as $it) {
                $alreadyDelivered[$it->product_id] = ($alreadyDelivered[$it->product_id] ?? 0) + (float) $it->qty;
            }
        }

        $auto = $svc->createFromInvoice($invoice, $alreadyDelivered);
        if ($auto) {
            $svc->post($auto->id);
            $svc->settleDeferredCogs($auto->id);
            $auto->load('items.product');
            $deliveries->push($auto);
        }

        return $deliveries;
    }

    /**
     * 🔥 ASSIGN HPP DARI SJ KE INVOICE
     * Mendukung produk tunggal maupun Bundle (Sum HPP komponen)
     */
    protected function assignHppFromDelivery($invoice, $deliveries)
    {
        $totalHpp = 0;

        // Gabung item dari SEMUA SJ (partial delivery → 1 invoice bisa banyak SJ).
        $deliveryItems = collect();
        foreach ($deliveries as $d) {
            foreach ($d->items as $it) {
                $deliveryItems->push($it);
            }
        }

        // HPP satu produk di SJ dibagi ke SEMUA baris faktur yang memakai produk itu, sebanding
        // qty-nya. Dulu tiap baris mengambil HPP produk itu SEUTUHNYA — pesanan Jubelio yang
        // memecah "qty 5" jadi 5 baris @1 (atau dua bundle berbagi komponen) membukukan HPP
        // berlipat. Faktur satu baris per produk hasilnya tetap sama persis (porsi = 100%).
        $porsi = [];     // invoice_item_id => [product_id => qty yang dipakai baris itu]
        $pemakaian = []; // product_id => total qty dipakai semua baris faktur
        foreach ($invoice->items as $invItem) {
            $product = $invItem->product;
            if (!$product || in_array($product->type, ['service', 'non_stock'], true)) {
                continue;
            }
            $pakai = [];
            if ($product->type === 'bundle') {
                $components = \App\Core\Inventory\BundleComponent::where('bundle_product_id', $invItem->product_id)->get();
                $takaranField = 'qty';
                if ($components->isEmpty()) {
                    $components = $product->bundleItems;
                    $takaranField = 'qty_required';
                }
                foreach ($components as $comp) {
                    $pakai[$comp->component_product_id] = ($pakai[$comp->component_product_id] ?? 0)
                        + (float) $invItem->qty * (float) ($comp->{$takaranField} ?? 1);
                }
            } else {
                $pakai[$invItem->product_id] = (float) $invItem->qty;
            }
            $porsi[$invItem->id] = $pakai;
            foreach ($pakai as $pid => $q) {
                $pemakaian[$pid] = ($pemakaian[$pid] ?? 0) + $q;
            }
        }
        $bagian = function ($invItem, $pid) use ($porsi, $pemakaian) {
            $q = $porsi[$invItem->id][$pid] ?? 0;
            $total = $pemakaian[$pid] ?? 0;
            return $total > 0 ? $q / $total : 1.0;
        };

        foreach ($invoice->items as $invItem) {
            $product = $invItem->product;
            $cogsTotalForItem = 0;

            // Jasa & non_stock tidak punya HPP — tidak ada barang yang dikirim/dikonsumsi via FIFO.
            if ($product && in_array($product->type, ['service', 'non_stock'], true)) {
                $invItem->cogs_total = 0;
                $invItem->save();
                continue;
            }

            if ($product->type === 'bundle') {
                // HPP bundle = SUM HPP komponen dari semua SJ
                $components = \App\Core\Inventory\BundleComponent::where('bundle_product_id', $invItem->product_id)->get();
                $compIdField = 'component_product_id';

                if ($components->isEmpty()) {
                    $components = $product->bundleItems;
                }

                foreach ($components as $comp) {
                    $matched = $deliveryItems->where('product_id', $comp->{$compIdField});

                    if ($matched->isEmpty()) {
                        throw new \Exception("Gagal kalkulasi HPP Bundle: Komponen ID {$comp->{$compIdField}} tidak ditemukan di Surat Jalan.");
                    }

                    if ($matched->contains(fn($i) => is_null($i->cogs_total))) {
                        throw new \Exception("Kalkulasi HPP Bundle Gagal: COGS komponen ID {$comp->{$compIdField}} belum tersedia. Selesaikan produksi terlebih dahulu.");
                    }

                    $cogsTotalForItem += (float) $matched->sum('cogs_total') * $bagian($invItem, $comp->{$compIdField});
                }
            } else {
                $matched = $deliveryItems->where('product_id', $invItem->product_id);

                if ($matched->isEmpty()) {
                    throw new \Exception("Data HPP Gagal: Produk ID {$invItem->product_id} tidak ditemukan di Surat Jalan.");
                }

                // 🔥 Guard: pastikan tidak ada COGS yang masih null (ditunda & belum di-settle)
                if ($matched->contains(fn($i) => is_null($i->cogs_total))) {
                    throw new \Exception("Kalkulasi HPP Gagal: COGS untuk produk ID {$invItem->product_id} belum tersedia. Selesaikan produksi terlebih dahulu sebelum invoice.");
                }

                $cogsTotalForItem = (float) $matched->sum('cogs_total') * $bagian($invItem, $invItem->product_id);
            }

            // Simpan HPP ke item invoice
            $invItem->cogs_total = round($cogsTotalForItem, 2);
            $invItem->save();

            $totalHpp += round($cogsTotalForItem, 2);
        }

        $invoice->hpp_total = $totalHpp;
        $invoice->save();
    }

    protected function createJournalHeader($invoice)
    {
        $period = \App\Core\Period\AccountingPeriod::where('year', $invoice->invoice_date->year)
            ->where('month', $invoice->invoice_date->month)->first();

        if (!$period) {
            throw new \Exception('Accounting period not found');
        }

        // Nomor bisa tetap bentrok bila dua proses (webhook Jubelio + cron)
        // memposting bersamaan: baris proses lain belum ter-commit sehingga
        // tak terlihat saat nomor dihitung. Duplikat di MySQL hanya
        // membatalkan statement-nya, bukan transaksinya — cukup coba lagi.
        for ($attempt = 1; ; $attempt++) {
            try {
                $journal = \App\Core\Journal\Journal::create([
                    'journal_number'   => $this->generateJournalNumber(),
                    'date'             => $invoice->invoice_date,
                    'period_id'        => $period->id,
                    'reference_type'   => 'sales_invoice',
                    'reference_id'     => $invoice->id,
                    'reference_number' => $invoice->invoice_number,
                    'description'      => 'Sales Invoice ' . $invoice->invoice_number,
                ]);
                break;
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                if ($attempt >= 5) {
                    throw $e;
                }
                usleep(random_int(50, 250) * 1000);
            }
        }

        $invoice->journal_id = $journal->id;
        $invoice->save();

        return $journal;
    }

    protected function postHpp($invoice)
    {
        if (!$invoice->hpp_total)
            return;

        $hppAccountId = $this->getAccountIdByCode($this->account('hpp'));
        $inventoryAccountId = $this->getAccountIdByCode($this->account('inventory'));

        $this->createJournalLine($invoice, $hppAccountId, 'debit', $invoice->hpp_total);
        $this->createJournalLine($invoice, $inventoryAccountId, 'credit', $invoice->hpp_total);
    }

    protected function postRevenue($invoice)
    {
        // Gunakan akun piutang standar (1120) untuk semua invoice.
        // Jika ini marketplace, pengurangan piutang akan dilakukan oleh applyAdvance.
        $arAccountId = $this->getAccountIdByCode($this->account('ar'));

        $defaultRevenueAccountId = $this->getAccountIdByCode($this->account('revenue'));
        $discountAccount = $this->getAccountIdByCode($this->account('discount'));

        $this->createJournalLine($invoice, $arAccountId, 'debit', $invoice->grand_total);

        // Pisah revenue per akun: jasa pakai revenue_account_id produk, sisanya pakai 4001 default.
        // Selisih pembulatan diserap ke akun default agar Cr revenue tepat sama dengan invoice->subtotal.
        $invoice->loadMissing('items.product');
        $revenueByAccount = [];
        $sumNonDefault = 0.0;

        foreach ($invoice->items as $invItem) {
            $product = $invItem->product;
            $itemSubtotal = (float) $invItem->subtotal;
            if ($itemSubtotal == 0) continue;

            $accountId = ($product && $product->type === 'service' && $product->revenue_account_id)
                ? (int) $product->revenue_account_id
                : (int) $defaultRevenueAccountId;

            if ($accountId !== (int) $defaultRevenueAccountId) {
                $rounded = round($itemSubtotal, 2);
                $revenueByAccount[$accountId] = round(($revenueByAccount[$accountId] ?? 0) + $rounded, 2);
                $sumNonDefault += $rounded;
            }
        }

        $defaultAmount = round((float) $invoice->subtotal - $sumNonDefault, 2);
        if ($defaultAmount > 0) {
            $this->createJournalLine($invoice, $defaultRevenueAccountId, 'credit', $defaultAmount);
        }
        foreach ($revenueByAccount as $accId => $amount) {
            if ($amount > 0) {
                $this->createJournalLine($invoice, $accId, 'credit', $amount);
            }
        }

        if ($invoice->global_discount_amount > 0) {
            $this->createJournalLine($invoice, $discountAccount, 'debit', $invoice->global_discount_amount);
        }

        // Penyesuaian nominal unik toko online, dibukukan lewat akun potongan penjualan:
        //  - POSITIF (transfer bank): piutang lebih KECIL dari nilai barang → Dr potongan.
        //  - NEGATIF (QRIS): penyedia menambah selisih unik sehingga pembeli membayar
        //    sedikit lebih besar → Cr potongan (mengurangi total potongan penjualan).
        $uniqueCode = (int) $invoice->unique_code;
        if ($uniqueCode > 0) {
            $this->createJournalLine($invoice, $discountAccount, 'debit', $uniqueCode);
        } elseif ($uniqueCode < 0) {
            $this->createJournalLine($invoice, $discountAccount, 'credit', abs($uniqueCode));
        }

        // Biaya admin/layanan marketplace: pendapatan tetap diakui penuh (Cr 4001 = subtotal),
        // potongan marketplace dibukukan sbg BEBAN ke akun fee yang dimapping (DEBIT),
        // BUKAN diskon penjualan. Tanpa akun fee → fallback ke akun diskon agar tetap balance.
        if ($invoice->marketplace_fee > 0) {
            $feeAccountId = \App\Models\MarketplaceConfig::where('customer_id', $invoice->customer_id)
                ->where('is_active', true)
                ->value('account_fee_id');
            if (!$feeAccountId) {
                \Illuminate\Support\Facades\Log::warning('InvoicePosting: marketplace_fee tanpa account_fee_id — fallback ke akun diskon', [
                    'invoice' => $invoice->id, 'customer' => $invoice->customer_id, 'fee' => (float) $invoice->marketplace_fee,
                ]);
                $feeAccountId = $discountAccount;
            }
            $this->createJournalLine($invoice, $feeAccountId, 'debit', $invoice->marketplace_fee);
        }

        if ($invoice->ppn_amount > 0) {
            $ppnAccountId = $this->getAccountIdByCode($this->account('ppn'));
            $this->createJournalLine($invoice, $ppnAccountId, 'credit', $invoice->ppn_amount);
        }

        // PPh dipotong customer mengurangi kas yang diterima (grand_total sudah dikurangi
        // PPh), namun nilainya adalah kredit pajak yang dapat ditagih → DEBIT aset
        // "PPh Dibayar Dimuka". Tanpa baris ini jurnal tidak balance sebesar pph_amount.
        if ($invoice->pph_amount > 0) {
            $pphAccountId = $this->getAccountIdByCode($this->account('pph'));
            $this->createJournalLine($invoice, $pphAccountId, 'debit', $invoice->pph_amount);
        }

        if ($invoice->additional_fee > 0) {
            $additionalAccount = $this->getAccountIdByCode($this->account('additional_revenue'));
            $this->createJournalLine($invoice, $additionalAccount, 'credit', $invoice->additional_fee);
        }
    }

    protected function postTitipan($invoice)
    {
        if ($invoice->shipping_cost > 0) {
            $titipanAccountId = $this->getAccountIdByCode($this->account('titipan'));
            $this->createJournalLine($invoice, $titipanAccountId, 'credit', $invoice->shipping_cost);
        }
    }

    protected function applyAdvance($invoice)
    {
        if (!$invoice->sales_order_id) {
            return;
        }

        // 1. Hitung TOTAL Saldo Uang Muka yang pernah DIPOSTING untuk SO ini
        $totalAdvancePosted = \App\Modules\Sales\Models\SalesAdvance::where('sales_order_id', $invoice->sales_order_id)
            ->where('status', 'posted')
            ->sum(\DB::raw('amount + credit_used'));

        // 2. Hitung TOTAL Uang Muka yang sudah TERPAKAI oleh Invoice lain (yang sudah posted)
        $totalAdvanceUsed = \App\Models\SalesInvoice::where('sales_order_id', $invoice->sales_order_id)
            ->where('status', 'posted')
            ->where('id', '!=', $invoice->id)
            ->sum('advance_applied');

        $availableBalance = max(0, $totalAdvancePosted - $totalAdvanceUsed);

        // 3. Tentukan berapa yang bisa di-apply (tidak boleh melebihi available & tidak boleh melebihi grand_total)
        $intendedAmount = $invoice->advance_applied;
        $actualApply = min($intendedAmount, $availableBalance);

        if ($actualApply > 0) {
            $advanceAccountId = $this->getAccountIdByCode($this->account('advance'));
            $arAccountId = $this->getAccountIdByCode($this->account('ar'));

            // Debet: Uang Muka (Mengurangi Hutang/Liability)
            $this->createJournalLine($invoice, $advanceAccountId, 'debit', $actualApply);
            
            // Kredit: Piutang (Mengurangi Tagihan)
            $this->createJournalLine($invoice, $arAccountId, 'credit', $actualApply);

            // Update ke invoice agar sinkron dengan kenyataan posting
            $invoice->advance_applied = $actualApply;

            // Status invoice tetap 'posted' meski lunas — kondisi "lunas" dihitung dari
            // remaining_amount (grand_total - paid - advance), bukan disimpan sebagai status.
            // Enum InvoiceStatusEnum hanya mengizinkan: draft | posted | void.
            $invoice->save();
        } else {
            // Jika tidak ada saldo tersedia, pastikan field di invoice di-reset ke 0
            $invoice->advance_applied = 0;
            $invoice->save();
        }
    }

    protected function validateBalance($invoice)
    {
        $lines = \App\Core\Journal\JournalLine::where('journal_id', $invoice->journal_id)->get();
        $debit = round($lines->sum('debit'), 2);
        $credit = round($lines->sum('credit'), 2);

        // Bandingkan dgn toleransi (float strict !== rawan false-positive).
        if (abs($debit - $credit) > 0.005) {
            throw new \Exception("Journal not balanced: Dr=$debit Cr=$credit");
        }
    }

    /**
     * Nomor = angka TERBESAR yang sudah dipakai prefiks bulan ini + 1.
     *
     * Dulu dihitung dari JUMLAH jurnal yang TANGGAL-nya bulan ini, padahal
     * prefiksnya diambil dari bulan saat posting. Faktur marketplace sering
     * bertanggal bulan lalu → memakai nomor bulan ini tanpa ikut terhitung →
     * faktur berikutnya mendapat nomor yang sama ("Duplicate entry JV/…").
     * Angka dibaca numerik, bukan urut string, supaya tetap benar lewat 9999.
     */
    protected function generateJournalNumber()
    {
        $prefix = 'JV/' . now()->format('Y/m') . '/';
        $max = (int) \App\Core\Journal\Journal::where('journal_number', 'like', $prefix . '%')
            ->selectRaw('MAX(CAST(SUBSTRING(journal_number, ?) AS UNSIGNED)) AS n', [strlen($prefix) + 1])
            ->value('n');

        return $prefix . str_pad($max + 1, 4, '0', STR_PAD_LEFT);
    }

    protected function createJournalLine($invoice, $accountId, $type, $amount)
    {
        \App\Core\Journal\JournalLine::create([
            'journal_id'       => $invoice->journal_id,
            'account_id'       => $accountId,
            'debit'            => $type === 'debit' ? $amount : 0,
            'credit'           => $type === 'credit' ? $amount : 0,
            'reference_type'   => 'sales_invoice',
            'reference_id'     => $invoice->id,
            'reference_number' => $invoice->invoice_number,
        ]);
    }

    protected function getAccountIdByCode($code)
    {
        $id = \App\Core\Accounting\Account::where('code', $code)->value('id');

        if (!$id) {
            throw new \Exception("Account code NOT found in database: $code");
        }

        return $id;
    }
}