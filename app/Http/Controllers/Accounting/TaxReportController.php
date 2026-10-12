<?php

namespace App\Http\Controllers\Accounting;

use App\Core\Accounting\Account;
use App\Http\Controllers\Controller;
use App\Models\TaxPayment;
use App\Modules\Finance\Models\CashDisbursement;
use App\Modules\Finance\Services\CashDisbursementService;
use App\Modules\Finance\Services\PphFinalHutangService;
use App\Modules\Finance\Services\PphFinalReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Laporan Pajak — PPh Final UMKM 0,5%: rekap setahun, rincian masa, dan setoran. */
class TaxReportController extends Controller
{
    public function __construct(private PphFinalReportService $report, private PphFinalHutangService $hutang) {}

    public function index(Request $request)
    {
        $tahun = (int) ($request->input('tahun') ?: now()->year);
        $rekap = $this->report->rekapTahun($tahun);

        // Hutang (akun 2114) per masa: yang semestinya dibukukan vs yang sudah.
        $dibukukan = $this->hutang->dibukukanPerMasa($tahun);
        $langsung  = $this->hutang->langsungPerMasa($tahun);
        foreach ($rekap['bulan'] as $m => &$b) {
            $b['hutang_dibukukan'] = (float) ($dibukukan[$m] ?? 0);
            $b['hutang_selisih'] = round(max(0.0, $b['harus_setor'] - ($langsung[$m] ?? 0)) - $b['hutang_dibukukan'], 0);
            $b['belum_setor'] = max(0.0, round($b['harus_setor'] - $b['sudah_setor'], 0));
        }
        unset($b);

        return view('erp.accounting.reports.pajak.index', [
            'tahun'      => $tahun,
            'rekap'      => $rekap,
            'peringatan' => $this->report->peringatanKonfigurasi(),
            'saldoHutang' => $this->hutang->saldoHutang(),
            'dpBelumFaktur' => $this->report->uangMukaBelumDifaktur(),
            'setoranLama' => $this->report->pengeluaranBelumDitandai($tahun),
            'setoran'    => TaxPayment::pphFinal()->whereYear('period', $tahun)->with('cashDisbursement')
                                ->orderBy('period')->orderBy('paid_at')->get(),
        ]);
    }

