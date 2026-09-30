<?php

namespace App\Modules\Sales\Services;

use App\DTO\SalesReturnDTO;
use App\Models\SalesInvoice;
use App\Enums\AccountCodeEnum;
use App\Core\Accounting\Account;
use App\DTO\JournalEntryDTO;
use App\DTO\JournalLineDTO;
use App\Core\Journal\JournalPostingService;
use App\Core\Inventory\FifoService;
use App\Core\Inventory\BundleComponent;
use App\Core\Inventory\ProductBundle;
use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnItem;
use App\Models\CustomerOverpayment;
use App\Services\NumberGeneratorService;
use Illuminate\Support\Facades\DB;
use Exception;

class SalesReturnService
{
    /**
     * Save return as draft (no accounting impact)
     *
     * Retur baru SELALU lahir di tahap `baru`, dari mana pun asalnya — tarikan Jubelio
     * maupun buatan tangan. Tahap `banding` tidak pernah jadi tempat lahir: ia hanya
     * dimasuki lewat tombol, saat seseorang memutuskan kasusnya disengketakan.
     */
    public function saveDraft(SalesReturnDTO $dto, string $stage = 'baru'): SalesReturn
    {
        return DB::transaction(function () use ($dto, $stage) {
            $doc = $this->getDoc($dto);
            $totals = $this->calculateTotals($dto, $doc);

            $stage = in_array($stage, SalesReturn::STAGES_AKTIF, true) ? $stage : 'baru';

            $return = SalesReturn::create([
                'return_number'  => NumberGeneratorService::generate('SR'),
                'customer_id'    => $dto->customer_id,
                'invoice_id'     => $dto->invoice_id,
                'sales_order_id' => $dto->sales_order_id,
                'return_date'    => $dto->date,
                'grand_total'    => $totals['net'],
                'status'         => 'draft',
                'stage'          => $stage,
                'return_type'            => $dto->return_type,
                'external_return_number' => $dto->external_return_number,
                'notes'                  => $dto->notes,
                // Draft boleh menyimpan jurnal yang belum seimbang; yang menuntut
                // seimbang adalah posting (lihat barisJurnalManual).
                'journal_override'       => $dto->journal_override ?: null,
                'appeal_result'          => $dto->appeal_result,
                'return_case'            => $dto->return_case,
                'journal_reversal'       => $dto->journal_reversal,
                'journal_settlement'     => $dto->journal_settlement,
            ]);

            foreach ($dto->items as $item) {
                $docItem = $doc->items->firstWhere('id', $item['invoice_item_id']);
                $docItemSubtotal = $docItem->subtotal ?? $docItem->line_total ?? 0;

                SalesReturnItem::create([
                    'sales_return_id'   => $return->id,
                    'reference_item_id' => $item['invoice_item_id'],
                    'product_id'        => $docItem->product_id,
                    'qty'               => $item['qty'],
                    'unit_price'        => $docItem->unit_price,
                    'subtotal'          => round(($docItemSubtotal / $docItem->qty) * $item['qty'], 2),
                    'condition'         => $item['condition'],
                    'component_conditions' => $this->normalizeComponentConditions($item['component_conditions'] ?? null),
                ]);
            }

            return $return;
        });
    }

    /**
     * Update an existing draft
     */
    public function updateDraft(int $id, SalesReturnDTO $dto): SalesReturn
    {
        return DB::transaction(function () use ($id, $dto) {
            $return = SalesReturn::findOrFail($id);
            if ($return->status !== 'draft') {
                throw new Exception('Only draft returns can be updated.');
            }

            $doc = $this->getDoc($dto);
            $totals = $this->calculateTotals($dto, $doc);

            /*
             * Tahap TIDAK bergerak sendiri saat data diisi. Dulu mengisi jenis retur
             * diam-diam memindahkan kasusnya ke tahap berikutnya, dan CS kehilangan
             * jejak barang yang baru saja ia ketik. Sekarang yang memindahkan kasus
             * hanya dua tombol yang memang berbunyi begitu: "Ajukan Banding" dan
             * "Selesaikan Retur".
             */
            $return->update([
                'return_date' => $dto->date,
                'grand_total' => $totals['net'],
                'return_type'            => $dto->return_type,
                'external_return_number' => $dto->external_return_number,
                'notes'                  => $dto->notes,
                // Draft boleh menyimpan jurnal yang belum seimbang; yang menuntut
                // seimbang adalah posting (lihat barisJurnalManual).
                'journal_override'       => $dto->journal_override ?: null,
                'appeal_result'          => $dto->appeal_result,
                'return_case'            => $dto->return_case,
                'journal_reversal'       => $dto->journal_reversal,
                'journal_settlement'     => $dto->journal_settlement,
            ]);

            $return->items()->delete();

            foreach ($dto->items as $item) {
                $docItem = $doc->items->firstWhere('id', $item['invoice_item_id']);
                $docItemSubtotal = $docItem->subtotal ?? $docItem->line_total ?? 0;

                SalesReturnItem::create([
                    'sales_return_id'   => $return->id,
                    'reference_item_id' => $item['invoice_item_id'],
                    'product_id'        => $docItem->product_id,
                    'qty'               => $item['qty'],
                    'unit_price'        => $docItem->unit_price,
                    'subtotal'          => round(($docItemSubtotal / $docItem->qty) * $item['qty'], 2),
                    'condition'         => $item['condition'],
                    'component_conditions' => $this->normalizeComponentConditions($item['component_conditions'] ?? null),
                ]);
            }

            return $return;
        });
    }

    /**
     * Post the return (creates journal entries and updates inventory)
     */
    public function post(SalesReturnDTO $dto, ?int $existingReturnId = null): SalesReturn
    {
        return DB::transaction(function () use ($dto, $existingReturnId) {
            $doc = $this->getDoc($dto);
            $this->validatePosting($dto, $doc, $existingReturnId);
            $totals = $this->calculateTotals($dto, $doc);

            if ($existingReturnId) {
                $return = SalesReturn::findOrFail($existingReturnId);
                $return->update([
                    'return_date' => $dto->date,
                    'grand_total' => $totals['net'],
                    'status'      => 'posted',
                    'stage'       => 'selesai',
                    'return_type'            => $dto->return_type ?? $return->return_type,
                    'external_return_number' => $dto->external_return_number ?? $return->external_return_number,
                    'notes'                  => $dto->notes ?? $return->notes,
                ]);
                $return->items()->delete();
                foreach ($dto->items as $item) {
                    $this->storeReturnItem($return->id, $doc, $item);
                }
            } else {
                $return = SalesReturn::create([
                    'return_number'  => NumberGeneratorService::generate('SR'),
                    'customer_id'    => $dto->customer_id,
                    'invoice_id'     => $dto->invoice_id,
                    'sales_order_id' => $dto->sales_order_id,
                    'return_date'    => $dto->date,
                    'grand_total'    => $totals['net'],
                    'status'         => 'posted',
                    'stage'          => 'selesai',
                    'return_type'            => $dto->return_type,
                    'external_return_number' => $dto->external_return_number,
                        'notes'                  => $dto->notes,
                ]);

                foreach ($dto->items as $item) {
                    $this->storeReturnItem($return->id, $doc, $item);
                }
            }

            if ($this->modeTigaBlok($dto, $doc)) {
                $this->postTigaBlok($dto, $doc, $return);

                return $return;
            }

            // Penjualan hanya dibalik sebesar baris yang BUKAN `tidak_kembali`. Baris
            // `tidak_kembali` dananya diganti marketplace, jadi omzet & HPP-nya tetap sah dan
            // barangnya memang tidak pernah kembali — tidak ada yang perlu dibalik untuk baris
            // itu. Kalau semua barisnya `tidak_kembali`, jurnalnya kosong sama sekali dan
            // dokumen retur murni jadi catatan kasus.
            $isSO = (bool) $dto->sales_order_id;

            /*
             * Blok DANA boleh ditulis tangan. Retur yang diajukan konsumen hasilnya
             * bermacam-macam — ganti penuh, sebagian, atau tak sama sekali — dan tak
             * semuanya bisa disimpulkan dari kondisi barang. Kalau baris manual ada,
             * ia MENGGANTI blok dana; yang tidak pernah bisa diganti adalah blok
             * BARANG di bawah, karena itu cerminan pergerakan stok yang benar-benar
             * terjadi dan mengetiknya sendiri membuat buku besar berbeda dari kartu stok.
             */
            $manual = $this->barisJurnalManual($dto->journal_override);

            $journalLines = [];
            if ($manual) {
                $journalLines = $manual;
            } elseif ($totals['reversed'] > 0) {
                $journalLines = array_merge($journalLines, $this->getRevenueReversalLines($dto, $doc, $totals['reversed'], $isSO));
            }
            $journalLines = array_merge($journalLines, $this->getCogsReversalLines($dto, $doc, $return->id));

            if (!empty($journalLines)) {
                $docNumber = $doc->invoice_number ?? $doc->order_number;
                $description = "Sales Return Reversal (Ref: {$docNumber})";

                app(JournalPostingService::class)->post(new JournalEntryDTO(
                    date: $dto->date,
                    reference_type: 'sales_return',
                    reference_id: $return->id,
                    description: $description,
                    lines: $journalLines,
                    reference_number: $return->return_number
                ));
            }

            // Kolam saldo kredit hanya bertambah bila uangnya memang DIJADIKAN kredit —
            // bukan setiap kali pelanggan biasa meretur. Retur yang uangnya ditransfer balik
            // atau dipotong dari dompet marketplace tidak menciptakan hak beli apa pun, dan
            // menambahkannya ke kolam berarti pelanggan dibayar dua kali.
            if ($manual) {
                $return->forceFill(['journal_override' => $dto->journal_override])->save();
            }

            /*
             * Saat jurnal dananya ditulis tangan, angka penanganan dana sistem TIDAK ikut
             * dihitung: yang berwenang sudah baris manual itu. Menuliskan keduanya membuat
             * kolom refund_amount bercerita lain daripada jurnalnya sendiri.
             */
            if (!$isSO && !$manual && $totals['reversed'] > 0) {
                $uang = $this->hitungUang($doc, $totals['reversed'], $dto->refund_target, $dto->refund_amount);

                $return->forceFill([
                    'refund_target'      => $uang['target'],
                    'refund_account_id'  => $dto->refund_account_id,
                    'refund_customer_id' => $dto->refund_customer_id,
                    'refund_amount'      => $uang['refund'],
                    'fee_reversed'       => $uang['fee'],
                ])->save();

                if ($uang['target'] === 'credit' && $uang['refund'] > 0) {
                    CustomerOverpayment::create([
                        'customer_id' => $dto->refund_customer_id ?: $dto->customer_id,
                        'amount'      => $uang['refund'],
                        'reference'   => $return->return_number,
                        'note'        => 'Retur ' . $return->return_number,
                    ]);
                }
            }

            if (!$isSO && $doc instanceof SalesInvoice) {
                $this->catatPiutangDihapus($return, $doc);
                $this->selesaikanFakturBelumCair($return, $doc->fresh(), $dto->date);
            }

            return $return;
        });
    }

