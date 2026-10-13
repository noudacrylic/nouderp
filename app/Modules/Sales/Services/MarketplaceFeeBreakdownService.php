<?php

namespace App\Modules\Sales\Services;

use App\Models\MarketplaceConfig;
use App\Models\MarketplaceOrderFee;
use App\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;

/**
 * Rincian potongan marketplace per pesanan: biaya admin, affiliate, pajak, dan saldo yang masuk
 * ke Saldo Penjualan.
 *
 * Sumber, dari yang paling dipercaya:
 *  1. Jubelio `escrow_list` — Shopee: rincian lengkap begitu pesanan selesai. TikTok: baru
 *     terisi ±7–10 hari setelah selesai, hanya 3 angka (fee_and_tax_amount gabungan komisi +
 *     affiliate + pajak, shipping_cost_amount, settlement_amount); pajaknya ditaksir 0,5%
 *     dan sisanya jadi "biaya admin & affiliate". Pesanan sudah selesai = FINAL.
 *  2. Jubelio angka global (service_fee, order_processing_fee, escrow_amount) — estimasi.
 *  3. Setingan integrasi (% admin + biaya tetap, % pajak) — estimasi, tidak disimpan.
 *
 * "Lain-lain" = sisa yang tak terpetakan (voucher toko, selisih ongkir, penyesuaian) supaya
 * kotor − semua potongan = saldo masuk selalu pas.
 */
class MarketplaceFeeBreakdownService
{
    /** Kolom escrow Shopee yang termasuk biaya admin. */
    public const ADMIN_KEYS = [
        'commission_fee'                                 => 'Komisi',
        'service_fee'                                    => 'Biaya layanan',
        'seller_order_processing_fee'                    => 'Biaya proses pesanan',
        'seller_transaction_fee'                         => 'Biaya transaksi',
        'campaign_fee'                                   => 'Biaya kampanye',
        'shipping_seller_protection_fee_amount'          => 'Premi perlindungan pengiriman',
        'delivery_seller_protection_fee_premium_amount'  => 'Premi pengiriman',
        'lff_seller_protection_fee_amount'               => 'Premi perlindungan',
        'fbs_fee'                                        => 'Biaya FBS',
        'sspl_fee'                                       => 'Biaya SSPL',
        'pay_per_sale'                                   => 'Iklan per penjualan',
        'ads_escrow_top_up_fee_or_technical_support_fee' => 'Biaya iklan / dukungan teknis',
    ];

    /**
     * Rincian dari detail pesanan Jubelio, atau null bila Jubelio tak membawa angka potongan.
     *
     * @param float $kotor nilai pesanan menurut ERP (grand_total SO = nilai yang ditahan)
     */
    public function dariJubelio(array $d, float $kotor, bool $selesai, ?MarketplaceConfig $cfg = null, $tanggalPesanan = null): ?array
    {
        $e = is_array($d['escrow_list'] ?? null) ? $d['escrow_list'] : [];

        if (isset($e['escrow_amount']) && (float) $e['escrow_amount'] > 0) {
            $rincian = [];
            foreach (self::ADMIN_KEYS as $k => $label) {
                $v = round(abs((float) ($e[$k] ?? 0)), 2);
                if ($v > 0) {
                    $rincian[$label] = $v;
                }
            }
            $pajak = (float) ($e['withholding_tax'] ?? 0)
                ?: (float) ($e['withholding_pit_tax'] ?? 0) + (float) ($e['withholding_cit_tax'] ?? 0);
            $saldo = (float) ($e['escrow_amount_after_adjustment'] ?? 0) ?: (float) $e['escrow_amount'];

            return $this->susun($kotor, $rincian, abs((float) ($e['order_ams_commission_fee'] ?? 0)), abs($pajak), $saldo, 'jubelio', $selesai) + ['pajak_asli' => true];
        }

        // TikTok/Tokopedia: settlement gabungan, tanpa rincian affiliate.
        if (isset($e['settlement_amount']) && (float) $e['settlement_amount'] > 0) {
            $feePajak = abs((float) ($e['fee_and_tax_amount'] ?? 0));
            $ongkir   = abs((float) ($e['shipping_cost_amount'] ?? 0));
            $pajak    = $cfg ? min($feePajak, $this->taksiranPajak($cfg, $kotor, $tanggalPesanan)) : 0.0;
            $rincian  = array_filter([
                'Komisi, layanan & affiliate (TikTok tidak merinci)' => round($feePajak - $pajak, 2),
                'Ongkos kirim dibebankan ke penjual'                 => $ongkir,
            ], fn ($v) => $v > 0);

            return $this->susun($kotor, $rincian, 0.0, $pajak, (float) $e['settlement_amount'], 'jubelio', $selesai);
        }

        // Belum ada rincian escrow: angka global Jubelio.
        $layanan = (float) ($d['service_fee'] ?? 0);
        $proses  = (float) ($d['order_processing_fee'] ?? 0);
        $selisih = round((float) ($d['sub_total'] ?? 0) + (float) ($d['shipping_cost'] ?? 0) - (float) ($d['grand_total'] ?? 0), 2);
        $escrow  = (float) ($d['escrow_amount'] ?? 0);

        $rincian = array_filter([
            'Biaya admin & layanan' => $layanan,
            'Biaya proses pesanan'  => $proses,
        ], fn ($v) => $v > 0);
        if (!$rincian && $selisih > 0) {
            $rincian['Potongan dilaporkan Jubelio'] = $selisih;
        }
        if (!$rincian && $escrow <= 0) {
            return null;
        }

        $pajak = $cfg ? $this->taksiranPajak($cfg, $kotor, $tanggalPesanan) : 0.0;
        $admin = array_sum($rincian);
        $saldo = $escrow > 0 ? $escrow : $kotor - $admin - $pajak;

        // Escrow global bisa sudah memuat affiliate/premi yang belum dirinci → masuk lain-lain.
        return $this->susun($kotor, $rincian, 0.0, $pajak, $saldo, 'jubelio', false);
    }

