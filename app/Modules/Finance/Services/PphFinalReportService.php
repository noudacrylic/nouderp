<?php

namespace App\Modules\Finance\Services;

use App\Models\MarketplaceConfig;
use App\Models\TaxOmzetAdjustment;
use App\Models\TaxPayment;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Laporan PPh Final UMKM 0,5% (PP 20/2026) per masa pajak.
 *
 * Omzet = pendapatan faktur menurut TANGGAL FAKTUR, sama dengan Laba Rugi:
 *   subtotal − diskon global − kode unik + biaya tambahan. Ongkir tidak termasuk (Titipan Ongkir).
 * Retur mengurangi omzet bulan returnya.
 *
 * Setiap faktur masuk satu dari tiga keranjang:
 *  - dipungut : marketplace pemungut PPh 22, pesanan MASUK sejak tanggal mulai pungut dan sudah
 *               SELESAI (pemotongan terjadi saat selesai) — pungutan itu = pelunasan PPh Final.
 *  - menunggu : seperti di atas tapi pesanan belum selesai — akan dipungut saat selesai.
 *  - sendiri  : selain itu (kasir, ERP langsung, website, pesanan marketplace yang MASUK sebelum
 *               tanggal pungut walau selesai sesudahnya, pesanan yang diretur/batal sebelum
 *               selesai — tak pernah dipungut; omzetnya dinolkan oleh retur/void-nya sendiri).
 *
 * Pajak setor sendiri dirinci per faktur (0,5% × omzet di atas jatah bebas) dan ditandai
 * lunas/belum dengan membagi setoran masa itu ke faktur berurutan tanggal.
 *
 * Kolom "pajak dipungut" di rekap memakai bulan PESANAN SELESAI (= bulan bukti pungut
 * marketplace), sedangkan pembagian omzet memakai bulan faktur.
 *
 * Omzet Rp500 jt pertama setahun bebas pajak (WP orang pribadi); jatah bebas dipakai berurutan
 * menurut omzet kumulatif dan, di dalam satu bulan, dialokasikan ke omzet "setor sendiri"
 * lebih dulu karena bagian marketplace tetap dipungut.
 */
class PphFinalReportService
{
    public const TARIF_PERSEN  = 0.5;
    public const OMZET_BEBAS   = 500_000_000;
    public const BATAS_OMZET   = 4_800_000_000;
    public const AKUN_PPH_FINAL = '5012';

    public const KANAL_LABEL = [
        'kasir'   => 'Kasir',
        'erp'     => 'ERP langsung',
        'website' => 'Website',
    ];

    public const STATUS_LABEL = [
        'dipungut' => 'Dipungut marketplace',
        'menunggu' => 'Menunggu selesai',
        'sendiri'  => 'Setor sendiri',
    ];

    /** Status pembayaran pajak per faktur. */
    public const BAYAR_LABEL = [
        'lunas'       => 'Sudah disetor',
        'sebagian'    => 'Disetor sebagian',
        'belum'       => 'Belum disetor',
        'bebas'       => 'Bebas (jatah Rp500 jt)',
        'dipotong_mp' => 'Dipotong marketplace',
        'menunggu_mp' => 'Dipotong saat selesai',
        'nihil'       => 'Tanpa pajak',
    ];

    /** @var Collection<int, MarketplaceConfig>|null */
    private ?Collection $configs = null;

    // ─────────────────────────── Rekap setahun ───────────────────────────

