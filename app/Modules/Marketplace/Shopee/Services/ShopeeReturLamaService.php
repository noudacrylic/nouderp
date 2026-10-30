<?php

namespace App\Modules\Marketplace\Shopee\Services;

use App\Core\Accounting\Account;
use App\Core\Inventory\InventoryEngine;
use App\Core\Inventory\StockLayer;
use App\Core\Journal\JournalPostingService;
use App\DTO\JournalEntryDTO;
use App\DTO\JournalLineDTO;
use App\DTO\SalesReturnDTO;
use App\Enums\AccountCodeEnum;
use App\Models\InventoryCostLayer;
use App\Models\SalesInvoice;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\MarketplaceEngineService;
use App\Modules\Sales\Services\SalesReturnService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Menuntaskan retur Shopee LAMA yang menggantung sebagai draft, berdasarkan angka Shopee
 * (keputusan user 30 Okt 2026): uangnya mengikuti rincian dana pesanan (escrow) Shopee, dan
 * barangnya dianggap TIDAK KEMBALI — sudah terlalu lama dan fisiknya tidak ada.
 *
 * Tiap draft jatuh ke salah satu kelompok:
 *  A  normal  — dana pesanan masih ditahan (1202). Diposting lewat jurnal tiga blok biasa:
 *               dibalik = refund ke pembeli − penggantian Shopee; dana cair = escrow Shopee;
 *               selisihnya potongan (admin/premi).
 *  B  dobel   — pesanan sudah punya retur POSTED atas SO (sebelum faktur terbit). Faktur yang
 *               terbit belakangan membuka lagi piutangnya. Draft ditutup; satu jurnal koreksi
 *               menghapus piutang (Dr Retur Penjualan / Cr Piutang), mencairkan bagian yang tak
 *               direfund + potongan Shopee, dan sisa stok yang
 *               dulu dikembalikan retur lama dihapus ke Beban Kerugian Retur.
 *  C  batal   — retur dibatalkan di Shopee: pesanan jadi pesanan biasa. Draft ditutup; faktur
 *               dicairkan seperlunya.
 *  -  lewati  — data tidak cukup / bentuknya di luar pola: ditangani manual.
 */
class ShopeeReturLamaService
{
    public function __construct(
        private ShopeeClient $client,
        private SalesReturnService $retur,
    ) {}

    /** Semua retur Shopee yang diajukan sejak $dari, dikelompokkan per order_sn. */
    public function tarikRetur(ShopeeShop $shop, Carbon $dari): array
    {
        $hasil = [];
        $akhir = time();
        for ($t = $dari->copy()->startOfDay()->timestamp; $t < $akhir; $t += 15 * 86400) {
            for ($hal = 0; $hal < 50; $hal++) {
                $r = $this->client->panggil($shop, 'GET', '/api/v2/returns/get_return_list', [
                    'page_no'          => $hal,
                    'page_size'        => 100,
                    'create_time_from' => $t,
                    'create_time_to'   => min($akhir, $t + 15 * 86400 - 1),
                ]);
                $isi = $r['response'] ?? $r;
                foreach ($isi['return'] ?? [] as $x) {
                    $hasil[$x['order_sn']][] = $x;
                }
                if (empty($isi['more'])) {
                    break;
                }
            }
        }

        return $hasil;
    }

    /** Rincian dana pesanan (order_income) dari Shopee. */
    public function escrow(ShopeeShop $shop, string $sn): ?array
    {
        try {
            $r = $this->client->panggil($shop, 'GET', '/api/v2/payment/get_escrow_detail', ['order_sn' => $sn]);
        } catch (ShopeeException) {
            return null;
        }
        $oi = ($r['response'] ?? $r)['order_income'] ?? null;

        return is_array($oi) && $oi ? $oi : null;
    }

    private function statusPesanan(ShopeeShop $shop, string $sn): ?string
    {
        try {
            $r = $this->client->panggil($shop, 'GET', '/api/v2/order/get_order_detail', ['order_sn_list' => $sn]);
        } catch (ShopeeException) {
            return null;
        }

        return ($r['response'] ?? $r)['order_list'][0]['order_status'] ?? null;
    }