    // ─────────────────────────── Jurnal tiga blok ───────────────────────────
    //
    // Form retur menyusun jurnal dalam tiga blok yang menjawab tiga pertanyaan berbeda:
    //   a. HPP          — barangnya ke mana? Selalu dihitung dari kondisi barang, TAK bisa
    //                     diubah (cerminan kartu stok; lihat getCogsReversalLines).
    //   b. Pembalikan   — berapa penjualan yang batal, dan titipan pembeli dikembalikan.
    //   c. Penyelesaian — uang pesanan berakhir di mana: sisa titipan dicairkan ke dompet,
    //                     dipotong biaya admin (termasuk Hemat Biaya Kirim) / pajak.
    // Blok b & c terisi bawaan dari jurnalBawaan() dan boleh diubah admin; yang dijaga saat
    // posting cuma keseimbangan & batas saldo nyata pesanan (piutang, uang muka, saldo
    // ditahan) — supaya setelah pesanan tuntas ketiganya tetap NOL, tidak minus.

    /** Retur ini memakai jurnal tiga blok? (Dokumen atas SO & pemanggil lama tidak.) */
    private function modeTigaBlok(SalesReturnDTO $dto, $doc): bool
    {
        return $doc instanceof SalesInvoice
            && ($dto->journal_reversal !== null || $dto->journal_settlement !== null);
    }

    /**
     * Isi bawaan blok Pembalikan & Penyelesaian untuk sebuah faktur.
     *
     * @param array       $items   [['invoice_item_id'=>int,'qty'=>float,'condition'=>string], ...]
     * @param string|null $banding 'menang' | 'kalah' | null
     * @param float|null  $dibalikDiminta nilai penjualan yang dibalik hasil negosiasi (refund
     *   sebagian). NULL = seluruh nilai barang yang tidak "dana diganti".
     * @return array{pembalikan:array, penyelesaian:?array, nilai:float, dibalik:float, konteks:array, target:string, tujuan:array}
     */
    public function jurnalBawaan(SalesInvoice $inv, array $items, ?string $jenis, ?string $banding, ?string $target = null, ?int $refundAccountId = null, ?float $dibalikDiminta = null): array
    {
        $inv->loadMissing('items', 'customer');
        $akun   = $this->akunDana($inv->customer_id);
        $config = \App\Models\MarketplaceConfig::where('customer_id', $inv->customer_id)->first();

        // Nilai jual barang yang diretur & bagian yang membalik penjualan.
        $nilai = 0.0;
        foreach ($items as $it) {
            $docItem = $inv->items->firstWhere('id', (int) ($it['invoice_item_id'] ?? 0));
            $qty = (float) ($it['qty'] ?? 0);
            if (!$docItem || $qty <= 0 || (float) $docItem->qty <= 0) {
                continue;
            }
            $nilai += (float) $docItem->subtotal * $qty / (float) $docItem->qty;
        }
        $nilai = round($nilai, 2);
        // Penjualan dibalik atau tidak ditentukan KASUSNYA, bukan kondisi barang (kondisi hanya
        // mengurus HPP). Tidak dibalik = dananya tetap milik kita: paket hilang yang klaimnya
        // tidak ditolak, atau banding yang menang.
        $tetapSah = ($jenis === 'paket_hilang' && $banding !== 'kalah') || $banding === 'menang';
        $dibalik  = $tetapSah ? 0.0 : $nilai;
        if ($dibalikDiminta !== null) {
            $dibalik = round(max(0, $dibalikDiminta), 2);
        }

        $tujuan = $this->tujuanTersedia($inv);
        $target = $target && isset($tujuan[$target]) ? $target : $this->tujuanDanaBawaan($inv);

        $baris = fn (?array $a, float $debit, float $credit, string $memo, bool $auto = false) => [
            'account_id' => $a['id'] ?? null,
            'code'       => $a['code'] ?? '',
            'name'       => $a['name'] ?? '',
            'debit'      => round(max(0, $debit), 2),
            'credit'     => round(max(0, $credit), 2),
            'memo'       => $memo,
            'auto'       => $auto,
        ];
        $akunId = function (?int $id): ?array {
            $a = $id ? Account::find($id, ['id', 'code', 'name']) : null;

            return $a ? ['id' => (int) $a->id, 'code' => (string) $a->code, 'name' => (string) $a->name] : null;
        };

        // ── b. Pembalikan ──
        $pembalikan = [];
        $uang = ['ar' => 0.0, 'hold' => 0.0];
        if ($dibalik > 0) {
            $uang = $this->hitungUang($inv, $dibalik, $target);
            $pembalikan[] = $baris($akun['retur'], $dibalik, 0, 'Retur penjualan - ' . $inv->invoice_number);
            if ($uang['ar'] > 0) {
                $pembalikan[] = $baris($akun['piutang'], 0, $uang['ar'], 'Tagihan dihapus');
            }
            if ($uang['refund'] > 0) {
                $tujuanAkun = match ($target) {
                    'hold'   => $akun['ditahan'],
                    'wallet' => $akun['dompet'],
                    'bank'   => $akunId($refundAccountId),
                    default  => $akun['kredit'],
                };
                $pembalikan[] = $baris($tujuanAkun, 0, $uang['refund'], 'Dana dikembalikan (' . (SalesReturn::REFUND_TARGETS[$target] ?? $target) . ')');
            }
            if ($uang['fee'] > 0) {
                $pembalikan[] = $baris($akun['admin'], 0, $uang['fee'], 'Biaya admin tidak dikembalikan ke pembeli');
            }
            if ($uang['hold'] > 0) {
                $pembalikan[] = $baris($akun['uang_muka'], $uang['hold'], 0, 'Titipan pembeli dikembalikan');
                $pembalikan[] = $baris($akun['ditahan'], 0, $uang['hold'], 'Saldo ditahan dilepas ke pembeli');
            }
        }

        // ── c. Penyelesaian — hanya faktur marketplace yang dananya belum dicairkan ──
        $penyelesaian = null;
        $sisaDitahan  = $this->sisaDitahan($inv);
        $sisaTagihan  = round(max(0, (float) $inv->remaining_amount), 2);
        $uangMuka     = app(MarketplaceEngineService::class)->uangMukaTersedia($inv);

        if ($config && $inv->fee_at_settlement && !$inv->marketplace_processed) {
            $penyelesaian = [];
            $holdSisa = round(max(0, $sisaDitahan - $uang['hold']), 2);
            $arSisa   = round(max(0, min($sisaTagihan - $uang['ar'], $uangMuka - $uang['hold'])), 2);

            if ($arSisa > 0) {
                $penyelesaian[] = $baris($akun['uang_muka'], $arSisa, 0, 'Faktur tuntas dari uang muka');
                $penyelesaian[] = $baris($akun['piutang'], 0, $arSisa, 'Piutang lunas');
            }

            // Kompensasi (paket hilang, gagal kirim yang banding-nya menang) dibayar KOTOR tanpa
            // biaya admin. Selain itu sisa pesanan dicairkan seperti penjualan biasa.
            $kompensasi = $jenis === 'paket_hilang' || ($jenis === 'gagal_kirim' && $banding === 'menang');
            $admin = (!$kompensasi && $holdSisa > 0)
                ? round($holdSisa * (float) $config->admin_fee_percent / 100 + (float) $config->admin_fee_fixed, 0)
                : 0.0;
            $pajak = $config->pajakDari($holdSisa);

            // Biaya Admin SELALU ada (0 untuk kompensasi): di sinilah juga Program Hemat Biaya
            // Kirim / premi asuransi diisi — sama seperti pesanan berhasil, semua potongan
            // marketplace selain pajak masuk Beban Admin.
            $penyelesaian[] = $baris($akun['admin'], $admin, 0, $kompensasi
                ? 'Biaya admin + Program Hemat Biaya Kirim (isi sesuai Seller Centre)'
                : 'Biaya admin + Program Hemat Biaya Kirim (taksiran — sesuaikan dgn Seller Centre)');
            $penyelesaian[] = $baris($akunId($config->account_tax_id), $pajak, 0, 'Pajak dipotong marketplace');

            // Saldo Penjualan = penyeimbang: mengikuti isian di atasnya sampai diubah sendiri.
            $dompet = round($holdSisa - $admin - $pajak, 2);
            $penyelesaian[] = $baris($akun['dompet'], max(0, $dompet), max(0, -$dompet), 'Dana cair ke Saldo Penjualan', true);

            if ($holdSisa > 0) {
                $penyelesaian[] = $baris($akun['ditahan'], 0, $holdSisa, 'Pelepasan saldo ditahan');
            }
        }

        return [
            'pembalikan'   => $pembalikan,
            'penyelesaian' => $penyelesaian,
            'nilai'        => $nilai,
            'dibalik'      => $dibalik,
            'target'       => $target,
            'tujuan'       => $tujuan,
            'konteks'      => [
                'sisa_tagihan' => $sisaTagihan,
                'sisa_ditahan' => $sisaDitahan,
                'uang_muka'    => $uangMuka,
                'akun'         => [
                    'piutang'   => $akun['piutang']['id'] ?? null,
                    'uang_muka' => $akun['uang_muka']['id'] ?? null,
                    'ditahan'   => $config?->account_receivable_hold_id ? (int) $config->account_receivable_hold_id : null,
                ],
            ],
        ];
    }