    /** Estimasi dari setingan integrasi marketplace. */
    public function taksiran(MarketplaceConfig $cfg, float $kotor, $tanggalPesanan = null): array
    {
        $admin = round($kotor * (float) $cfg->admin_fee_percent / 100 + (float) $cfg->admin_fee_fixed, 0);
        $pajak = $this->taksiranPajak($cfg, $kotor, $tanggalPesanan);

        return $this->susun($kotor, $admin > 0 ? ['Biaya admin (setingan)' => $admin] : [], 0.0, $pajak,
            $kotor - $admin - $pajak, 'setingan', false);
    }

    /**
     * Rincian dari laporan penghasilan marketplace yang diunggah di Rekonsiliasi — selalu final,
     * pajaknya angka asli (PPh 22 dipungut).
     *
     * @param array{admin_rincian: array<string, float>, affiliate: float, pajak: float, saldo: float} $r
     */
    public function dariLaporan(array $r, float $kotor): array
    {
        return $this->susun($kotor, $r['admin_rincian'], $r['affiliate'], $r['pajak'], $r['saldo'], 'laporan', true)
            + ['pajak_asli' => true];
    }

    /**
     * Simpan rincian. Urutan kepercayaan: laporan penghasilan > Jubelio final > Jubelio estimasi;
     * yang lebih rendah tidak menimpa yang lebih tinggi. Pajak asli mengisi faktur pesanan ini
     * (dibaca Laporan Pajak).
     */
    public function simpan(SalesOrder $so, array $b): ?MarketplaceOrderFee
    {
        if (!in_array($b['sumber'], ['jubelio', 'laporan'], true)) {
            return null;
        }
        $ada = MarketplaceOrderFee::where('sales_order_id', $so->id)->first();
        if ($ada && (($ada->final && !$b['final']) || ($ada->sumber === 'laporan' && $b['sumber'] !== 'laporan'))) {
            return $ada;
        }

        $row = MarketplaceOrderFee::updateOrCreate(['sales_order_id' => $so->id], [
            'kotor'         => $b['kotor'],
            'admin'         => $b['admin'],
            'admin_rincian' => $b['admin_rincian'],
            'affiliate'     => $b['affiliate'],
            'pajak'         => $b['pajak'],
            'lain'          => $b['lain'],
            'saldo_masuk'   => $b['saldo_masuk'],
            'sumber'        => $b['sumber'],
            'final'         => $b['final'],
            'pajak_asli'    => !empty($b['pajak_asli']),
            'diambil_at'    => now(),
        ]);

        // Hanya pajak yang memang dirinci marketplace (escrow Shopee, laporan TikTok) dicatat
        // sebagai pajak asli; pajak dari angka gabungan Jubelio TikTok hanyalah taksiran.
        if ($b['final'] && !empty($b['pajak_asli'])) {
            SalesInvoice::where('sales_order_id', $so->id)->where('status', '!=', 'void')
                ->update(['mp_tax_withheld' => $b['pajak'], 'mp_tax_source' => $b['sumber'], 'updated_at' => now()]);
        }

        return $row;
    }