    /**
     * Skill Manajer Admin "Selesaikan retur tidak jadi": draft retur Shopee yang returnya
     * dibatalkan pembeli & pesanannya sudah selesai → ditutup, faktur dicairkan sesuai angka
     * Shopee. Satu transaksi per draft; $simulasi = periksa saja.
     *
     * @return array<int, array{retur: string, retur_id: int, sn: string, nilai: float, cair: float, potongan: float, status: string, pesan: ?string}>
     */
    public function selesaikanTidakJadi(ShopeeShop $shop, bool $simulasi, int $hariMundur = 120): array
    {
        $hasil = [];
        $rencana = $this->rencana($shop, now()->subDays($hariMundur), now(), [], true);

        foreach ($rencana as $r) {
            $baris = [
                'retur' => $r['draft']->return_number, 'retur_id' => (int) $r['draft']->id, 'sn' => $r['sn'],
                'nilai' => $r['nilai'], 'cair' => $r['cair'], 'potongan' => $r['potongan'],
                'status' => 'dilewati', 'pesan' => $r['alasan'],
            ];
            if ($r['kelompok'] === 'C') {
                if ($simulasi) {
                    $baris['status'] = 'akan';
                } else {
                    try {
                        DB::transaction(fn () => $this->jalankan($r));
                        $baris['status'] = 'selesai';
                    } catch (Throwable $e) {
                        $baris['status'] = 'gagal';
                        $baris['pesan']  = $e->getMessage();
                    }
                }
            }
            $hasil[] = $baris;
        }

        return $hasil;
    }