    /** Tujuan dana yang masuk akal untuk faktur ini (pilihan di blok Pembalikan). */
    public function tujuanTersedia(SalesInvoice $inv): array
    {
        if ($this->jenisRetur($inv) === 'marketplace') {
            return ['hold' => SalesReturn::REFUND_TARGETS['hold']];
        }

        $out = [];
        if ($inv->customer?->is_marketplace) {
            $out['wallet'] = SalesReturn::REFUND_TARGETS['wallet'];
        }
        $out['credit'] = SalesReturn::REFUND_TARGETS['credit'];
        $out['bank']   = SalesReturn::REFUND_TARGETS['bank'];

        return $out;
    }

    /**
     * Posting retur tiga blok. Blok HPP dihitung sistem; blok Pembalikan ikut jurnal retur,
     * blok Penyelesaian jadi jurnal pencairan faktur (`sales_invoice_settlement`) — persis
     * tempat MarketplaceEngineService menaruh pencairan pesanan normal, sehingga penanda
     * idempoten & pembatalannya (batalkanPenyelesaian) berlaku sama.
     */
    private function postTigaBlok(SalesReturnDTO $dto, SalesInvoice $inv, SalesReturn $return): void
    {
        $rev = $this->barisBlok($dto->journal_reversal, 'Pembalikan');
        $set = $this->barisBlok($dto->journal_settlement, 'Penyelesaian');

        $config = \App\Models\MarketplaceConfig::where('customer_id', $inv->customer_id)->first();
        $belumCair = $config && $inv->fee_at_settlement && !$inv->marketplace_processed;

        if ($set && !$belumCair) {
            throw new Exception('Blok Penyelesaian hanya untuk faktur marketplace yang dananya belum dicairkan. Faktur ' . $inv->invoice_number . ' sudah tuntas — kosongkan blok itu.');
        }

        // ── Batas saldo nyata pesanan ──
        $net = fn (array $lines, ?int $akunId, string $sisi) => $akunId
            ? round(collect($lines)->where('account_id', $akunId)->sum(fn ($l) => $sisi === 'kredit' ? $l->credit - $l->debit : $l->debit - $l->credit), 2)
            : 0.0;
        $semua    = array_merge($rev, $set);
        $piutang  = (int) $this->getAccountId(AccountCodeEnum::AR_RECEIVABLE);
        $uangMuka = (int) $this->getAccountId(AccountCodeEnum::SALES_ADVANCE);
        $ditahan  = $config?->account_receivable_hold_id ? (int) $config->account_receivable_hold_id : null;

        $sisaTagihan = round(max(0, (float) $inv->remaining_amount), 2);
        if ($net($semua, $piutang, 'kredit') > $sisaTagihan + 0.01) {
            throw new Exception('Piutang yang ditutup (' . rupiah($net($semua, $piutang, 'kredit')) . ') melebihi sisa tagihan faktur (' . rupiah($sisaTagihan) . ').');
        }
        $sisaDitahan = $this->sisaDitahan($inv);
        if ($ditahan && $net($semua, $ditahan, 'kredit') > $sisaDitahan + 0.01) {
            throw new Exception('Saldo Ditahan yang dilepas (' . rupiah($net($semua, $ditahan, 'kredit')) . ') melebihi titipan yang masih ditahan (' . rupiah($sisaDitahan) . ').');
        }
        $umTersedia = app(MarketplaceEngineService::class)->uangMukaTersedia($inv);
        if ($inv->sales_order_id && $net($semua, $uangMuka, 'debit') > $umTersedia + 0.01) {
            throw new Exception('Uang Muka yang dipakai (' . rupiah($net($semua, $uangMuka, 'debit')) . ') melebihi uang muka pesanan yang tersisa (' . rupiah($umTersedia) . ').');
        }

        // ── a + b: jurnal retur ──
        $lines = array_merge($rev, $this->getCogsReversalLines($dto, $inv, $return->id));
        if ($lines) {
            app(JournalPostingService::class)->post(new JournalEntryDTO(
                date: $dto->date,
                reference_type: 'sales_return',
                reference_id: $return->id,
                description: "Sales Return Reversal (Ref: {$inv->invoice_number})",
                lines: $lines,
                reference_number: $return->return_number
            ));
        }

        // Nilai penjualan yang benar-benar dibalik = mutasi akun pendapatan di blok Pembalikan.
        $revenueIds = Account::whereIn('id', collect($rev)->pluck('account_id')->unique())
            ->where('type', 'revenue')->pluck('id')->all();
        $dibalik = round(collect($rev)->whereIn('account_id', $revenueIds)->sum(fn ($l) => $l->debit - $l->credit), 2);

        $isi = [
            'appeal_result'      => $dto->appeal_result,
            'return_case'        => $dto->return_case,
            'journal_reversal'   => $dto->journal_reversal ?? [],
            'journal_settlement' => $dto->journal_settlement,
            'journal_override'   => null,
            'reversed_amount'    => max(0, $dibalik),
        ];

        // Dana dijadikan kredit belanja pelanggan → kolam saldo kredit bertambah.
        $kredit = $net($rev, (int) $this->getAccountId(AccountCodeEnum::CUSTOMER_OVERPAY), 'kredit');
        if ($kredit > 0) {
            CustomerOverpayment::create([
                'customer_id' => $dto->refund_customer_id ?: $dto->customer_id,
                'amount'      => $kredit,
                'reference'   => $return->return_number,
                'note'        => 'Retur ' . $return->return_number,
            ]);
            $isi += ['refund_target' => 'credit', 'refund_amount' => $kredit, 'refund_customer_id' => $dto->refund_customer_id];
        }
        $return->forceFill($isi)->save();

        $this->catatPiutangDihapus($return, $inv);

        // ── c: jurnal penyelesaian ──
        $inv = $inv->fresh();
        if ($belumCair && $set) {
            $tanggal = max(\Carbon\Carbon::parse($dto->date)->toDateString(), $inv->invoice_date?->toDateString() ?? $dto->date);
            $journal = app(JournalPostingService::class)->post(new JournalEntryDTO(
                date: $tanggal,
                reference_type: 'sales_invoice_settlement',
                reference_id: $inv->id,
                description: 'Penyelesaian pesanan via retur ' . $return->return_number . ' - ' . $inv->invoice_number,
                lines: $set,
                reference_number: $return->return_number
            ));

            $arApplied = $net($set, $piutang, 'kredit');
            $dompet    = $net($set, $config->account_wallet_id ? (int) $config->account_wallet_id : null, 'debit');
            // Potongan tercatat = nilai kotor yang tersisa − yang masuk dompet. Dengan begitu
            // rekonsiliasi (gross − net vs tercatat) persis membandingkan dana cair sebenarnya
            // dengan yang dibukukan di sini, apa pun pembagian admin / pajaknya.
            $potongan  = round(max(0, $this->kotorTersisa($inv) - $dompet), 2);

            $return->forceFill([
                'settlement_journal_id' => $journal->id,
                'settlement_ar_applied' => $arApplied,
                'settlement_fee'        => round($potongan - (float) $inv->marketplace_fee, 2),
            ])->save();

            $inv->update([
                'advance_applied'       => round((float) $inv->advance_applied + $arApplied, 2),
                'marketplace_processed' => true,
                'marketplace_fee'       => $potongan,
            ]);
        } elseif ($belumCair && $this->sisaDitahan($inv) <= 0.005) {
            // Seluruh titipan sudah dikembalikan ke pembeli — tak ada yang tersisa untuk cair.
            $inv->update(['marketplace_processed' => true]);
        }

        app(MarketplaceEngineService::class)->cocokkanUlangRekonsiliasi($inv->fresh());
    }

    /** Nilai kotor pesanan yang tersisa setelah semua retur posted membalik penjualannya. */
    private function kotorTersisa(SalesInvoice $inv): float
    {
        $dibalik = SalesReturn::where('status', 'posted')
            ->where(fn ($w) => $w->where('invoice_id', $inv->id)
                ->when($inv->sales_order_id, fn ($q) => $q->orWhere('sales_order_id', $inv->sales_order_id)))
            ->get()
            ->sum(fn (SalesReturn $r) => $r->reversedAmount());

        return round(max(0, (float) $inv->grand_total - $dibalik), 2);
    }