    /** 12 baris ringkasan masa pajak + total setahun. */
    public function rekapTahun(int $tahun): array
    {
        $from = Carbon::create($tahun, 1, 1)->startOfDay();
        $to   = $from->copy()->endOfYear();

        $faktur = $this->fakturRows(fn ($q) => $q->whereBetween('si.invoice_date', [$from->toDateString(), $to->toDateString()]));
        $retur  = $this->returRows($from, $to);
        $setor  = $this->setoranPerMasa($tahun);
        $luar   = $this->omzetLuarPerMasa($tahun);
        $pungut = $this->pajakDipungutPerBulanSelesai($from, $to);

        $bulan = [];
        $kumulatif = 0.0;
        for ($m = 1; $m <= 12; $m++) {
            $awal = Carbon::create($tahun, $m, 1);
            $fBulan = $faktur->filter(fn ($r) => (int) substr($r->tanggal, 5, 2) === $m);
            $rBulan = $retur->filter(fn ($r) => (int) substr($r->tanggal, 5, 2) === $m);

            $ringkas = $this->ringkas($fBulan, $rBulan, $kumulatif, (float) ($luar[$m] ?? 0));
            $kumulatif += $ringkas['omzet_neto'];

            $ringkas['masa']        = $awal;
            $ringkas['sudah_setor'] = (float) ($setor[$m] ?? 0);
            $ringkas['pungut_bulan']          = (float) ($pungut[$m]['pajak'] ?? 0);
            $ringkas['pungut_bulan_taksiran'] = (float) ($pungut[$m]['taksiran'] ?? 0);
            $ringkas['pungut_bulan_faktur']   = (int) ($pungut[$m]['faktur'] ?? 0);
            $ringkas += $this->statusSetor($ringkas['harus_setor'], $ringkas['sudah_setor'], $awal);
            $bulan[$m] = $ringkas;
        }

        $total = [];
        foreach (['omzet_bruto', 'omzet_luar', 'retur', 'omzet_neto', 'omzet_dipungut', 'omzet_sendiri', 'omzet_bebas',
                  'pajak_dipungut', 'pajak_menunggu', 'harus_setor', 'sudah_setor',
                  'pungut_bulan', 'pungut_bulan_taksiran'] as $k) {
            $total[$k] = array_sum(array_column($bulan, $k));
        }

        return [
            'tahun'  => $tahun,
            'bulan'  => $bulan,
            'total'  => $total,
            'kumulatif' => $kumulatif,
            'proyeksi'  => $this->proyeksi($bulan, $tahun),
        ];
    }

    // ─────────────────────────── Rincian satu masa ───────────────────────────

    public function rincianMasa(int $tahun, int $bulan): array
    {
        $awal  = Carbon::create($tahun, $bulan, 1)->startOfDay();
        $akhir = $awal->copy()->endOfMonth();

        $faktur = $this->fakturRows(fn ($q) => $q->whereBetween('si.invoice_date', [$awal->toDateString(), $akhir->toDateString()]));
        $retur  = $this->returRows($awal, $akhir);

        $kumulatifSebelum = $bulan > 1
            ? $this->omzetNeto(Carbon::create($tahun, 1, 1), $awal->copy()->subDay())
            : 0.0;

        $luar = (float) TaxOmzetAdjustment::pphFinal()->whereDate('period', $awal)->value('amount');
        $ringkas = $this->ringkas($faktur, $retur, $kumulatifSebelum, $luar);
        $ringkas['masa'] = $awal;
        $ringkas['sudah_setor'] = (float) TaxPayment::pphFinal()->whereDate('period', $awal)->sum('amount');
        $bayar = $this->alokasiPerFaktur($faktur, $retur, $luar, $kumulatifSebelum, $ringkas['sudah_setor'], $ringkas['harus_setor']);
        $pungut = $this->pajakDipungutPerBulanSelesai($awal, $akhir)[$bulan] ?? ['pajak' => 0.0, 'taksiran' => 0.0, 'faktur' => 0];
        $ringkas['pungut_bulan']          = (float) $pungut['pajak'];
        $ringkas['pungut_bulan_taksiran'] = (float) $pungut['taksiran'];
        $ringkas['pungut_bulan_faktur']   = (int) $pungut['faktur'];
        $ringkas += $this->statusSetor($ringkas['harus_setor'], $ringkas['sudah_setor'], $awal);

        // Per kanal.
        $kanal = [];
        foreach ($faktur as $r) {
            $k = &$kanal[$r->kanal];
            $k ??= ['label' => $r->kanal_label, 'faktur' => 0, 'omzet' => 0.0, 'retur' => 0.0,
                    'dipungut' => 0.0, 'menunggu' => 0.0, 'sendiri' => 0.0, 'pajak_dipungut' => 0.0];
            $k['faktur']++;
            $k['omzet'] += $r->omzet;
            $k[$r->status] += $r->omzet;
            $k['pajak_dipungut'] += $r->pajak;
            unset($k);
        }
        foreach ($retur as $r) {
            $k = &$kanal[$r->kanal];
            $k ??= ['label' => $r->kanal_label, 'faktur' => 0, 'omzet' => 0.0, 'retur' => 0.0,
                    'dipungut' => 0.0, 'menunggu' => 0.0, 'sendiri' => 0.0, 'pajak_dipungut' => 0.0];
            $k['retur'] += $r->nilai;
            unset($k);
        }
        uasort($kanal, fn ($a, $b) => $b['omzet'] <=> $a['omzet']);

        // Transisi: faktur bulan-bulan SEBELUMNYA yang baru selesai (dan dipungut) di masa ini.
        $soSelesai = DB::table('jubelio_order_links')
            ->whereRaw('mp_completed_at BETWEEN ? AND ?',
                [$awal->toDateTimeString(), $akhir->copy()->endOfDay()->toDateTimeString()])
            ->whereNotNull('sales_order_id')
            ->pluck('sales_order_id')->unique()->values()->all();
        $transisi = $soSelesai
            ? $this->fakturRows(fn ($q) => $q->where('si.invoice_date', '<', $awal->toDateString())
                ->whereIn('si.sales_order_id', $soSelesai))
                ->where('status', 'dipungut')
                ->filter(fn ($r) => $r->selesai_at && Carbon::parse($r->selesai_at)->between($awal, $akhir->copy()->endOfDay()))
                ->values()
            : collect();

        return [
            'ringkas'   => $ringkas,
            'kanal'     => $kanal,
            'faktur'    => $faktur->sortBy([['tanggal', 'asc'], ['nomor', 'asc']])->values(),
            'retur'     => $retur->values(),
            'transisi'  => $transisi,
            'bayar'     => $bayar,
            'kumulatif_sebelum' => $kumulatifSebelum,
            'peringatan' => $this->peringatanKonfigurasi(),
        ];
    }