    /**
     * Susun rencana untuk draft retur Shopee atas pesanan bertanggal $dari..$sampai.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rencana(ShopeeShop $shop, Carbon $dari, Carbon $sampai, array $hanyaSn = [], bool $hanyaReturBatal = false): array
    {
        $drafts = SalesReturn::with('items')
            ->where('sales_returns.status', 'draft')
            ->join('sales_invoices as si', 'si.id', '=', 'sales_returns.invoice_id')
            ->join('sales_orders as so', 'so.id', '=', 'si.sales_order_id')
            ->where('so.order_number', 'like', 'SP-%')
            ->whereBetween('so.order_date', [$dari->toDateString(), $sampai->toDateString() . ' 23:59:59'])
            ->orderBy('so.order_date')
            ->select('sales_returns.*', 'so.order_number as _order', 'so.id as _so')
            ->get();

        if ($hanyaSn) {
            $drafts = $drafts->filter(fn ($d) => in_array(substr($d->_order, 3), $hanyaSn, true));
        }

        $returShopee = $this->tarikRetur($shop, $dari->copy()->subDays(5));

        // Hanya calon "retur tidak jadi": semua pengajuan retur pesanan itu dibatalkan di Shopee.
        // Menghemat panggilan rincian dana untuk draft yang jelas bukan kelompok C.
        if ($hanyaReturBatal) {
            $drafts = $drafts->filter(function ($d) use ($returShopee) {
                $list = $returShopee[substr($d->_order, 3)] ?? [];

                return $list && collect($list)->every(fn ($x) => $x['status'] === 'CANCELLED');
            });
        }

        return $drafts->map(fn (SalesReturn $d) => $this->rencanaSatu($shop, $d, $returShopee[substr($d->_order, 3)] ?? []))->values()->all();
    }

    private function rencanaSatu(ShopeeShop $shop, SalesReturn $draft, array $returSn): array
    {
        $sn  = substr($draft->_order, 3);
        $inv = SalesInvoice::with('items')->find($draft->invoice_id);
        $r   = [
            'draft' => $draft, 'invoice' => $inv, 'sn' => $sn, 'kelompok' => '-', 'alasan' => null,
            'retur_shopee' => array_map(fn ($x) => ['sn' => $x['return_sn'], 'status' => $x['status'], 'alasan' => $x['reason'], 'refund' => (float) $x['refund_amount']], $returSn),
            'nilai' => (float) ($inv?->grand_total ?? 0), 'refund' => 0.0, 'ganti' => 0.0, 'cair' => 0.0,
            'dibalik' => 0.0, 'potongan' => 0.0, 'jenis' => null, 'kasus' => null, 'lama' => null,
        ];
        $lewati = function (string $alasan) use (&$r) {
            $r['kelompok'] = '-';
            $r['alasan']   = $alasan;

            return $r;
        };

        if (!$inv || !in_array((string) ($inv->status instanceof \BackedEnum ? $inv->status->value : $inv->status), ['posted', 'partial'], true)) {
            return $lewati('Faktur tidak aktif.');
        }

        $esc = $this->escrow($shop, $sn);
        if (!$esc) {
            return $lewati('Rincian dana Shopee (escrow) tidak tersedia.');
        }

        // ── Angka Shopee ──
        $refund = max(0.0, -(float) ($esc['seller_return_refund'] ?? 0));
        $cair   = (float) ($esc['escrow_amount_after_adjustment'] ?? $esc['escrow_amount'] ?? 0);
        // Penggantian dari Shopee: penyesuaian positif setelah escrow (kompensasi klaim / paket
        // hilang). Penyesuaian negatif bukan penggantian — ia masuk potongan dengan sendirinya.
        $ganti = array_key_exists('escrow_amount_after_adjustment', $esc)
            ? max(0.0, (float) $esc['escrow_amount_after_adjustment'] - (float) ($esc['escrow_amount'] ?? 0))
            : 0.0;
        $ganti = max($ganti, (float) ($esc['seller_lost_compensation'] ?? 0));

        $nilai   = (float) $inv->grand_total;
        $dibalik = round(min($nilai, max(0.0, $refund - $ganti)), 2);
        $sisa    = round($nilai - $dibalik, 2);
        $r = array_merge($r, ['refund' => $refund, 'ganti' => $ganti, 'cair' => $cair, 'dibalik' => $dibalik, 'potongan' => round($sisa - $cair, 2)]);

        $semuaBatal = $returSn && collect($returSn)->every(fn ($x) => $x['status'] === 'CANCELLED');
        $lama = SalesReturn::where('sales_order_id', $draft->_so)->whereNull('invoice_id')->where('status', 'posted')->get();

        // ── C: retur dibatalkan di Shopee ──
        if ($semuaBatal && $refund <= 0) {
            // Rincian dana baru final setelah pesanan selesai — sebelum itu angkanya taksiran.
            $status = $this->statusPesanan($shop, $sn);
            if ($status !== 'COMPLETED') {
                return $lewati('Retur dibatalkan di Shopee, tapi pesanan masih ' . ($status ?? 'tidak terbaca') . ' — dana belum final.');
            }
            $r['kelompok'] = 'C';
            $r['alasan']   = 'Retur dibatalkan pembeli di Shopee — pesanan biasa, dana cair ' . rupiah($cair) . '.';

            return $r;
        }

        if ($r['potongan'] < -0.5) {
            return $lewati('Dana cair (' . rupiah($cair) . ') melebihi sisa nilai pesanan (' . rupiah($sisa) . ') — cek manual.');
        }

        // ── B: dobel dengan retur lama atas SO ──
        if ($lama->isNotEmpty()) {
            $r['lama'] = $lama;
            // Retur lama sudah melepas Saldo Ditahan barang yang diretur; yang tersisa ditahan
            // harus persis bagian pesanan yang tidak direfund (dicairkan di jurnal koreksi).
            $ditahan = $this->retur->sisaDitahan($inv);
            if (abs($ditahan - $sisa) > 0.5) {
                return $lewati('Dobel dengan ' . $lama->pluck('return_number')->implode(', ') . ', tapi Saldo Ditahan ' . rupiah($ditahan) . ' ≠ sisa pesanan ' . rupiah($sisa) . ' — cek manual.');
            }
            if ($sisa > 0.5 && app(MarketplaceEngineService::class)->uangMukaTersedia($inv) + 0.5 < $sisa) {
                return $lewati('Dobel dengan retur lama, tapi uang muka tak cukup untuk sisa pesanan — cek manual.');
            }
            if (round((float) $inv->remaining_amount, 2) < $nilai - 0.5) {
                return $lewati('Dobel dengan retur lama, tapi piutang faktur tinggal ' . rupiah($inv->remaining_amount) . ' — cek manual.');
            }
            $r['kelompok'] = 'B';
            $r['alasan']   = 'Dobel dengan ' . $lama->pluck('return_number')->implode(', ') . ' (retur atas SO, sebelum faktur terbit).';

            return $r;
        }

        // ── A: normal ──
        $tanpaRetur  = !$returSn;
        $tidakSampai = collect($returSn)->contains(fn ($x) => $x['reason'] === 'NOT_RECEIPT');
        [$jenis, $kasus] = match (true) {
            $dibalik <= 0.5 && ($tanpaRetur || $tidakSampai) => ['paket_hilang', 'PH1'],
            $dibalik <= 0.5                                  => ['diajukan_konsumen', 'K6'],
            $tidakSampai                                     => ['paket_hilang', 'PH2'],
            $tanpaRetur && $sisa <= 0.5                      => ['gagal_kirim', 'GK1'],
            $tanpaRetur                                      => ['paket_hilang', 'PH1'],
            $sisa <= 0.5                                     => ['diajukan_konsumen', 'K3'],
            default                                          => ['diajukan_konsumen', 'K4'],
        };
        $r['jenis'] = $jenis;
        $r['kasus'] = $kasus;

        $sisaDitahan = $this->retur->sisaDitahan($inv);
        if (abs($sisaDitahan - $nilai) > 0.5) {
            return $lewati('Saldo Ditahan pesanan ' . rupiah($sisaDitahan) . ' ≠ nilai faktur ' . rupiah($nilai) . ' — cek manual.');
        }
        if (!$inv->fee_at_settlement || $inv->marketplace_processed) {
            return $lewati('Faktur sudah ditandai cair — cek manual.');
        }

        $r['kelompok'] = 'A';
        $r['alasan']   = SalesReturn::CASES[$jenis][$kasus];

        return $r;
    }

    /**
     * Jalankan satu baris rencana. Dipanggil di dalam transaksi oleh pemanggil (dry-run =
     * rollback), jadi semua penjaga posting ikut teruji tanpa meninggalkan bekas.
     *
     * @return array{jurnal: array<int, array>, catatan: string[]}
     */
    public function jalankan(array $r): array
    {
        return match ($r['kelompok']) {
            'A'     => $this->postingNormal($r),
            'B'     => $this->koreksiDobel($r),
            'C'     => $this->tutupBatal($r),
            default => ['jurnal' => [], 'catatan' => ['dilewati']],
        };
    }