    /** barisJurnalManual dengan nama blok di pesan galatnya. */
    private function barisBlok(?array $raw, string $blok): array
    {
        try {
            return $this->barisJurnalManual($raw);
        } catch (Exception $e) {
            throw new Exception("Blok {$blok}: " . $e->getMessage());
        }
    }

    /**
     * Isi blok untuk retur yang diposting TANPA lewat form (tombol posting di daftar):
     * pakai yang tersimpan di draft; kalau belum pernah disusun, pakai bawaan sistem.
     *
     * @return array{0:?array,1:?array} [pembalikan, penyelesaian]
     */
    public function blokUntukPosting(SalesReturn $return): array
    {
        if ($return->journal_reversal !== null || $return->journal_settlement !== null) {
            return [$return->journal_reversal ?? [], $return->journal_settlement];
        }

        // Dokumen lama yang dananya ditulis tangan dengan cara lama — jadikan blok Pembalikan.
        if (!empty($return->journal_override)) {
            return [$return->journal_override, null];
        }

        $inv = $return->invoice_id ? SalesInvoice::find($return->invoice_id) : null;
        if (!$inv) {
            return [null, null];
        }

        $items = $return->items->map(fn ($i) => [
            'invoice_item_id' => $i->reference_item_id, 'qty' => $i->qty, 'condition' => $i->condition,
        ])->all();
        $b = $this->jurnalBawaan($inv, $items, $return->return_type, $return->appeal_result, $return->refund_target, $return->refund_account_id);

        return [self::barisTersimpan($b['pembalikan']), self::barisTersimpan($b['penyelesaian'])];
    }

    /** Baris bawaan → bentuk yang disimpan & diposting (tanpa baris nol / tanpa akun). */
    public static function barisTersimpan(?array $rows): ?array
    {
        if ($rows === null) {
            return null;
        }

        return array_values(array_map(
            fn ($r) => ['account_id' => (int) $r['account_id'], 'debit' => (float) $r['debit'], 'credit' => (float) $r['credit'], 'memo' => $r['memo'] ?? null],
            array_filter($rows, fn ($r) => !empty($r['account_id']) && ((float) $r['debit'] > 0 || (float) $r['credit'] > 0))
        ));
    }


    /**
     * Faktur perlu tahu berapa piutangnya yang sudah dihapus retur. Angkanya dibaca dari
     * jurnal retur itu sendiri (baris Piutang), jadi berlaku sama untuk hitungan sistem
     * maupun jurnal dana yang ditulis tangan.
     */
    private function catatPiutangDihapus(SalesReturn $return, SalesInvoice $invoice): void
    {
        $arId = (int) Account::where('code', AccountCodeEnum::AR_RECEIVABLE)->value('id');
        if (!$arId) {
            return;
        }

        $ar = round((float) DB::table('journal_lines as jl')
            ->join('journals as j', 'j.id', '=', 'jl.journal_id')
            ->where('j.reference_type', 'sales_return')
            ->where('j.reference_id', $return->id)
            ->where('j.status', '!=', 'void')
            ->where('jl.account_id', $arId)
            ->selectRaw('SUM(jl.credit) - SUM(jl.debit) v')->value('v'), 2);

        if ($ar > 0) {
            $return->forceFill(['ar_credited' => $ar])->save();
            $invoice->increment('returned_amount', $ar);
        }
    }

    /**
     * Posting retur = penyelesaian faktur marketplace yang dananya BELUM CAIR.
     *
     * Pesanan yang berakhir retur tidak pernah berstatus "selesai" di Jubelio, jadi pencairan
     * yang biasanya jalan saat itu (Saldo Ditahan → Saldo Penjualan) tak pernah terjadi:
     * fakturnya menggantung "Belum Cair" dan Saldo Ditahan tak pernah turun — padahal untuk
     * barang yang dananya DIGANTI (paket hilang, banding menang) marketplace sudah membayar.
     * Di sini sisa yang belum dikembalikan ke pembeli dicairkan lewat engine yang sama:
     *
     *     Dr Uang Muka / Cr Piutang              (faktur tuntas)
     *     Dr Saldo Penjualan / Cr Saldo Ditahan  (dana masuk dompet)
     *
     * Fee SENGAJA 0: potongan untuk dana pengganti bukan biaya admin (umumnya premi asuransi,
     * jauh lebih kecil) — selisih terhadap dana cair sebenarnya dibukukan rekonsiliasi.
     *
     * Tanggal = tanggal retur, tapi tak pernah sebelum tanggal faktur: melunasi faktur yang
     * belum terbit tidak masuk akal di buku besar.
     */
    private function selesaikanFakturBelumCair(SalesReturn $return, SalesInvoice $invoice, string $tanggalRetur): void
    {
        if (!$invoice->fee_at_settlement || $invoice->marketplace_processed) {
            return;
        }

        $tanggalFaktur = $invoice->invoice_date?->toDateString() ?? $tanggalRetur;
        $tanggal       = max(\Carbon\Carbon::parse($tanggalRetur)->toDateString(), $tanggalFaktur);
        $uangMukaAwal  = (float) $invoice->advance_applied;

        $journal = app(MarketplaceEngineService::class)->handle($invoice, 0.0, $tanggal);

        if ($journal) {
            $return->forceFill([
                'settlement_journal_id' => $journal->id,
                'settlement_ar_applied' => round((float) $invoice->fresh()->advance_applied - $uangMukaAwal, 2),
            ])->save();
        }
    }

    /**
     * Kebalikan dari penyelesaian di atas — dipanggil saat retur di-void, SETELAH jurnal
     * returnya sendiri di-void. Faktur kembali ke keadaan sebelum retur: piutang yang dihapus
     * dibuka lagi, dan pencairan yang dipicu retur ini dibatalkan sehingga fakturnya kembali
     * "Belum Cair" dan bisa dicairkan lagi saat pesanannya benar-benar selesai.
     */
    public function batalkanPenyelesaian(SalesReturn $return): void
    {
        $invoice = $return->invoice_id ? SalesInvoice::find($return->invoice_id) : null;
        if (!$invoice) {
            return;
        }

        $isi = [];
        if ($return->settlement_journal_id) {
            \App\Core\Journal\Journal::whereKey($return->settlement_journal_id)
                ->update(['status' => 'void', 'voided_at' => now()]);
            $isi['advance_applied'] = round(max(0, (float) $invoice->advance_applied - (float) $return->settlement_ar_applied), 2);
        }
        if ((float) $return->ar_credited > 0) {
            $isi['returned_amount'] = round(max(0, (float) $invoice->returned_amount - (float) $return->ar_credited), 2);
        }
        // Potongan (admin + pajak) yang dibukukan retur tiga blok — rekonsiliasi
        // membacanya sebagai "sudah tercatat", jadi ikut dikembalikan.
        if ($return->settlement_fee !== null) {
            $isi['marketplace_fee'] = round(max(0, (float) $invoice->marketplace_fee - (float) $return->settlement_fee), 2);
        }

        // Tanpa jurnal pencairan aktif, faktur gaya baru belum cair — buka lagi penandanya.
        // (Retur yang mengembalikan SELURUH titipan menandai faktur tuntas tanpa jurnal.)
        $masihCair = \App\Core\Journal\Journal::where('reference_type', 'sales_invoice_settlement')
            ->where('reference_id', $invoice->id)
            ->where('status', '!=', 'void')
            ->exists();
        if ($invoice->fee_at_settlement && !$masihCair) {
            $isi['marketplace_processed'] = false;
        }

        if ($isi) {
            $invoice->update($isi);
        }
    }

    private function storeReturnItem(int $returnId, $doc, array $item): void
    {
        $docItem = $doc->items->firstWhere('id', $item['invoice_item_id']);
        $docItemSubtotal = $docItem->subtotal ?? $docItem->line_total ?? 0;

        SalesReturnItem::create([
            'sales_return_id'   => $returnId,
            'reference_item_id' => $item['invoice_item_id'],
            'product_id'        => $docItem->product_id,
            'qty'               => $item['qty'],
            'unit_price'        => $docItem->unit_price,
            'subtotal'          => round(($docItemSubtotal / $docItem->qty) * $item['qty'], 2),
            'condition'         => $item['condition'],
            'component_conditions' => $this->normalizeComponentConditions($item['component_conditions'] ?? null),
        ]);
    }