    // ─────────────────────────── Bahan dasar ───────────────────────────

    /**
     * Faktur terposting + kanal + status pungutan. $filter menambah syarat ke query `si`.
     *
     * @return Collection<int, object>
     */
    public function fakturRows(callable $filter): Collection
    {
        $q = DB::table('sales_invoices as si')
            ->join('customers as c', 'c.id', '=', 'si.customer_id')
            ->whereNotIn('si.status', ['draft', 'void'])
            ->select([
                'si.id', 'si.invoice_number as nomor', 'si.invoice_date as tanggal', 'si.customer_id',
                'si.sales_order_id', 'si.delivery_method', 'si.mp_tax_withheld', 'si.mp_tax_source',
                'c.name as pelanggan', 'c.is_marketplace', 'c.marketplace_code',
                DB::raw('(si.subtotal - COALESCE(si.global_discount_amount,0) - COALESCE(si.unique_code,0) + COALESCE(si.additional_fee,0)) as omzet'),
            ]);

        $filter($q);
        $rows = $q->get();
        if ($rows->isEmpty()) {
            return $rows;
        }

        // Data pendukung diambil per kelompok (bukan subquery per faktur — ribuan faktur/bulan).
        $soIds  = $rows->pluck('sales_order_id')->filter()->unique()->values();
        $invIds = $rows->pluck('id');

        $selesai = collect();
        $pesanan = collect();
        $batal   = collect();
        $webSo   = collect();
        $webInv  = collect();
        foreach ($soIds->chunk(1000) as $chunk) {
            $selesai = $selesai->union(DB::table('jubelio_order_links')->whereIn('sales_order_id', $chunk)
                ->groupBy('sales_order_id')
                ->selectRaw('sales_order_id, MAX(mp_completed_at) as t')
                ->pluck('t', 'sales_order_id'));
            $pesanan = $pesanan->union(DB::table('sales_orders as so')->whereIn('so.id', $chunk)
                ->selectRaw('so.id, COALESCE((SELECT MIN(l.snap_order_date) FROM jubelio_order_links l WHERE l.sales_order_id = so.id), so.order_date) as t')
                ->pluck('t', 'id'));
            $batal = $batal->union(DB::table('jubelio_order_links')->whereIn('sales_order_id', $chunk)
                ->whereIn('last_status', ['returned', 'canceled'])
                ->pluck('sales_order_id', 'sales_order_id'));
            $webSo = $webSo->merge(DB::table('web_payments')->whereIn('sales_order_id', $chunk)->pluck('sales_order_id'))
                ->merge(DB::table('midtrans_transactions')->where('source', 'link')->whereIn('sales_order_id', $chunk)->pluck('sales_order_id'));
        }
        $pajakJurnal = collect();
        $akunPajak = $this->akunPajakIds();
        foreach ($invIds->chunk(1000) as $chunk) {
            $webInv = $webInv->merge(DB::table('midtrans_transactions')->where('source', 'link')->whereIn('sales_invoice_id', $chunk)->pluck('sales_invoice_id'));
            if ($akunPajak) {
                $pajakJurnal = $pajakJurnal->union(DB::table('journal_lines as l')
                    ->join('journals as j', 'j.id', '=', 'l.journal_id')
                    ->where('j.reference_type', 'sales_invoice_settlement')
                    ->where('j.status', 'posted')
                    ->whereIn('j.reference_id', $chunk)
                    ->whereIn('l.account_id', $akunPajak)
                    ->groupBy('j.reference_id')
                    ->selectRaw('j.reference_id as rid, SUM(l.debit) as t')
                    ->pluck('t', 'rid'));
            }
        }
        $webSo  = array_flip($webSo->filter()->unique()->all());
        $webInv = array_flip($webInv->filter()->unique()->all());

        return $rows->map(function ($r) use ($selesai, $pesanan, $batal, $webSo, $webInv, $pajakJurnal) {
            $r->selesai_at   = $r->sales_order_id ? ($selesai[$r->sales_order_id] ?? null) : null;
            $r->pesanan_at   = ($r->sales_order_id ? ($pesanan[$r->sales_order_id] ?? null) : null) ?? $r->tanggal;
            $r->batal_mp     = $r->sales_order_id && isset($batal[$r->sales_order_id]);
            $r->is_web       = isset($webInv[$r->id]) || ($r->sales_order_id && isset($webSo[$r->sales_order_id]));
            $r->pajak_jurnal = (float) ($pajakJurnal[$r->id] ?? 0);

            return $this->klasifikasi($r);
        });
    }