    private function catatan(SalesReturn $d, string $teks): string
    {
        // Dikerjakan karyawan virtual → namanya ikut tercatat di riwayat retur.
        $oleh = auth()->user()?->name;

        return trim(($d->notes ? $d->notes . "\n" : '') . '[Data Shopee ' . now()->format('d/m/Y') . ($oleh ? ' · ' . $oleh : '') . '] ' . $teks);
    }

    private function tanggal(SalesReturn $d, SalesInvoice $inv): string
    {
        return max($d->return_date?->toDateString() ?? now()->toDateString(), $inv->invoice_date?->toDateString() ?? '0000-00-00');
    }

    /** A — posting tiga blok dengan angka Shopee. */
    private function postingNormal(array $r): array
    {
        /** @var SalesReturn $d */
        $d   = $r['draft'];
        $inv = $r['invoice'];

        $items = $d->items->map(fn ($i) => [
            'invoice_item_id' => (int) $i->reference_item_id,
            'qty'             => (float) $i->qty,
            'condition'       => SalesReturn::CONDITION_NO_RETURN,
        ])->all();

        $banding = SalesReturn::CASE_APPEAL[$r['kasus']] ?? null;
        $b = $this->retur->jurnalBawaan($inv, $items, $r['jenis'], $banding, 'hold', null, $r['dibalik']);

        // Penyelesaian: dana cair = escrow Shopee; potongan = sisa kotor − dana cair.
        $set = $b['penyelesaian'];
        if ($set !== null) {
            $akun  = $this->retur->akunDana($inv->customer_id);
            $pajakIdx = $adminIdx = $dompetIdx = null;
            foreach ($set as $i => $row) {
                if ($row['account_id'] === ($akun['admin']['id'] ?? -1)) $adminIdx = $i;
                elseif ($row['account_id'] === ($akun['dompet']['id'] ?? -1)) $dompetIdx = $i;
                elseif ($row['memo'] === 'Pajak dipotong marketplace') $pajakIdx = $i;
            }
            $pajak = $pajakIdx !== null ? (float) $set[$pajakIdx]['debit'] : 0.0;
            if ($pajak > $r['potongan']) {
                $pajak = 0.0;
            }
            if ($pajakIdx !== null) $set[$pajakIdx]['debit'] = $pajak;
            $set[$adminIdx]['debit']  = round(max(0, $r['potongan'] - $pajak), 2);
            $set[$adminIdx]['memo']   = 'Potongan Shopee (admin/premi) sesuai rincian dana';
            $set[$dompetIdx]['debit']  = round(max(0, $r['cair']), 2);
            $set[$dompetIdx]['credit'] = round(max(0, -$r['cair']), 2);
            $set[$dompetIdx]['memo']   = 'Dana cair sesuai rincian dana Shopee';
        }

        $dto = new SalesReturnDTO(
            customer_id: (int) $d->customer_id,
            items: $items,
            date: $this->tanggal($d, $inv),
            invoice_id: (int) $inv->id,
            return_type: $r['jenis'],
            external_return_number: $d->external_return_number ?: (collect($r['retur_shopee'])->pluck('sn')->implode(', ') ?: null),
            notes: $this->catatan($d, 'Diposting dari rincian dana Shopee: refund ' . rupiah($r['refund']) . ', diganti ' . rupiah($r['ganti']) . ', cair ' . rupiah($r['cair']) . '. Barang dianggap tidak kembali.'),
            refund_target: 'hold',
            appeal_result: $banding,
            journal_reversal: SalesReturnService::barisTersimpan($b['pembalikan']) ?? [],
            journal_settlement: SalesReturnService::barisTersimpan($set),
            return_case: $r['kasus'],
        );

        $this->retur->post($dto, $d->id);

        return ['jurnal' => $this->jurnalDokumen($d->id, $inv->id), 'catatan' => []];
    }