    /**
     * Faktur tempat sebuah retur DRAFT atas SO bisa dipindahkan, atau alasan kenapa tidak.
     *
     * Retur atas SO adalah jalur lama: lahir sebelum faktur terbit. Begitu SO-nya berfaktur,
     * retur itu harus ikut ke faktur — kalau tetap di SO, saat diposting ia membalik Uang Muka
     * yang sudah habis dipakai faktur, dan kasusnya tak tersambung ke dokumen penjualannya.
     *
     * Hanya DRAFT: draft belum berjurnal, jadi memindahkannya cuma mengganti kaitan. Retur yang
     * sudah diposting gaya lama sudah membalik Uang Muka; memindahkannya berarti menulis ulang
     * jurnal, dan itu keputusan tersendiri.
     *
     * Baris dipetakan lewat `sales_invoice_items.sales_order_item_id` — kaitan yang ditulis saat
     * faktur dibuat — jadi persis per baris, bukan menebak lewat produk. Satu baris saja tak
     * terpetakan (atau qty-nya melebihi baris faktur) = faktur itu tak memenuhi syarat; lebih
     * baik ditangani manual daripada separuh pindah.
     *
     * @return array{invoice: ?SalesInvoice, peta: array<int,int>, alasan: ?string}
     *         peta = sales_return_items.id => sales_invoice_items.id
     */
    public function fakturUntukReturSO(SalesReturn $retur, ?SalesInvoice $invoice = null): array
    {
        $gagal = fn (string $alasan) => ['invoice' => null, 'peta' => [], 'alasan' => $alasan];

        if (!$retur->sales_order_id || $retur->invoice_id) {
            return $gagal('Retur ini tidak menempel ke Sales Order.');
        }
        if ($retur->status !== 'draft') {
            return $gagal('Hanya retur draf yang bisa dipindah — retur posted sudah berjurnal atas Uang Muka.');
        }

        $calon = $invoice
            ? collect([$invoice])
            : SalesInvoice::with('items')
                ->where('sales_order_id', $retur->sales_order_id)
                ->whereIn('status', ['posted', 'partial'])
                ->orderBy('id')
                ->get();

        if ($calon->isEmpty()) {
            return $gagal('Sales Order ini belum punya faktur aktif.');
        }

        $retur->loadMissing('items');

        foreach ($calon as $inv) {
            $inv->loadMissing('items');
            $petaBaris = $inv->items->whereNotNull('sales_order_item_id')->keyBy('sales_order_item_id');

            $peta = [];
            $qtyPerBaris = [];
            foreach ($retur->items as $ri) {
                $baris = $petaBaris->get($ri->reference_item_id);
                if (!$baris) {
                    $peta = null;
                    break;
                }
                $peta[$ri->id] = $baris->id;
                $qtyPerBaris[$baris->id] = ($qtyPerBaris[$baris->id] ?? 0) + (float) $ri->qty;
            }

            if ($peta === null) {
                continue;
            }

            $lebih = collect($qtyPerBaris)->first(
                fn ($qty, $id) => $qty > (float) $inv->items->firstWhere('id', $id)->qty + 0.00001
            );
            if ($lebih === null) {
                return ['invoice' => $inv, 'peta' => $peta, 'alasan' => null];
            }
        }

        return $gagal('Ada baris retur yang tidak tercakup faktur ' . $calon->pluck('invoice_number')->implode(', ') . '.');
    }

    /**
     * Pindahkan satu retur DRAFT dari SO ke fakturnya. Tanggal retur, jenis, kondisi, catatan,
     * dan jurnal manual tidak disentuh — yang berganti hanya dokumen acuannya, beserta harga
     * baris yang kini dibaca dari faktur.
     *
     * @throws Exception bila tak ada faktur yang memenuhi syarat (lihat fakturUntukReturSO).
     */
    public function pindahkanReturKeFaktur(SalesReturn $retur, ?SalesInvoice $invoice = null): SalesInvoice
    {
        $hasil = $this->fakturUntukReturSO($retur, $invoice);
        if (!$hasil['invoice']) {
            throw new Exception($hasil['alasan']);
        }

        $inv = $hasil['invoice'];

        DB::transaction(function () use ($retur, $inv, $hasil) {
            $total = 0.0;
            foreach ($retur->items as $ri) {
                $baris = $inv->items->firstWhere('id', $hasil['peta'][$ri->id]);
                $subtotalBaris = (float) ($baris->subtotal ?? $baris->line_total ?? 0);
                $subtotal = (float) $baris->qty > 0 ? round($subtotalBaris / (float) $baris->qty * (float) $ri->qty, 2) : 0.0;
                $total += $subtotal;

                $ri->update([
                    'reference_item_id' => $baris->id,
                    'unit_price'        => $baris->unit_price,
                    'subtotal'          => $subtotal,
                ]);
            }

            $retur->update([
                'invoice_id'     => $inv->id,
                'sales_order_id' => null,
                'grand_total'    => round($total, 2),
            ]);
        });

        return $inv;
    }

    /**
     * Pindahkan SEMUA retur draft sebuah SO ke faktur yang baru terbit. Retur yang tak bisa
     * dipetakan dibiarkan di SO (dan dilaporkan lewat nomornya) — bukan alasan menggagalkan
     * penerbitan faktur.
     *
     * @return array{dipindah: string[], tertinggal: array<string,string>} nomor retur
     */
    public function pindahkanDraftSOKeFaktur(int $salesOrderId, SalesInvoice $invoice): array
    {
        $hasil = ['dipindah' => [], 'tertinggal' => []];

        $drafts = SalesReturn::with('items')
            ->where('sales_order_id', $salesOrderId)
            ->whereNull('invoice_id')
            ->where('status', 'draft')
            ->get();

        foreach ($drafts as $retur) {
            try {
                $this->pindahkanReturKeFaktur($retur, $invoice);
                $hasil['dipindah'][] = $retur->return_number;
            } catch (Exception $e) {
                $hasil['tertinggal'][$retur->return_number] = $e->getMessage();
            }
        }

        return $hasil;
    }

    /**
     * Bersihkan map kondisi per komponen: buang nilai kosong/tak valid, kunci = product_id int.
     * Return null bila tidak ada (item non-bundle) → item pakai `condition` tunggal.
     */
    private function normalizeComponentConditions($raw): ?array
    {
        if (!is_array($raw) || empty($raw)) {
            return null;
        }
        $out = [];
        foreach ($raw as $pid => $cond) {
            $cond = in_array($cond, SalesReturn::CONDITIONS_LAMA, true) ? SalesReturn::CONDITION_NO_RETURN : $cond;
            if (isset(SalesReturn::CONDITIONS[$cond])) {
                $out[(int) $pid] = $cond;
            }
        }
        return $out ?: null;
    }

    /**
     * Jenis penanganan retur — ditentukan dari KEADAAN DANA, bukan dari channel.
     *
     * Orang menyebutnya "retur marketplace" dan "retur biasa", dan itu benar untuk sebagian
     * besar kasus. Tapi pembeda sesungguhnya bukan channel-nya: faktur marketplace yang
     * pesanannya SUDAH selesai berperilaku persis seperti penjualan toko — dananya sudah cair,
     * jadi pengembaliannya harus keluar dari dompet/bank, bukan dari saldo yang sudah kosong.
     * Karena itu jenisnya disimpulkan sistem, bukan ditanyakan ke CS yang belum tentu tahu
     * pesanan itu sudah tuntas atau belum.
     *
     * @return 'marketplace'|'biasa'
     */
    public function jenisRetur($doc): string
    {
        if (!$doc instanceof SalesInvoice) {
            return 'biasa';
        }

        return ($doc->customer?->is_marketplace && $doc->fee_at_settlement && $doc->remaining_amount >= 1)
            ? 'marketplace'
            : 'biasa';
    }

    /** Tujuan dana bawaan untuk sebuah dokumen — dipakai form & sebagai fallback posting. */
    public function tujuanDanaBawaan($doc): string
    {
        if ($this->jenisRetur($doc) === 'marketplace') {
            return 'hold';
        }

        return $doc->customer?->is_marketplace ? 'wallet' : 'credit';
    }

    /**
     * Pembagian uang sebuah retur.
     *
     * Satu aturan untuk semua kasus, dan urutannya yang membuatnya benar:
     *
     *  1. HAPUS TAGIHAN DULU sebesar piutang faktur yang masih terbuka. Selama faktur belum
     *     dibayar, membatalkan penjualan berarti menghapus tagihan — tidak ada uang bergerak.
     *  2. SISANYA adalah uang yang SUDAH kita terima, dan hanya bagian inilah yang benar-benar
     *     perlu dikembalikan ke suatu tempat.
     *  3. Untuk retur marketplace yang dananya masih ditahan, uang pembeli dilepas balik dari
     *     Saldo Ditahan ke Uang Muka — itu pasangan jurnal tersendiri, di luar dua langkah di
     *     atas, karena yang bergerak adalah titipan pembeli, bukan pendapatan kita.
     *
     * NILAI REFUND adalah hasil negosiasi, bukan turunan harga. Pada retur setelah pesanan
     * selesai, biaya admin marketplace sudah hangus dan tidak dikembalikan platform; yang
     * wajar dikembalikan adalah dana bersih yang kita terima. Selisih antara nilai jual yang
     * dibatalkan dan uang yang benar-benar keluar MEMBALIK beban admin — karena beban itu
     * akhirnya ditanggung pembeli, bukan kita. Pembalikan dibatasi sebesar fee yang memang
     * pernah dibebankan untuk porsi yang diretur; lebih dari itu bukan urusan biaya admin dan
     * ditolak, supaya selisih yang tak terjelaskan tidak diam-diam menumpang di sana.
     *
     * @return array{ar:float, cash:float, refund:float, fee:float, hold:float, target:string}
     */
    public function hitungUang($doc, float $amount, ?string $target = null, ?float $refundDiminta = null): array
    {
        $target = $target ?: $this->tujuanDanaBawaan($doc);
        $isInvoice = $doc instanceof SalesInvoice;

        // 1. Piutang yang masih terbuka pada faktur ini.
        $arOpen = $isInvoice ? max(0, (float) $doc->remaining_amount) : 0.0;
        $ar     = round(min($amount, $arOpen), 2);

        // 2. Sisanya = uang yang sudah kita terima.
        $cash = round($amount - $ar, 2);

        // 3. Titipan pembeli yang masih ditahan marketplace, dilepas balik.
        $hold = $target === 'hold' ? round(min($amount, $this->sisaDitahan($doc)), 2) : 0.0;

        // Fee yang pernah dibebankan untuk porsi yang diretur ini.
        $feeMax = 0.0;
        if ($isInvoice && $cash > 0 && (float) $doc->grand_total > 0) {
            $feeMax = round((float) ($doc->marketplace_fee ?? 0) * ($amount / (float) $doc->grand_total), 2);
        }

        // Bawaan: kembalikan dana BERSIH yang kita terima (fee-nya ditanggung pembeli).
        $refund = $refundDiminta !== null ? round($refundDiminta, 2) : round(max(0, $cash - $feeMax), 2);
        if ($refund < 0) {
            throw new Exception('Nilai pengembalian tidak boleh negatif.');
        }
        if ($refund > $cash + 0.005) {
            throw new Exception(
                'Nilai pengembalian ' . rupiah($refund) . ' melebihi dana yang pernah kita terima atas bagian ini (' . rupiah($cash) . ').'
            );
        }

        $fee = round($cash - $refund, 2);
        if ($fee > $feeMax + 0.005) {
            throw new Exception(
                'Selisih ' . rupiah($fee) . ' lebih besar daripada biaya admin yang pernah dibebankan untuk bagian ini (' . rupiah($feeMax) . '). '
                . 'Naikkan nilai pengembalian, atau catat selisihnya lewat dokumen tersendiri.'
            );
        }

        return compact('ar', 'cash', 'refund', 'fee', 'hold', 'target');
    }