    private function klasifikasi(object $r): object
    {
        $r->omzet = round((float) $r->omzet, 2);
        $cfg = $this->configs()->get($r->customer_id);

        if ($r->is_marketplace) {
            $r->kanal = 'mp_' . ($r->marketplace_code ?: $r->customer_id);
            $r->kanal_label = $r->pelanggan;
        } elseif ($r->is_web) {
            $r->kanal = 'website';
        } elseif (!$r->sales_order_id && $r->delivery_method === 'ambil_toko') {
            $r->kanal = 'kasir';
        } else {
            $r->kanal = 'erp';
        }
        $r->kanal_label ??= self::KANAL_LABEL[$r->kanal];

        $r->pajak = 0.0;
        $r->sumber_pajak = null;

        if ($cfg && $cfg->isPemungutPajak() && $cfg->pesananKenaPungut($r->pesanan_at)) {
            if ($r->selesai_at) {
                $r->status = 'dipungut';
            } else {
                // Diretur/batal sebelum selesai → tak akan pernah dipungut marketplace.
                $r->status = $r->batal_mp ? 'sendiri' : 'menunggu';
            }
        } else {
            // Bukan pemungut, atau pesanan masuk sebelum tanggal mulai pungut (walau selesai sesudahnya).
            $r->status = 'sendiri';
        }

        if ($r->status !== 'sendiri') {
            $tarif = (float) $cfg->tax_percent > 0 ? (float) $cfg->tax_percent : self::TARIF_PERSEN;
            if ($r->mp_tax_withheld !== null) {
                $r->pajak = (float) $r->mp_tax_withheld;
                $r->sumber_pajak = 'aktual';
            } elseif ((float) $r->pajak_jurnal > 0) {
                $r->pajak = (float) $r->pajak_jurnal;
                $r->sumber_pajak = 'jurnal';
            } else {
                $r->pajak = round(max(0, $r->omzet) * $tarif / 100, 0);
                $r->sumber_pajak = 'taksiran';
            }
        }

        $r->status_label = self::STATUS_LABEL[$r->status];

        return $r;
    }