    /** Rincian untuk ditampilkan: yang tersimpan, atau estimasi setingan. Null = bukan marketplace. */
    public function untukPesanan(SalesOrder $so): ?array
    {
        // Konvensi LAMA (lihat migrasi add_fee_at_settlement_to_sales_invoices): grand_total SO
        // sudah BERSIH dan biaya adminnya tercatat di `marketplace_fee`. Nilai pesanan yang
        // ditampilkan harus dikembalikan ke kotor — kalau tidak, kartu memotong biaya admin dua
        // kali (sekali sudah di grand_total, sekali lagi di taksiran).
        //
        // Konvensi BARU juga mengisi `marketplace_fee` (catatan "sudah dibebankan" saat pesanan
        // selesai) TANPA menurunkan grand_total. Pembedanya: hanya di konvensi lama biaya itu
        // benar-benar sudah dikurangkan, yaitu grand_total + marketplace_fee = subtotal + ongkir.
        $fee           = round((float) ($so->marketplace_fee ?? 0), 2);
        $kotorAsli     = (float) $so->subtotal + (float) ($so->shipping_cost ?? 0);
        $sudahDipotong = $fee > 0 && abs((float) $so->grand_total + $fee - $kotorAsli) < 1 ? $fee : 0.0;

        $row = MarketplaceOrderFee::where('sales_order_id', $so->id)->first();
        if ($row) {
            $kotor = (float) $row->kotor;
            $lain  = (float) $row->lain;
            // Rincian Jubelio untuk SO lama tersimpan dengan kotor = grand_total bersih, dan
            // biaya yang sudah dipotong itu muncul sebagai "lain-lain" negatif penyeimbang.
            if ($sudahDipotong > 0 && abs($kotor - (float) $so->grand_total) < 1) {
                $kotor += $sudahDipotong;
                $lain  += $sudahDipotong;
            }

            return [
                'kotor' => $kotor, 'admin' => $row->admin, 'admin_rincian' => $row->admin_rincian ?? [],
                'affiliate' => $row->affiliate, 'pajak' => $row->pajak, 'lain' => round($lain, 2),
                'saldo_masuk' => $row->saldo_masuk, 'sumber' => $row->sumber, 'final' => $row->final,
                'diambil_at' => $row->diambil_at,
            ];
        }

        $cfg = MarketplaceConfig::where('customer_id', $so->customer_id)->where('is_active', true)->first();
        if (!$cfg) {
            return null;
        }

        if ($sudahDipotong > 0) {
            // Biaya admin SUDAH tercatat — tampilkan angka itu, bukan taksiran baru atas nilai bersih.
            $kotor = (float) $so->grand_total + $sudahDipotong;
            $pajak = $this->taksiranPajak($cfg, $kotor, $this->tanggalPesanan($so));

            return $this->susun($kotor, ['Biaya admin (tercatat di faktur)' => $sudahDipotong], 0.0, $pajak,
                $kotor - $sudahDipotong - $pajak, 'setingan', false) + ['diambil_at' => null];
        }

        return $this->taksiran($cfg, (float) $so->grand_total, $this->tanggalPesanan($so)) + ['diambil_at' => null];
    }

    /** Potongan di luar pajak (admin + affiliate + lain-lain) — yang dibebankan ke Beban Admin. */
    public static function potonganNonPajak(array $b): float
    {
        return max(0.0, round($b['admin'] + $b['affiliate'] + $b['lain'], 2));
    }

    public function tanggalPesanan(SalesOrder $so): ?string
    {
        return MarketplaceConfig::tanggalPesananFaktur((object) ['sales_order_id' => $so->id, 'invoice_date' => $so->order_date]);
    }

    private function taksiranPajak(MarketplaceConfig $cfg, float $kotor, $tanggalPesanan): float
    {
        return $cfg->pajakBerlakuPada($tanggalPesanan) ? $cfg->pajakDari($kotor) : 0.0;
    }

    private function susun(float $kotor, array $rincian, float $affiliate, float $pajak, float $saldo, string $sumber, bool $final): array
    {
        $admin = round(array_sum($rincian), 2);

        return [
            'kotor'         => round($kotor, 2),
            'admin'         => $admin,
            'admin_rincian' => $rincian,
            'affiliate'     => round($affiliate, 2),
            'pajak'         => round($pajak, 2),
            'lain'          => round($kotor - $admin - $affiliate - $pajak - $saldo, 2),
            'saldo_masuk'   => round($saldo, 2),
            'sumber'        => $sumber,
            'final'         => $final,
        ];
    }
}