    /** Saldo titipan pembeli yang masih tertahan untuk dokumen ini. */
    public function sisaDitahan($doc): float
    {
        $soId = $doc->sales_order_id ?? ($doc instanceof \App\Modules\Sales\Models\SalesOrder ? $doc->id : null);
        if (!$soId) {
            return 0.0;
        }

        $config = \App\Models\MarketplaceConfig::where('customer_id', $doc->customer_id)->first();
        $holdId = $config?->account_receivable_hold_id;
        if (!$holdId) {
            return 0.0;
        }

        $deposit = (float) \App\Modules\Sales\Models\SalesAdvance::where('sales_order_id', $soId)
            ->where('status', 'posted')
            ->where('bank_account_id', $holdId)
            ->sum('amount');

        // Retur sebelumnya sudah melepas sebagian, dan pencairan pesanan (dari engine maupun
        // blok Penyelesaian retur) melepas sisanya — jangan melepasnya dua kali.
        $returIds = SalesReturn::where('status', 'posted')
            ->where(fn ($w) => $w->where('invoice_id', $doc->id ?? 0)
                ->orWhere('sales_order_id', $soId))
            ->pluck('id');
        $fakturIds = SalesInvoice::where('sales_order_id', $soId)->pluck('id');

        $terpakai = (float) DB::table('journal_lines as jl')
            ->join('journals as j', 'j.id', '=', 'jl.journal_id')
            ->where('j.status', '!=', 'void')
            ->where('jl.account_id', $holdId)
            ->where(fn ($w) => $w
                ->where(fn ($q) => $q->where('j.reference_type', 'sales_return')->whereIn('j.reference_id', $returIds))
                ->orWhere(fn ($q) => $q->where('j.reference_type', 'sales_invoice_settlement')->whereIn('j.reference_id', $fakturIds)))
            ->selectRaw('SUM(jl.credit) - SUM(jl.debit) v')->value('v');

        return round(max(0, $deposit - $terpakai), 2);
    }

    /**
     * Akun-akun sisi dana untuk pelanggan ini, persis yang dipakai getRevenueReversalLines()
     * & akunTujuan(). Dikirim ke form retur supaya Preview Jurnal dan isian awal "Tulis
     * sendiri jurnal dananya" menyebut akun yang SAMA dengan jurnal yang nanti diposting —
     * bukan tebakan dari label.
     *
     * @return array<string, array{id:int, code:string, name:string}|null>
     */
    public function akunDana(?int $customerId): array
    {
        $config = $customerId ? \App\Models\MarketplaceConfig::where('customer_id', $customerId)->first() : null;

        $ids = [
            'retur'     => $this->getAccountId(AccountCodeEnum::SALES_RETURN),
            'piutang'   => $this->getAccountId(AccountCodeEnum::AR_RECEIVABLE),
            'uang_muka' => $this->getAccountId(AccountCodeEnum::SALES_ADVANCE),
            'kredit'    => $this->getAccountId(AccountCodeEnum::CUSTOMER_OVERPAY),
            'ditahan'   => $config?->account_receivable_hold_id ?: $this->getAccountId(AccountCodeEnum::CUSTOMER_OVERPAY),
            'dompet'    => $config?->account_wallet_id ?: $this->getAccountId(AccountCodeEnum::CASH),
            'admin'     => $config?->account_fee_id ?: $this->getAccountId(AccountCodeEnum::SALES_LOSS),
        ];

        $akun = \App\Core\Accounting\Account::whereIn('id', array_filter($ids))->get(['id', 'code', 'name'])->keyBy('id');

        return array_map(fn ($id) => ($a = $akun->get($id))
            ? ['id' => (int) $a->id, 'code' => (string) $a->code, 'name' => (string) $a->name]
            : null, $ids);
    }

    /** Akun tujuan pengembalian dana. */
    private function akunTujuan($doc, string $target, ?int $refundAccountId): int
    {
        $config = \App\Models\MarketplaceConfig::where('customer_id', $doc->customer_id)->first();

        return match ($target) {
            'hold'   => (int) ($config?->account_receivable_hold_id ?: $this->getAccountId(AccountCodeEnum::CUSTOMER_OVERPAY)),
            'wallet' => (int) ($config?->account_wallet_id ?: $this->getAccountId(AccountCodeEnum::CASH)),
            'bank'   => (int) ($refundAccountId ?: throw new Exception('Pilih akun kas/bank untuk pengembalian dana.')),
            default  => (int) $this->getAccountId(AccountCodeEnum::CUSTOMER_OVERPAY),
        };
    }

    /**
     * Baris jurnal sisi UANG sebuah retur. Sisi barang (HPP/persediaan) terpisah di
     * getCogsReversalLines().
     */
    /**
     * Ubah baris jurnal dana yang diketik sendiri jadi JournalLineDTO — atau tolak.
     *
     * Kebebasan mengetik jurnal cuma berguna kalau yang keluar tetap jurnal yang sah, dan
     * data retur permanen setelah posting. Karena itu yang dijaga di sini bukan selera,
     * melainkan syarat yang membuat sebuah jurnal bisa dibaca sama sekali: akunnya nyata
     * dan aktif, tiap baris berdiri di SATU sisi, dan debit bertemu kredit.
     *
     * @param  array<int,array>|null $raw
     * @return array<int,JournalLineDTO>  kosong bila tak ada baris manual
     */
    private function barisJurnalManual(?array $raw): array
    {
        $baris = collect($raw ?? [])
            ->map(fn ($r) => [
                'account_id' => (int) ($r['account_id'] ?? 0),
                'debit'      => round((float) ($r['debit'] ?? 0), 2),
                'credit'     => round((float) ($r['credit'] ?? 0), 2),
                'memo'       => trim((string) ($r['memo'] ?? '')) ?: null,
            ])
            ->filter(fn ($r) => $r['account_id'] > 0 && ($r['debit'] > 0 || $r['credit'] > 0))
            ->values();

        if ($baris->isEmpty()) {
            return [];
        }

        if ($baris->count() < 2) {
            throw new Exception('Jurnal manual butuh minimal dua baris — satu debit dan satu kredit.');
        }

        foreach ($baris as $r) {
            if ($r['debit'] > 0 && $r['credit'] > 0) {
                throw new Exception('Satu baris jurnal hanya boleh berisi debit ATAU kredit, tidak keduanya.');
            }
            if ($r['debit'] < 0 || $r['credit'] < 0) {
                throw new Exception('Nilai jurnal tidak boleh negatif — pindahkan ke sisi seberangnya.');
            }
        }

        $akun = Account::whereIn('id', $baris->pluck('account_id')->unique())->where('is_active', true)->pluck('id');
        $asing = $baris->pluck('account_id')->unique()->diff($akun);
        if ($asing->isNotEmpty()) {
            throw new Exception('Ada baris jurnal yang akunnya tidak dikenal atau sudah nonaktif.');
        }

        $debit  = round($baris->sum('debit'), 2);
        $kredit = round($baris->sum('credit'), 2);
        if (abs($debit - $kredit) > 0.005) {
            throw new Exception(
                'Jurnal belum seimbang: debit ' . rupiah($debit) . ' vs kredit ' . rupiah($kredit)
                . '. Selisih ' . rupiah(abs($debit - $kredit)) . ' harus dihabiskan dulu.'
            );
        }

        return $baris
            ->map(fn ($r) => new JournalLineDTO($r['account_id'], $r['debit'], $r['credit'], $r['memo'] ?? 'Jurnal retur manual'))
            ->all();
    }