    /** Retur terposting dalam rentang, membawa kanal & status faktur asalnya. */
    public function returRows(Carbon $from, Carbon $to): Collection
    {
        $rows = DB::table('sales_returns as sr')
            ->where('sr.status', 'posted')
            ->whereBetween('sr.return_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('sr.invoice_id')
            ->select(['sr.id', 'sr.return_number as nomor', 'sr.return_date as tanggal', 'sr.invoice_id', 'sr.reversed_amount'])
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $faktur = $this->fakturRows(fn ($q) => $q->whereIn('si.id', $rows->pluck('invoice_id')->unique()->all()))
            ->keyBy('id');

        // Nilai pembalikan baris lama (tanpa reversed_amount) = subtotal item yang kembali.
        $itemSum = DB::table('sales_return_items')
            ->whereIn('sales_return_id', $rows->whereNull('reversed_amount')->pluck('id')->all() ?: [0])
            ->where('condition', '!=', \App\Modules\Sales\Models\SalesReturn::CONDITION_NO_RETURN)
            ->groupBy('sales_return_id')
            ->selectRaw('sales_return_id, SUM(subtotal) as t')
            ->pluck('t', 'sales_return_id');

        return $rows->map(function ($r) use ($faktur, $itemSum) {
            $f = $faktur->get($r->invoice_id);
            $r->nilai = round((float) ($r->reversed_amount ?? $itemSum[$r->id] ?? 0), 2);
            $r->faktur_nomor = $f->nomor ?? '—';
            $r->kanal = $f->kanal ?? 'erp';
            $r->kanal_label = $f->kanal_label ?? self::KANAL_LABEL['erp'];
            $r->status = $f->status ?? 'sendiri';

            return $r;
        })->filter(fn ($r) => $r->nilai > 0)->values();
    }

    private function omzetNeto(Carbon $from, Carbon $to): float
    {
        $faktur = (float) DB::table('sales_invoices')
            ->whereNotIn('status', ['draft', 'void'])
            ->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()])
            ->sum(DB::raw('subtotal - COALESCE(global_discount_amount,0) - COALESCE(unique_code,0) + COALESCE(additional_fee,0)'));

        $luar = (float) TaxOmzetAdjustment::pphFinal()
            ->whereBetween('period', [$from->toDateString(), $to->toDateString()])->sum('amount');