    /** B — tutup draft dobel + jurnal koreksi piutang & stok retur lama. */
    private function koreksiDobel(array $r): array
    {
        /** @var SalesReturn $d */
        $d    = $r['draft'];
        $inv  = $r['invoice'];
        $akun = $this->retur->akunDana($inv->customer_id);
        $id   = fn (string $kode) => (int) Account::where('code', $kode)->value('id');
        $tgl  = $this->tanggal($d, $inv);

        $lines = [
            new JournalLineDTO($akun['retur']['id'], $r['dibalik'], 0, 'Retur penjualan - ' . $inv->invoice_number . ' (refund Shopee)'),
            new JournalLineDTO($akun['piutang']['id'], 0, $r['dibalik'], 'Piutang dihapus — dibuka ulang saat faktur terbit'),
        ];
        $sisa = round($r['nilai'] - $r['dibalik'], 2);
        if ($sisa > 0.5) {
            // Bagian pesanan yang tidak direfund: faktur dilunasi uang muka, titipan dicairkan.
            $lines[] = new JournalLineDTO($akun['uang_muka']['id'], $sisa, 0, 'Faktur tuntas dari uang muka');
            $lines[] = new JournalLineDTO($akun['piutang']['id'], 0, $sisa, 'Piutang lunas');
            $lines[] = new JournalLineDTO($akun['ditahan']['id'], 0, $sisa, 'Pelepasan saldo ditahan');
        }
        // Dana cair & potongan Shopee (admin/premi) sesuai rincian dana.
        if ($r['cair'] > 0.5) {
            $lines[] = new JournalLineDTO($akun['dompet']['id'], $r['cair'], 0, 'Dana cair sesuai rincian dana Shopee');
        } elseif ($r['cair'] < -0.5) {
            $lines[] = new JournalLineDTO($akun['dompet']['id'], 0, -$r['cair'], 'Dipotong dari Saldo Penjualan');
        }
        if ($r['potongan'] > 0.5) {
            $lines[] = new JournalLineDTO($akun['admin']['id'], $r['potongan'], 0, 'Potongan Shopee (admin/premi) sesuai rincian dana');
        }

        // Stok yang dulu dikembalikan retur lama → barangnya tidak ada: hapus ke Beban Kerugian Retur.
        $catatan = [];
        $repairWh = \App\Core\Inventory\Warehouse::repairId();
        $engine = app(InventoryEngine::class);
        foreach ($r['lama'] as $lama) {
            $layers = StockLayer::where('source_type', 'sales_return')->where('source_id', $lama->id)->where('qty_remaining', '>', 0)->get();
            foreach ($layers as $layer) {
                $qty  = (float) $layer->qty_remaining;
                $cost = round($qty * (float) $layer->unit_cost, 2);
                $engine->ledger((int) $layer->product_id, (int) $layer->warehouse_id, 0, $qty, 'sales_return_writeoff', $lama->return_number, 'Barang retur tidak ada — dihapus (data Shopee)', (int) $lama->id, true);
                $layer->qty_remaining = 0;
                $layer->save();
                InventoryCostLayer::create([
                    'product_id' => $layer->product_id, 'qty_out' => $qty, 'unit_cost' => $layer->unit_cost,
                    'reference_type' => 'sales_return_writeoff', 'reference_id' => $lama->id,
                ]);
                if ($cost > 0) {
                    $persediaan = $id((int) $layer->warehouse_id === (int) $repairWh ? AccountCodeEnum::INVENTORY_REPAIR : AccountCodeEnum::INVENTORY);
                    $lines[] = new JournalLineDTO($id(AccountCodeEnum::SALES_LOSS), $cost, 0, 'Barang retur ' . $lama->return_number . ' tidak ada');
                    $lines[] = new JournalLineDTO($persediaan, 0, $cost, 'Stok retur ' . $lama->return_number . ' dihapus');
                }
                $catatan[] = "stok dihapus: produk {$layer->product_id} gudang {$layer->warehouse_id} qty {$qty} (" . rupiah($cost) . ')';
            }
        }

        app(JournalPostingService::class)->post(new JournalEntryDTO(
            date: $tgl,
            reference_type: 'sales_return_koreksi',
            reference_id: $d->id,
            description: 'Koreksi retur dobel ' . $d->return_number . ' (data Shopee) - ' . $inv->invoice_number,
            lines: $lines,
            reference_number: $d->return_number,
        ));

        $d->forceFill([
            'status' => 'void', 'stage' => 'batal', 'ar_credited' => $r['dibalik'],
            'notes'  => $this->catatan($d, $r['alasan'] . ' Piutang ' . rupiah($r['dibalik']) . ' dihapus lewat jurnal koreksi; barang dianggap tidak kembali.'),
        ])->save();
        $inv->update([
            'returned_amount'       => round((float) $inv->returned_amount + $r['dibalik'], 2),
            'advance_applied'       => round((float) $inv->advance_applied + max(0, $sisa), 2),
            'marketplace_processed' => true,
            'marketplace_fee'       => round(max(0, $r['potongan']), 2),
        ]);

        return ['jurnal' => $this->jurnalDokumen($d->id, $inv->id), 'catatan' => $catatan];
    }