    public function masa(Request $request, int $tahun, int $bulan)
    {
        abort_unless($bulan >= 1 && $bulan <= 12, 404);
        $data = $this->report->rincianMasa($tahun, $bulan);

        // Daftar faktur bisa ribuan → filter status/kanal + paginasi di atas koleksi.
        $faktur = $data['faktur']
            ->when($request->filled('status'), fn ($c) => $c->where('status', $request->input('status')))
            ->when($request->filled('kanal'), fn ($c) => $c->where('kanal', $request->input('kanal')))
            ->when($request->filled('bayar'), fn ($c) => $c->whereIn('bayar', $request->input('bayar') === 'belum'
                ? ['belum', 'sebagian'] : [$request->input('bayar')]))
            ->when($request->filled('q'), fn ($c) => $c->filter(fn ($r) =>
                str_contains(mb_strtolower($r->nomor . ' ' . $r->pelanggan), mb_strtolower($request->input('q')))))
            ->values();
        $perPage = per_page_size();
        $page = max(1, (int) $request->input('page', 1));
        $data['fakturPage'] = (new \Illuminate\Pagination\LengthAwarePaginator(
            $faktur->forPage($page, $perPage)->values(), $faktur->count(), $perPage, $page,
            ['path' => $request->url(), 'query' => $request->query()]
        ));
        $data['fakturFilterTotal'] = (float) $faktur->sum('omzet');
        $data['fakturFilterPajak'] = (float) $faktur->sum('pajak_faktur');
        $masa = Carbon::create($tahun, $bulan, 1);
        $data['hutang'] = $this->hutang->status($masa, $data['ringkas']['harus_setor']);
        $data['hutangJurnal'] = $this->hutang->jurnal($masa);

        return view('erp.accounting.reports.pajak.masa', $data + [
            'tahun'   => $tahun,
            'bulan'   => $bulan,
            'setoran' => TaxPayment::pphFinal()->whereDate('period', Carbon::create($tahun, $bulan, 1))
                            ->with('cashDisbursement')->orderBy('paid_at')->get(),
            'omzetLuar' => \App\Models\TaxOmzetAdjustment::pphFinal()->whereDate('period', Carbon::create($tahun, $bulan, 1))->first(),
            'cashAccounts' => Account::whereIn('account_category', ['cash', 'cash_equivalent'])
                            ->where('is_active', 1)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function excel(int $tahun, int $bulan)
    {
        abort_unless($bulan >= 1 && $bulan <= 12, 404);
        $d = $this->report->rincianMasa($tahun, $bulan);
        $r = $d['ringkas'];

        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $ss->getActiveSheet()->setTitle('Ringkasan');
        $sheet->fromArray([
            ['Laporan PPh Final UMKM 0,5%', 'Masa ' . $r['masa']->translatedFormat('F Y')],
            [],
            ['Omzet bruto', $r['omzet_bruto']],
            ['Omzet di luar ERP', $r['omzet_luar']],
            ['Retur', -$r['retur']],
            ['Omzet neto', $r['omzet_neto']],
            ['Omzet dipungut marketplace (termasuk menunggu selesai)', $r['omzet_dipungut']],
            ['Omzet setor sendiri', $r['omzet_sendiri']],
            ['Bebas pajak (jatah Rp500 jt)', -$r['omzet_bebas']],
            ['Dasar setor sendiri', $r['dasar_setor']],
            ['PPh Final harus disetor (0,5%)', $r['harus_setor']],
            ['Sudah disetor', $r['sudah_setor']],
            ['Selisih (+ lebih / − kurang)', $r['selisih']],
            ['Pajak dipungut marketplace', $r['pajak_dipungut']],
            ['Pajak akan dipungut (menunggu selesai)', $r['pajak_menunggu']],
            ['Jatuh tempo setor', $r['jatuh_tempo']->format('d/m/Y')],
        ]);
        $sheet->getColumnDimension('A')->setWidth(52);
        $sheet->getColumnDimension('B')->setWidth(20);

        $f = $ss->createSheet()->setTitle('Faktur');
        $f->fromArray(['Tanggal', 'No. Faktur', 'Pelanggan', 'Kanal', 'Omzet', 'Status', 'Tgl pesanan', 'Selesai di marketplace',
            'Pajak dipungut', 'Sumber angka pajak', 'Bebas (jatah Rp500 jt)', 'Pajak setor sendiri', 'Status bayar'], null, 'A1');
        $row = 2;
        foreach ($d['faktur'] as $x) {
            $f->fromArray([$x->tanggal, $x->nomor, $x->pelanggan, $x->kanal_label, $x->omzet, $x->status_label,
                $x->pesanan_at, $x->selesai_at, $x->pajak ?: null, $x->sumber_pajak,
                $x->bebas ?: null, $x->pajak_setor ?: null, $x->bayar_label], null, 'A' . $row++);
        }

        $rt = $ss->createSheet()->setTitle('Retur');
        $rt->fromArray(['Tanggal', 'No. Retur', 'Faktur', 'Kanal', 'Nilai dibalik', 'Status faktur'], null, 'A1');
        $row = 2;
        foreach ($d['retur'] as $x) {
            $rt->fromArray([$x->tanggal, $x->nomor, $x->faktur_nomor, $x->kanal_label, $x->nilai, \App\Modules\Finance\Services\PphFinalReportService::STATUS_LABEL[$x->status]], null, 'A' . $row++);
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
        $nama = sprintf('pph-final-%04d-%02d.xlsx', $tahun, $bulan);

        return response()->streamDownload(fn () => $writer->save('php://output'), $nama, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** Catat setoran baru → Pengeluaran (Dr 2114 Hutang PPh Final / Cr kas-bank) + baris setoran masa. */
    public function storeSetoran(Request $request, CashDisbursementService $cdSvc)
    {
        $data = $request->validate([
            'period'          => 'required|date_format:Y-m',
            'paid_at'         => 'required|date',
            'amount'          => 'required',
            'ntpn'            => 'nullable|string|max:40',
            'billing_code'    => 'nullable|string|max:40',
            'tanpa_jurnal'    => 'nullable|boolean',
            'cash_account_id' => 'required_unless:tanpa_jurnal,1|nullable|exists:accounts,id',
            'notes'           => 'nullable|string|max:500',
        ]);
        $tanpaJurnal = (bool) ($data['tanpa_jurnal'] ?? false);
        $amount = (float) clean_number($data['amount']);
        if ($amount <= 0) {
            return back()->withInput()->with('error', 'Nominal setoran harus lebih dari 0.');
        }

        $akun = Account::where('code', PphFinalHutangService::AKUN_HUTANG)->first();
        if (!$akun) {
            return back()->withInput()->with('error', 'Akun 2114 Hutang PPh Final UMKM belum ada di Chart of Accounts.');
        }

        $masa = Carbon::createFromFormat('Y-m', $data['period'])->startOfMonth();
        $label = 'Setoran PPh Final UMKM 0,5% masa ' . $masa->translatedFormat('F Y');

        try {
            DB::transaction(function () use ($data, $amount, $akun, $masa, $label, $cdSvc, $tanpaJurnal) {
                if (!$tanpaJurnal) {
                    // Hutang masa ini dibukukan dulu (Dr 5012 / Cr 2114), setorannya melunasi hutang.
                    $this->hutang->bukukan($masa);
                }

                // Setoran lama yang sudah dibukukan di luar ERP: cukup dicatat, tanpa Pengeluaran.
                $cd = $tanpaJurnal ? null : $cdSvc->createDraft([
                    'type'            => 'general',
                    'date'            => $data['paid_at'],
                    'cash_account_id' => $data['cash_account_id'],
                    'payee'           => 'Kas Negara (DJP)',
                    'reference'       => !empty($data['ntpn']) ? 'NTPN ' . $data['ntpn'] : null,
                    'notes'           => trim($label . (!empty($data['notes']) ? ' — ' . $data['notes'] : '')),
                    'lines'           => [[
                        'account_id'  => $akun->id,
                        'amount'      => $amount,
                        'description' => $label,
                    ]],
                ]);
                if ($cd) {
                    $cdSvc->post($cd);
                }

                TaxPayment::create([
                    'tax_type'             => TaxPayment::PPH_FINAL_UMKM,
                    'period'               => $masa->toDateString(),
                    'paid_at'              => $data['paid_at'],
                    'amount'               => $amount,
                    'ntpn'                 => ($data['ntpn'] ?? null) ?: null,
                    'billing_code'         => ($data['billing_code'] ?? null) ?: null,
                    'cash_disbursement_id' => $cd?->id,
                    'via_hutang'           => $cd !== null,
                    'notes'                => ($data['notes'] ?? null) ?: null,
                    'created_by'           => auth()->id(),
                ]);
            });
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', 'Gagal mencatat setoran: ' . $e->getMessage());
        }

        return back()->with('success', 'Setoran ' . rupiah($amount) . ' untuk masa ' . $masa->translatedFormat('F Y') . ' tercatat.');
    }

    /** Omzet masa ini yang tidak tercatat sebagai faktur ERP. Nominal 0 = hapus. */
    public function storeOmzetLuar(Request $request)
    {
        $data = $request->validate([
            'period' => 'required|date_format:Y-m',
            'amount' => 'nullable',
            'notes'  => 'nullable|string|max:500',
        ]);
        $masa = Carbon::createFromFormat('Y-m', $data['period'])->startOfMonth();
        $amount = (float) clean_number($data['amount'] ?? 0);

        $kunci = ['tax_type' => TaxPayment::PPH_FINAL_UMKM, 'period' => $masa->toDateString()];
        if ($amount == 0.0) {
            \App\Models\TaxOmzetAdjustment::where($kunci)->delete();

            return back()->with('success', 'Omzet di luar ERP masa ' . $masa->translatedFormat('F Y') . ' dihapus.');
        }

        \App\Models\TaxOmzetAdjustment::updateOrCreate($kunci, [
            'amount'     => $amount,
            'notes'      => ($data['notes'] ?? null) ?: null,
            'created_by' => auth()->id(),
        ]);

        return back()->with('success', 'Omzet di luar ERP masa ' . $masa->translatedFormat('F Y') . ' disimpan: ' . rupiah($amount) . '.');
    }

    /** Tandai Pengeluaran 5012 yang sudah ada (dicatat manual dulu) sebagai setoran masa tertentu. */
    public function tandaiSetoran(Request $request)
    {
        $data = $request->validate([
            'cash_disbursement_id' => 'required|exists:cash_disbursements,id|unique:tax_payments,cash_disbursement_id',
            'period'               => 'required|date_format:Y-m',
            'ntpn'                 => 'nullable|string|max:40',
        ]);

        $cd = CashDisbursement::findOrFail($data['cash_disbursement_id']);
        $akunId = Account::where('code', PphFinalReportService::AKUN_PPH_FINAL)->value('id');
        $jumlah = (float) $cd->lines()->where('account_id', $akunId)->sum('amount');
        if ($cd->status !== 'posted' || $jumlah <= 0) {
            return back()->with('error', 'Pengeluaran ' . $cd->number . ' bukan pengeluaran terposting ke akun 5012.');
        }

        $masa = Carbon::createFromFormat('Y-m', $data['period'])->startOfMonth();
        TaxPayment::create([
            'tax_type'             => TaxPayment::PPH_FINAL_UMKM,
            'period'               => $masa->toDateString(),
            'paid_at'              => $cd->date,
            'amount'               => $jumlah,
            'ntpn'                 => ($data['ntpn'] ?? null) ?: null,
            'cash_disbursement_id' => $cd->id,
            'created_by'           => auth()->id(),
        ]);
        $this->sesuaikanHutang($masa);

        return back()->with('success', $cd->number . ' ditandai sebagai setoran masa ' . $masa->translatedFormat('F Y') . '.');
    }

    /** Lepas tanda setoran. Pengeluarannya TIDAK di-void — lakukan di Kas Bank bila perlu. */
    public function destroySetoran(TaxPayment $payment)
    {
        $nomor = $payment->cashDisbursement?->number;
        $masa = $payment->period->copy();
        $payment->delete();
        if (!$payment->via_hutang) {
            $this->sesuaikanHutang($masa);
        }

        return back()->with('success', 'Tanda setoran dilepas.' . ($nomor ? " Pengeluaran $nomor tetap ada; void di Kas Bank bila memang salah catat." : ''));
    }

    /** Bukukan / perbarui hutang PPh Final (akun 2114) satu masa. */
    public function bukukanHutang(int $tahun, int $bulan)
    {
        abort_unless($bulan >= 1 && $bulan <= 12, 404);
        $masa = Carbon::create($tahun, $bulan, 1);

        try {
            $nilai = $this->hutang->bukukan($masa);
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal membukukan hutang pajak: ' . $e->getMessage());
        }

        $label = $masa->translatedFormat('F Y');

        return back()->with('success', match (true) {
            $nilai > 0 => 'Hutang PPh Final masa ' . $label . ' bertambah ' . rupiah($nilai) . ' (Dr 5012 / Cr 2114).',
            $nilai < 0 => 'Hutang PPh Final masa ' . $label . ' berkurang ' . rupiah(-$nilai) . '.',
            default    => 'Hutang PPh Final masa ' . $label . ' sudah sesuai.',
        });
    }

    /** Setoran lama (langsung ke 5012) berubah -> hutang masa yang sudah pernah dibukukan ikut disesuaikan. */
    private function sesuaikanHutang(Carbon $masa): void
    {
        if (\App\Models\TaxAccrual::pphFinal()->whereDate('period', $masa->copy()->startOfMonth())->exists()) {
            try {
                $this->hutang->bukukan($masa);
            } catch (\Throwable $e) {
                session()->flash('error', 'Hutang pajak belum disesuaikan: ' . $e->getMessage());
            }
        }
    }
}