        return $faktur + $luar - (float) $this->returRows($from, $to)->sum('nilai');
    }

    /** Ringkasan satu masa dari baris faktur & retur bulan itu. */
    private function ringkas(Collection $faktur, Collection $retur, float $kumulatifSebelum, float $luar = 0.0): array
    {
        $bruto = (float) $faktur->sum('omzet');
        $nilaiRetur = (float) $retur->sum('nilai');

        // Omzet di luar ERP dianggap setor sendiri (tidak lewat marketplace pemungut).
        $omzetSendiri  = (float) $faktur->where('status', 'sendiri')->sum('omzet')
                       - (float) $retur->where('status', 'sendiri')->sum('nilai')
                       + $luar;
        $omzetDipungut = (float) $faktur->whereIn('status', ['dipungut', 'menunggu'])->sum('omzet')
                       - (float) $retur->whereIn('status', ['dipungut', 'menunggu'])->sum('nilai');
        $neto = $bruto - $nilaiRetur + $luar;

        $sisaBebas = max(0.0, self::OMZET_BEBAS - max(0.0, $kumulatifSebelum));
        $bebasSendiri = min(max(0.0, $omzetSendiri), $sisaBebas);
        $dasarSendiri = max(0.0, $omzetSendiri - $bebasSendiri);

        return [
            'faktur_count'   => $faktur->count(),
            'omzet_bruto'    => $bruto,
            'omzet_luar'     => $luar,
            'retur'          => $nilaiRetur,
            'omzet_neto'     => $neto,
            'omzet_dipungut' => $omzetDipungut,
            'omzet_sendiri'  => $omzetSendiri,
            'omzet_bebas'    => $bebasSendiri,
            'dasar_setor'    => $dasarSendiri,
            'pajak_dipungut' => (float) $faktur->where('status', 'dipungut')->sum('pajak'),
            'pajak_menunggu' => (float) $faktur->where('status', 'menunggu')->sum('pajak'),
            'pajak_taksiran' => (float) $faktur->where('sumber_pajak', 'taksiran')->sum('pajak'),
            'harus_setor'    => floor($dasarSendiri * self::TARIF_PERSEN / 100),
            'kumulatif_akhir' => $kumulatifSebelum + $neto,
        ];
    }

    /**
     * Pajak setor sendiri per faktur + status bayarnya. Jatah bebas dipakai omzet di luar ERP
     * lebih dulu lalu faktur berurutan tanggal; setoran masa dibagi ke faktur dengan urutan yang
     * sama. Retur mengurangi total harus setor, jadi bila setoran sudah menutup total itu semua
     * faktur lunas; retur sendiri tidak menandai faktur mana pun lunas. Baris faktur diberi `pajak_setor`,
     * `bebas`, `pajak_faktur` (pajak MP atau setor sendiri) dan `bayar`.
     *
     * @return array ringkasan per status bayar: [status => ['faktur' => n, 'pajak' => Rp]]
     */
    private function alokasiPerFaktur(Collection $faktur, Collection $retur, float $luar, float $kumulatifSebelum, float $sudahSetor, float $harusSetor): array
    {
        $tarif = self::TARIF_PERSEN / 100;
        $sisaBebas = max(0.0, self::OMZET_BEBAS - max(0.0, $kumulatifSebelum));

        $bebasLuar = min(max(0.0, $luar), $sisaBebas);
        $sisaBebas -= $bebasLuar;
        $pajakLuar = round(max(0.0, $luar - $bebasLuar) * $tarif, 2);

        $sendiri = $faktur->where('status', 'sendiri')->sortBy([['tanggal', 'asc'], ['nomor', 'asc']]);
        foreach ($sendiri as $r) {
            $r->bebas = min(max(0.0, $r->omzet), $sisaBebas);
            $sisaBebas -= $r->bebas;
            $r->pajak_setor = round(max(0.0, $r->omzet - $r->bebas) * $tarif, 2);
        }
        // Retur hanya mengurangi pajak bila jatah bebas sudah habis terpakai.
        $kreditRetur = $sisaBebas > 0 ? 0.0
            : round((float) $retur->where('status', 'sendiri')->sum('nilai') * $tarif, 2);

        $lunasSemua = $harusSetor > 0 && $sudahSetor + 1 >= $harusSetor;
        $dana = $sudahSetor - $pajakLuar;
        foreach ($sendiri as $r) {
            if ($r->pajak_setor <= 0) {
                $r->bayar = $r->bebas > 0 ? 'bebas' : 'nihil';
            } elseif ($lunasSemua) {
                $r->bayar = 'lunas';
            } elseif ($dana + 1 >= $r->pajak_setor) {   // toleransi pembulatan rupiah
                $r->bayar = 'lunas';
                $dana -= $r->pajak_setor;
            } elseif ($dana > 0) {
                $r->bayar = 'sebagian';
                $dana = 0.0;
            } else {
                $r->bayar = 'belum';
            }
        }

        $ringkas = [];
        foreach ($faktur as $r) {
            if ($r->status !== 'sendiri') {
                $r->bebas = 0.0;
                $r->pajak_setor = 0.0;
                $r->bayar = $r->status === 'dipungut' ? 'dipotong_mp' : 'menunggu_mp';
            }
            $r->pajak_faktur = $r->status === 'sendiri' ? $r->pajak_setor : $r->pajak;
            $r->bayar_label = self::BAYAR_LABEL[$r->bayar];
            $ringkas[$r->bayar] ??= ['faktur' => 0, 'pajak' => 0.0];
            $ringkas[$r->bayar]['faktur']++;
            $ringkas[$r->bayar]['pajak'] += $r->pajak_faktur;
        }

        return $ringkas + ['_luar' => ['pajak' => $pajakLuar], '_retur' => ['pajak' => $kreditRetur]];
    }

    /** Status setoran + jatuh tempo (tanggal 15 bulan berikutnya). */
    private function statusSetor(float $harus, float $sudah, Carbon $masa): array
    {
        $jatuhTempo = $masa->copy()->addMonthNoOverflow()->day(15);
        $selisih = round($sudah - $harus, 0);
        $masaBerjalan = $masa->copy()->endOfMonth()->isFuture();

        if ($harus <= 0 && $sudah <= 0) {
            $status = $masaBerjalan ? 'berjalan' : 'nihil';
        } elseif ($masaBerjalan) {
            $status = 'berjalan';
        } elseif ($selisih == 0) {
            $status = 'lunas';
        } elseif ($selisih > 0) {
            $status = 'lebih';
        } elseif ($sudah > 0) {
            $status = 'kurang';
        } else {
            $status = 'belum';
        }

        return [
            'jatuh_tempo' => $jatuhTempo,
            'selisih'     => $selisih,
            'status'      => $status,
            'terlambat'   => in_array($status, ['belum', 'kurang'], true) && $jatuhTempo->isPast(),
        ];
    }

    /** Setoran tercatat per bulan masa (1..12). */
    private function setoranPerMasa(int $tahun): array
    {
        return TaxPayment::pphFinal()
            ->whereYear('period', $tahun)
            ->get(['period', 'amount'])
            ->groupBy(fn ($p) => (int) $p->period->format('n'))
            ->map(fn ($g) => (float) $g->sum('amount'))
            ->all();
    }

    /**
     * Pajak yang BENAR-BENAR dipungut marketplace per bulan pesanan selesai (bulan bukti pungut),
     * apa pun bulan fakturnya.
     *
     * @return array<int, array{pajak: float, taksiran: float, faktur: int}> per bulan (1..12)
     */
    public function pajakDipungutPerBulanSelesai(Carbon $from, Carbon $to): array
    {
        $soIds = DB::table('jubelio_order_links')
            ->whereBetween('mp_completed_at', [$from->copy()->startOfDay()->toDateTimeString(), $to->copy()->endOfDay()->toDateTimeString()])
            ->whereNotNull('sales_order_id')
            ->pluck('sales_order_id')->unique()->values();

        $out = [];
        foreach ($soIds->chunk(2000) as $chunk) {
            $rows = $this->fakturRows(fn ($q) => $q->whereIn('si.sales_order_id', $chunk->all()))
                ->where('status', 'dipungut');
            foreach ($rows as $r) {
                $m = (int) Carbon::parse($r->selesai_at)->format('n');
                $out[$m] ??= ['pajak' => 0.0, 'taksiran' => 0.0, 'faktur' => 0];
                $out[$m]['pajak'] += $r->pajak;
                $out[$m]['faktur']++;
                if ($r->sumber_pajak === 'taksiran') {
                    $out[$m]['taksiran'] += $r->pajak;
                }
            }
        }

        return $out;
    }

    /** Omzet di luar ERP per bulan masa (1..12). */
    private function omzetLuarPerMasa(int $tahun): array
    {
        return TaxOmzetAdjustment::pphFinal()
            ->whereYear('period', $tahun)
            ->get(['period', 'amount'])
            ->mapWithKeys(fn ($a) => [(int) $a->period->format('n') => (float) $a->amount])
            ->all();
    }

    /** Perkiraan omzet setahun dari rata-rata bulan yang sudah lewat. */
    private function proyeksi(array $bulan, int $tahun): array
    {
        $now = now();
        $bulanLewat = $tahun < $now->year ? 12 : ($tahun > $now->year ? 0 : $now->month - 1);
        $berjalan = $tahun === $now->year ? $now->month : null;

        $omzetLewat = 0.0;
        for ($m = 1; $m <= $bulanLewat; $m++) {
            $omzetLewat += $bulan[$m]['omzet_neto'];
        }
        $rata = $bulanLewat > 0 ? $omzetLewat / $bulanLewat : 0.0;
        $setahun = $bulanLewat >= 12 ? $omzetLewat : $omzetLewat + $rata * (12 - $bulanLewat);

        return [
            'rata_bulanan' => $rata,
            'setahun'      => $setahun,
            'bulan_lewat'  => $bulanLewat,
            'berjalan'     => $berjalan,
            'persen_batas' => self::BATAS_OMZET > 0 ? $setahun / self::BATAS_OMZET * 100 : 0,
        ];
    }

    // ─────────────────────────── Per faktur ───────────────────────────

    /**
     * Status pajak satu faktur untuk baris di halaman faktur: [status, status_label, pajak,
     * sumber_pajak, bayar, bayar_label, masa]. Null = faktur tak masuk laporan (draft/void).
     * Hitungan satu masa disimpan sebentar di cache, kuncinya sidik jari data yang memengaruhinya.
     */
    public function statusFaktur(int $invoiceId): ?array
    {
        $tgl = DB::table('sales_invoices')->where('id', $invoiceId)->whereNotIn('status', ['draft', 'void'])->value('invoice_date');
        if (!$tgl) {
            return null;
        }
        $masa = Carbon::parse($tgl)->startOfMonth();
        $peta = \Illuminate\Support\Facades\Cache::remember(
            'pph_final:faktur:' . $masa->format('Y-m') . ':' . $this->sidikJari(),
            600,
            fn () => $this->rincianMasa($masa->year, $masa->month)['faktur']
                ->mapWithKeys(fn ($r) => [$r->id => [
                    'status' => $r->status, 'status_label' => $r->status_label, 'pajak' => (float) $r->pajak_faktur,
                    'sumber_pajak' => $r->sumber_pajak, 'bayar' => $r->bayar, 'bayar_label' => $r->bayar_label,
                ]])->all()
        );

        return isset($peta[$invoiceId]) ? $peta[$invoiceId] + ['masa' => $masa] : null;
    }

    private function sidikJari(): string
    {
        $bagian = [];
        foreach (['sales_invoices', 'sales_returns', 'tax_payments', 'tax_omzet_adjustments', 'jubelio_order_links', 'marketplace_configs'] as $t) {
            $x = DB::table($t)->selectRaw('COUNT(*) n, MAX(updated_at) u')->first();
            $bagian[] = $x->n . '@' . $x->u;
        }

        return md5(implode('|', $bagian));
    }

    /**
     * Pesanan non-marketplace yang uang mukanya sudah diterima tapi belum difaktur. Dengan basis
     * tanggal faktur, omzetnya belum masuk masa mana pun — supaya tak terlupa saat lintas bulan.
     */
    public function uangMukaBelumDifaktur(): Collection
    {
        return DB::table('sales_orders as so')
            ->join('customers as c', 'c.id', '=', 'so.customer_id')
            ->join('sales_advances as sa', fn ($j) => $j->on('sa.sales_order_id', '=', 'so.id')->where('sa.status', 'posted'))
            ->where(fn ($q) => $q->whereNull('c.is_marketplace')->orWhere('c.is_marketplace', false))
            ->whereNotIn('so.status', ['void', 'cancelled', 'draft'])
            ->whereNotExists(fn ($q) => $q->from('sales_invoices as si')->whereColumn('si.sales_order_id', 'so.id')
                ->whereNotIn('si.status', ['draft', 'void']))
            ->groupBy('so.id', 'so.order_number', 'so.order_date', 'so.grand_total', 'c.name')
            ->orderBy('so.order_date')
            ->get(['so.id', 'so.order_number', 'so.order_date', 'so.grand_total', 'c.name as pelanggan',
                   DB::raw('SUM(sa.amount) as dp'), DB::raw('MIN(sa.advance_date) as dp_pertama'), DB::raw('MAX(sa.advance_date) as dp_terakhir')]);
    }

    // ─────────────────────────── Setoran lama ───────────────────────────

    /** Pengeluaran ke akun 5012 yang belum ditandai sebagai setoran masa mana pun. */
    public function pengeluaranBelumDitandai(int $tahun): Collection
    {
        $akun = DB::table('accounts')->where('code', self::AKUN_PPH_FINAL)->value('id');
        if (!$akun) {
            return collect();
        }

        return DB::table('cash_disbursements as cd')
            ->join('cash_disbursement_lines as l', 'l.cash_disbursement_id', '=', 'cd.id')
            ->where('cd.status', 'posted')
            ->where('l.account_id', $akun)
            ->whereBetween('cd.date', [Carbon::create($tahun, 1, 1)->toDateString(), Carbon::create($tahun + 1, 1, 31)->toDateString()])
            ->whereNotExists(fn ($q) => $q->from('tax_payments as tp')->whereColumn('tp.cash_disbursement_id', 'cd.id'))
            ->groupBy('cd.id', 'cd.number', 'cd.date', 'cd.payee', 'cd.notes')
            ->orderBy('cd.date')
            ->get(['cd.id', 'cd.number', 'cd.date', 'cd.payee', 'cd.notes', DB::raw('SUM(l.amount) as jumlah'),
                   DB::raw('GROUP_CONCAT(l.description SEPARATOR " · ") as uraian')]);
    }

    // ─────────────────────────── Konfigurasi ───────────────────────────

    /** @return Collection<int, MarketplaceConfig> keyBy customer_id */
    private function configs(): Collection
    {
        return $this->configs ??= MarketplaceConfig::where('is_active', true)->get()->keyBy('customer_id');
    }

    private function akunPajakIds(): array
    {
        $ids = $this->configs()->pluck('account_tax_id')->filter()->all();
        $ids[] = DB::table('accounts')->where('code', self::AKUN_PPH_FINAL)->value('id');

        return array_values(array_unique(array_filter($ids)));
    }

    /** Marketplace pemungut yang % pajaknya belum diisi → jurnal settlement tak memisah pajak. */
    public function peringatanKonfigurasi(): array
    {
        $pesan = [];
        $cfgs = $this->configs()->load('customer');
        foreach ($cfgs as $cfg) {
            if ($cfg->isPemungutPajak() && (float) $cfg->tax_percent <= 0) {
                $pesan[] = ($cfg->customer->name ?? 'Marketplace #' . $cfg->customer_id)
                    . ': tanggal mulai pungut sudah diisi tapi "Pajak (%)" masih 0 — jurnal settlement belum memisahkan pajak.';
            }
        }

        return $pesan;
    }
}