    /** C — retur batal di Shopee: tutup draft, faktur dicairkan bila belum. */
    private function tutupBatal(array $r): array
    {
        /** @var SalesReturn $d */
        $d   = $r['draft'];
        $inv = $r['invoice'];

        // Sama dengan "Retur Tidak Jadi" (batalkanDraft), tapi potongannya angka Shopee — bukan
        // taksiran biaya admin.
        if ($this->retur->sisaDitahan($inv) <= 0.5) {
            throw new \Exception('Saldo Ditahan pesanan ini sudah nol — cek manual.');
        }

        $d->forceFill(['status' => 'void', 'stage' => 'batal', 'notes' => $this->catatan($d, $r['alasan'])])->save();
        $journal = app(MarketplaceEngineService::class)->handle($inv, max(0.0, $r['potongan']), $this->tanggal($d, $inv), null, true);
        if (!$journal) {
            throw new \Exception('Pencairan faktur tidak membentuk jurnal.');
        }

        return ['jurnal' => $this->jurnalDokumen($d->id, $inv->id), 'catatan' => ['Retur tidak jadi — dicairkan dengan potongan Shopee ' . rupiah($r['potongan'])]];
    }

    /** Jurnal aktif yang menempel ke retur / faktur ini, dibuat dalam proses ini. */
    private function jurnalDokumen(int $returId, int $invId): array
    {
        return DB::table('journals as j')
            ->join('journal_lines as l', 'l.journal_id', '=', 'j.id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('j.status', '!=', 'void')
            ->where('j.created_at', '>=', now()->subMinutes(5))
            ->where(fn ($q) => $q->where(fn ($w) => $w->whereIn('j.reference_type', ['sales_return', 'sales_return_koreksi'])->where('j.reference_id', $returId))
                ->orWhere(fn ($w) => $w->where('j.reference_type', 'sales_invoice_settlement')->where('j.reference_id', $invId)))
            ->orderBy('j.id')->orderBy('l.id')
            ->get(['j.journal_number', 'j.date', 'a.code', 'a.name', 'l.debit', 'l.credit'])
            ->map(fn ($x) => (array) $x)->all();
    }
}