    private function getRevenueReversalLines($dto, $doc, $amount, $isSO)
    {
        // Retur atas SO (belum ada faktur) — jalur lama, dipertahankan agar 32 dokumen lama
        // tetap bisa dibuka & di-void. Belum ada omzet yang diakui, jadi yang dibalik adalah
        // Uang Muka, bukan penjualan.
        if ($isSO) {
            $config = $doc->customer->is_marketplace
                ? \App\Models\MarketplaceConfig::where('customer_id', $doc->customer_id)->first()
                : null;
            $creditId = $config?->account_receivable_hold_id ?: $this->getAccountId(AccountCodeEnum::CUSTOMER_OVERPAY);

            return [
                new JournalLineDTO($this->getAccountId(AccountCodeEnum::SALES_ADVANCE), (float) $amount, 0, 'Sales Return Reversal - Advance'),
                new JournalLineDTO((int) $creditId, 0, (float) $amount, 'Pengembalian titipan pembeli', $doc->customer_id),
            ];
        }

        $uang = $this->hitungUang($doc, (float) $amount, $dto->refund_target, $dto->refund_amount);

        // Nilai JUAL yang dibatalkan → kontra-pendapatan 4004, bukan mendebit 4001 langsung.
        // Mendebit 4001 membuat omzet menyusut diam-diam: laporan tak bisa memisahkan
        // "jual berapa" dari "diretur berapa". Laba tidak berubah — 4004 sama-sama revenue.
        $lines = [
            new JournalLineDTO($this->getAccountId(AccountCodeEnum::SALES_RETURN), (float) $amount, 0, 'Retur penjualan - ' . $doc->invoice_number),
        ];

        if ($uang['ar'] > 0) {
            $lines[] = new JournalLineDTO(
                $this->getAccountId(AccountCodeEnum::AR_RECEIVABLE), 0, $uang['ar'],
                'Tagihan dihapus - ' . $doc->invoice_number, $doc->customer_id
            );
        }

        if ($uang['refund'] > 0) {
            $lines[] = new JournalLineDTO(
                $this->akunTujuan($doc, $uang['target'], $dto->refund_account_id), 0, $uang['refund'],
                'Pengembalian dana (' . (SalesReturn::REFUND_TARGETS[$uang['target']] ?? $uang['target']) . ')',
                $dto->refund_customer_id ?: $doc->customer_id
            );
        }

        if ($uang['fee'] > 0) {
            // Biaya admin yang TIDAK jadi kita tanggung: pembeli hanya menerima dana bersih.
            $config = \App\Models\MarketplaceConfig::where('customer_id', $doc->customer_id)->first();
            $lines[] = new JournalLineDTO(
                (int) ($config?->account_fee_id ?: $this->getAccountId(AccountCodeEnum::SALES_LOSS)), 0, $uang['fee'],
                'Biaya admin tidak dikembalikan ke pembeli'
            );
        }

        // Titipan pembeli yang masih ditahan marketplace dilepas balik — pasangan tersendiri.
        if ($uang['hold'] > 0) {
            $config = \App\Models\MarketplaceConfig::where('customer_id', $doc->customer_id)->first();
            $lines[] = new JournalLineDTO($this->getAccountId(AccountCodeEnum::SALES_ADVANCE), $uang['hold'], 0, 'Titipan pembeli dikembalikan');
            $lines[] = new JournalLineDTO((int) $config->account_receivable_hold_id, 0, $uang['hold'], 'Pelepasan saldo ditahan untuk retur', $doc->customer_id);
        }

        return $lines;
    }

    private function getCogsReversalLines($dto, $doc, $returnId)
    {
        $lines = [];
        $isSO = (bool) $dto->sales_order_id;
        $docNumber = $doc->invoice_number ?? $doc->order_number;

        foreach ($dto->items as $item) {
            $docItem = $doc->items->firstWhere('id', $item['invoice_item_id']);
            if (!$docItem) {
                continue;
            }

            $qty = (float) $docItem->qty;
            if ($qty <= 0) {
                continue;
            }

            $cogsTotal = (float) ($docItem->cogs_total ?? 0);

            if ($doc instanceof \App\Modules\Sales\Models\SalesOrder && $cogsTotal == 0) {
                $cogsTotal = $doc->deliveries()->where('status', 'posted')->with('items')->get()
                    ->flatMap->items
                    ->where('sales_order_item_id', $docItem->id)
                    ->sum('cogs_total');
            }

            $product = Product::find($docItem->product_id);

            if ($product && $product->sale_type === 'bundle' && $cogsTotal == 0 && $dto->invoice_id) {
                $delivery = $doc->delivery ?? null;
                if ($delivery && $delivery->items) {
                    $components = BundleComponent::where('bundle_product_id', $docItem->product_id)->get();
                    $qtyField = 'qty';
                    if ($components->isEmpty()) {
                        $components = ProductBundle::where('bundle_product_id', $docItem->product_id)->get();
                        $qtyField = 'qty_required';
                    }
                    foreach ($components as $comp) {
                        $deliveryItem = $delivery->items->firstWhere('product_id', $comp->component_product_id);
                        if ($deliveryItem) {
                            $cogsTotal += (float) $deliveryItem->cogs_total;
                        }
                    }
                }
            }

            // Bundle dengan kondisi PER KOMPONEN → tiap komponen dirutekan & di-COGS sendiri.
            $componentConditions = $this->normalizeComponentConditions($item['component_conditions'] ?? null);
            if ($product && $product->sale_type === 'bundle' && $componentConditions) {
                $lines = array_merge($lines, $this->bundleComponentCogsLines(
                    $doc, $docItem, $item, $componentConditions, $docNumber, $returnId, $isSO
                ));
                continue;
            }

            // `tidak_kembali`: barang hilang & dananya diganti → penjualannya sah. HPP tetap
            // di 5001, stok tidak dipulihkan, tidak ada baris jurnal sama sekali.
            $unitCogs = $cogsTotal > 0 ? $cogsTotal / $qty : 0;
            $returnCogs = round($unitCogs * $item['qty'], 2);

            if ($item['condition'] === 'good' || $item['condition'] === 'repair') {
                $this->restoreReturnStock($dto, $doc, $docItem, $item, $unitCogs, $docNumber, $returnId);
            }

            if ($returnCogs <= 0) {
                continue;
            }

            $debitAccountCode = $this->getReturnConditionAccountCode($item['condition']);
            $debitAccountId = $this->getAccountId($debitAccountCode);
            $conditionLabel = $this->getReturnConditionLabel($item['condition']);

            $lines[] = new JournalLineDTO(
                account_id: $debitAccountId,
                debit: (float) $returnCogs,
                credit: 0,
                description: $isSO
                    ? "Retur SO - {$conditionLabel}"
                    : "Retur Invoice - {$conditionLabel}"
            );

            $lines[] = new JournalLineDTO(
                account_id: $this->getAccountId(AccountCodeEnum::COGS),
                debit: 0,
                credit: (float) $returnCogs,
                description: "{$conditionLabel} COGS Reversal"
            );
        }

        return $lines;
    }

    private function restoreReturnStock($dto, $doc, $docItem, array $item, float $unitCogs, string $docNumber, int $returnId): void
    {
        $product = Product::find($docItem->product_id);

        // Barang kondisi 'repair' masuk ke Gudang Perbaikan (non-jual, = akun 1131), BUKAN
        // gudang jual — supaya tidak ikut terjual & saldo 1131 cocok dengan stok fisik.
        // Kondisi 'good' tetap kembali ke gudang dokumen. (bundle: komponennya ikut aturan sama)
        $targetWarehouseId = $item['condition'] === 'repair'
            ? (Warehouse::repairId() ?? $doc->warehouse_id)
            : $doc->warehouse_id;

        if ($product && $product->sale_type === 'bundle') {
            $components = BundleComponent::where('bundle_product_id', $docItem->product_id)->get();
            $qtyField = 'qty';

            if ($components->isEmpty()) {
                $components = ProductBundle::where('bundle_product_id', $docItem->product_id)->get();
                $qtyField = 'qty_required';
            }

            $totalUnits = $components->sum(fn ($c) => $c->{$qtyField} ?? 1);

            foreach ($components as $comp) {
                $compQtyPerBundle = $comp->{$qtyField} ?? 1;
                $compQtyReturn = $compQtyPerBundle * $item['qty'];
                $compCostPerUnit = ($totalUnits > 0 && $unitCogs > 0)
                    ? $unitCogs * ($compQtyPerBundle / $totalUnits)
                    : 0;

                app(FifoService::class)->stockIn(
                    productId: $comp->component_product_id,
                    warehouseId: $targetWarehouseId,
                    type: 'sales_return',
                    reference: $docNumber,
                    qty: $compQtyReturn,
                    cost: $compCostPerUnit,
                    transactionId: $returnId
                );
            }

            return;
        }

        app(FifoService::class)->stockIn(
            productId: $docItem->product_id,
            warehouseId: $targetWarehouseId,
            type: 'sales_return',
            reference: $docNumber,
            qty: $item['qty'],
            cost: $unitCogs,
            transactionId: $returnId
        );
    }

    /** Kumpulkan item SJ (posted) untuk dokumen retur — sumber COGS per komponen. */
    private function deliveryItemsFor($doc)
    {
        if ($doc instanceof \App\Modules\Sales\Models\SalesOrder) {
            return $doc->deliveries()->where('status', 'posted')->with('items')->get()->flatMap->items;
        }
        return $doc->delivery?->items ?? collect();
    }

    /**
     * Bundle dengan kondisi PER KOMPONEN: rutekan stok & buat baris COGS untuk tiap komponen
     * sesuai kondisinya sendiri (good→persediaan, repair→Gudang Perbaikan, damaged→beban).
     * Pembalikan pendapatan tetap di level bundle (di getRevenueReversalLines), tak disentuh.
     */
    private function bundleComponentCogsLines($doc, $docItem, array $item, array $componentConditions, string $docNumber, int $returnId, bool $isSO): array
    {
        $components = BundleComponent::where('bundle_product_id', $docItem->product_id)->get();
        $qtyField = 'qty';
        if ($components->isEmpty()) {
            $components = ProductBundle::where('bundle_product_id', $docItem->product_id)->get();
            $qtyField = 'qty_required';
        }

        $deliveryItems     = $this->deliveryItemsFor($doc);
        $docQty            = (float) $docItem->qty;
        $retQty            = (float) $item['qty'];
        $repairWarehouseId = Warehouse::repairId();
        $fifo              = app(FifoService::class);
        $lines             = [];

        foreach ($components as $comp) {
            $cond = $componentConditions[(int) $comp->component_product_id] ?? 'good';

            // Komponen yang tidak kembali & dananya diganti: tak ada stok masuk, tak ada
            // pemindahan HPP — sama seperti baris non-bundle.
            $compQtyPerBundle = (float) ($comp->{$qtyField} ?? 1);
            if ($compQtyPerBundle <= 0) {
                continue;
            }

            $di            = $deliveryItems->firstWhere('product_id', $comp->component_product_id);
            $compDelivCogs = (float) ($di->cogs_total ?? 0);
            // Biaya 1 unit komponen (delivery cogs mencakup docQty × compQtyPerBundle unit).
            $compUnitCost   = ($docQty > 0) ? $compDelivCogs / ($docQty * $compQtyPerBundle) : 0;
            $compQtyReturn  = $compQtyPerBundle * $retQty;
            $compReturnCogs = round($compUnitCost * $compQtyReturn, 2);

            // Routing stok: good/repair masuk gudang (repair→Gudang Perbaikan); damaged tidak.
            if ($cond === 'good' || $cond === 'repair') {
                $warehouseId = $cond === 'repair'
                    ? ($repairWarehouseId ?? $doc->warehouse_id)
                    : $doc->warehouse_id;
                $fifo->stockIn(
                    productId: $comp->component_product_id,
                    warehouseId: $warehouseId,
                    type: 'sales_return',
                    reference: $docNumber,
                    qty: $compQtyReturn,
                    cost: $compUnitCost,
                    transactionId: $returnId
                );
            }

            if ($compReturnCogs <= 0) {
                continue;
            }

            $label = $this->getReturnConditionLabel($cond);
            $lines[] = new JournalLineDTO(
                account_id: $this->getAccountId($this->getReturnConditionAccountCode($cond)),
                debit: (float) $compReturnCogs,
                credit: 0,
                description: ($isSO ? 'Retur SO' : 'Retur Invoice') . " - {$label} (komponen)"
            );
            $lines[] = new JournalLineDTO(
                account_id: $this->getAccountId(AccountCodeEnum::COGS),
                debit: 0,
                credit: (float) $compReturnCogs,
                description: "{$label} COGS Reversal (komponen)"
            );
        }

        return $lines;
    }

    private function getReturnConditionAccountCode(string $condition): string
    {
        return match ($condition) {
            'good' => AccountCodeEnum::INVENTORY,
            'repair' => AccountCodeEnum::INVENTORY_REPAIR,
            'damaged' => AccountCodeEnum::SALES_LOSS,
            // Barang tak kembali & dananya dikembalikan: penjualannya batal, jadi modalnya
            // bukan lagi HPP sebuah penjualan melainkan kerugian. Tidak ada barang yang masuk
            // gudang — getCogsReversalLines hanya memasukkan `good` & `repair` ke persediaan.
            'hilang', 'tetap', SalesReturn::CONDITION_NO_RETURN => AccountCodeEnum::SALES_LOSS,
            // tidak_kembali tak pernah sampai sini (dilewati di getCogsReversalLines).
            default => AccountCodeEnum::INVENTORY,
        };
    }

    private function getReturnConditionLabel(string $condition): string
    {
        return match ($condition) {
            'good' => 'Persediaan',
            'repair' => 'Persediaan Perbaikan',
            'damaged', 'hilang', 'tetap', SalesReturn::CONDITION_NO_RETURN => 'Beban Kerugian Retur',
            default => 'Persediaan',
        };
    }

    private function getDoc(SalesReturnDTO $dto)
    {
        if ($dto->invoice_id) {
            return SalesInvoice::with(['items.product', 'customer', 'delivery.items'])->findOrFail($dto->invoice_id);
        } elseif ($dto->sales_order_id) {
            return \App\Modules\Sales\Models\SalesOrder::with(['items', 'customer', 'deliveries.items'])->findOrFail($dto->sales_order_id);
        }

        throw new Exception('Either invoice_id atau sales_order_id harus diisi.');
    }

    private function validatePosting(SalesReturnDTO $dto, $doc, ?int $existingReturnId = null)
    {
        $docType = $dto->invoice_id ? 'invoice' : 'so';
        $docStatus = $doc->status instanceof \BackedEnum ? $doc->status->value : (string) $doc->status;

        if ($docType === 'invoice') {
            if (!in_array($docStatus, ['posted', 'partial'])) {
                throw new Exception("Invoice status '{$docStatus}' tidak dapat diretur. Harus posted atau partial.");
            }
        } else {
            if (!in_array($docStatus, ['confirmed', 'closed', 'partial'])) {
                throw new Exception("Sales Order status '{$docStatus}' tidak dapat diretur.");
            }

            $hasSJ = $doc->deliveries()->where('status', 'posted')->exists();
            if (!$hasSJ) {
                throw new Exception('Sales Order belum memiliki Pengiriman (SJ) yang diposting. Jika barang belum keluar, cukup lakukan VOID pada SO.');
            }

            $isPaid = $doc->payment_status === 'paid' || (($doc->paid_amount ?? 0) >= $doc->grand_total);
            if (!$isPaid) {
                throw new Exception('Sales Order belum lunas. Retur uang hanya berlaku untuk pesanan yang sudah terbayar penuh (Advance).');
            }
        }

        if (empty($dto->items)) {
            throw new Exception('Minimal harus ada 1 item yang diretur.');
        }

        // Satu produk kini bisa dipecah ke beberapa baris (kondisi berbeda: utuh/perbaikan/
        // rusak). Validasi returnable harus pakai TOTAL per item dokumen, bukan per baris —
        // kalau tidak, tiap baris lolos sendiri (mis. utuh 2 + rusak 2) padahal jumlahnya
        // melebihi qty dokumen.
        $requestedByRef = [];
        foreach ($dto->items as $item) {
            $refId = $item['invoice_item_id'];
            if (!$doc->items->firstWhere('id', $refId)) {
                throw new Exception("Item ID {$refId} tidak ditemukan pada dokumen");
            }
            if ($item['qty'] <= 0) {
                throw new Exception('Qty retur harus lebih besar dari 0');
            }
            $requestedByRef[$refId] = ($requestedByRef[$refId] ?? 0) + (float) $item['qty'];
        }

        foreach ($requestedByRef as $refId => $requestedQty) {
            $targetItem = $doc->items->firstWhere('id', $refId);

            // Qty yang SUDAH diretur (posted) untuk item dokumen ini, kecuali retur yang
            // sedang di-post. Cegah double-return: validasi pakai SISA, bukan qty penuh.
            $alreadyReturned = (float) SalesReturnItem::where('reference_item_id', $refId)
                ->whereHas('salesReturn', function ($q) use ($existingReturnId) {
                    $q->where('status', 'posted');
                    if ($existingReturnId) {
                        $q->where('id', '!=', $existingReturnId);
                    }
                })
                ->sum('qty');

            $returnable = (float) $targetItem->qty - $alreadyReturned;
            if ($requestedQty > $returnable + 0.00001) {
                throw new Exception(
                    "Total qty retur ({$requestedQty}) untuk " . ($targetItem->product?->name ?? "item #{$refId}") . " melebihi sisa yang bisa diretur ({$returnable}). "
                    . "Qty dokumen: {$targetItem->qty}, sudah diretur: {$alreadyReturned}."
                );
            }
        }
    }

    private function calculateTotals($dto, $doc)
    {
        $totalNet = 0;
        $totalReversed = 0;

        foreach ($dto->items as $item) {
            $docItem = $doc->items->firstWhere('id', $item['invoice_item_id']);

            if (!$docItem) {
                throw new Exception("Item ID {$item['invoice_item_id']} tidak ditemukan");
            }

            $docItemSubtotal = $docItem->subtotal ?? $docItem->line_total ?? 0;
            $ratio = $item['qty'] / $docItem->qty;
            $lineNet = $docItemSubtotal * $ratio;
            $totalNet += $lineNet;

            // Baris `tidak_kembali` dananya diganti marketplace → TIDAK mengurangi penjualan.
            if (($item['condition'] ?? null) !== SalesReturn::CONDITION_NO_RETURN) {
                $totalReversed += $lineNet;
            }
        }

        return [
            // Nilai kasus retur seutuhnya (dipakai sbg grand_total dokumen).
            'net'      => round($totalNet, 2),
            // Bagian yang benar-benar membalik penjualan.
            'reversed' => round($totalReversed, 2),
        ];
    }

    private function getAccountId($code)
    {
        $id = Account::where('code', $code)->value('id');
        if (!$id) {
            if ($code === AccountCodeEnum::INVENTORY_REPAIR || $code === AccountCodeEnum::INVENTORY_DAMAGED) {
                return $this->getAccountId(AccountCodeEnum::INVENTORY);
            }
            if ($code === AccountCodeEnum::SALES_LOSS) {
                return $this->getAccountId(AccountCodeEnum::COGS);
            }

            throw new Exception("Account with code {$code} not found.");
        }

        return $id;
    }
}
