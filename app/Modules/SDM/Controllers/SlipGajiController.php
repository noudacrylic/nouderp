<?php

namespace App\Modules\SDM\Controllers;

use App\Modules\SDM\Models\Attendance;
use App\Modules\SDM\Models\Kasbon;
use App\Modules\SDM\Models\SlipGaji;
use App\Modules\SDM\Models\SlipGajiKomponen;
use App\Modules\SDM\Services\PayrollBreakdownService;
use App\Modules\SDM\Services\PayrollCalculationService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SlipGajiController extends Controller
{
    use \App\Http\Controllers\Concerns\InlinesPrintAssets;

    public function show(int $id, PayrollBreakdownService $breakdown)
    {
        $slip = SlipGaji::with(['karyawan.department', 'periode', 'komponen'])->findOrFail($id);
        $attendances = Attendance::where('periode_id', $slip->periode_id)
            ->where('karyawan_id', $slip->karyawan_id)
            ->orderBy('tanggal')
            ->get();
        $kasbonAktif = Kasbon::where('karyawan_id', $slip->karyawan_id)
            ->where('status', 'posted')
            ->where('sisa_terhutang', '>', 0)
            ->orderBy('tanggal_pengajuan')
            ->get();

        $karyawan = $slip->karyawan;
        $bulan    = $slip->periode->bulan;
        $tahun    = $slip->periode->tahun;
        $data     = $breakdown->build($karyawan, $bulan, $tahun);

        return view('erp.sdm.slip-gaji.show', array_merge([
            'slip'        => $slip,
            'attendances' => $attendances,
            'kasbonAktif' => $kasbonAktif,
            'karyawan'    => $karyawan,
            'bulan'       => $bulan,
            'tahun'       => $tahun,
        ], $data));
    }

    public function edit(int $id)
    {
        $slip = SlipGaji::with(['karyawan', 'periode', 'komponen'])->findOrFail($id);
        if ($slip->isFinalized()) {
            return redirect()->route('sdm.slip-gaji.show', $id)
                ->with('error', 'Slip sudah finalized, tidak bisa diedit.');
        }
        $kasbonAktif = Kasbon::where('karyawan_id', $slip->karyawan_id)
            ->where('status', 'posted')
            ->where('sisa_terhutang', '>', 0)
            ->orderBy('tanggal_pengajuan')
            ->get();
        return view('erp.sdm.slip-gaji.edit', compact('slip', 'kasbonAktif'));
    }

    public function update(Request $request, int $id, PayrollCalculationService $payroll)
    {
        $slip = SlipGaji::findOrFail($id);
        if ($slip->isFinalized()) {
            return back()->with('error', 'Slip sudah finalized.');
        }

        $request->validate([
            'hari_kerja_periode'    => 'nullable|integer|min:1|max:31',
            'bonus'                 => 'nullable|string',
            'thr_amount'            => 'nullable|string',
            'cuti_unused_amount'    => 'nullable|string',
            'bpjs_kesehatan_amount' => 'nullable|string',
            'bpjs_tk_amount'        => 'nullable|string',
            'pph21_amount'          => 'nullable|string',
            'kasbon_payment'        => 'nullable|string',
            'notes'                 => 'nullable|string',
            'komponen'              => 'nullable|array',
            'komponen.*.id'         => 'nullable|integer',
            'komponen.*.label'      => 'nullable|string|max:100',
            'komponen.*.metode'     => 'nullable|in:nominal,percentage',
            'komponen.*.nilai'      => 'nullable|string',
            'komponen.*.basis'      => 'nullable|in:gaji_pokok,gaji_pokok_dibayar,subtotal',
            'komponen.*.notes'      => 'nullable|string',
        ]);

        $slip->fill([
            'hari_kerja_periode'    => (int) ($request->hari_kerja_periode ?? $slip->hari_kerja_periode ?? 25),
            'bonus'                 => (float) clean_number($request->bonus ?? 0),
            'thr_amount'            => (float) clean_number($request->thr_amount ?? 0),
            'cuti_unused_amount'    => (float) clean_number($request->cuti_unused_amount ?? 0),
            'bpjs_kesehatan_amount' => (float) clean_number($request->bpjs_kesehatan_amount ?? 0),
            'bpjs_tk_amount'        => (float) clean_number($request->bpjs_tk_amount ?? 0),
            'pph21_amount'          => (float) clean_number($request->pph21_amount ?? 0),
            'kasbon_payment'        => (float) clean_number($request->kasbon_payment ?? 0),
            'notes'                 => $request->notes,
        ]);
        $slip->save();

        $this->syncKomponen($slip, (array) $request->input('komponen', []));

        $payroll->regenerateSlip($slip);

        return redirect()->route('sdm.slip-gaji.show', $id)->with('success', 'Slip diperbarui.');
    }

    public function print(int $id, Request $request)
    {
        $slip = SlipGaji::with(['karyawan.department', 'periode', 'komponen'])->findOrFail($id);
        $attendances = Attendance::where('periode_id', $slip->periode_id)
            ->where('karyawan_id', $slip->karyawan_id)
            ->orderBy('tanggal')
            ->get();

        if ($request->boolean('pdf')) {
            $html = view('erp.sdm.slip-gaji.print', [
                'slip'        => $slip,
                'attendances' => $attendances,
                'pdfMode'     => true,
            ])->render();
            $html = $this->inlineLocalAssets($html);

            $bs = \Spatie\Browsershot\Browsershot::html($html)
                ->showBackground()
                ->format('A4')
                ->margins(8, 8, 8, 8)
                ->emulateMedia('print')
                ->timeout(120);

            if ($chromePath = env('BROWSERSHOT_CHROME_PATH')) {
                $bs->setChromePath($chromePath);
            }

            $filename = 'slip-' . ($slip->karyawan->staf_code ?? $slip->id) . '-' . ($slip->periode->code ?? $slip->periode_id);

            return response($bs->pdf(), 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $filename . '.pdf"',
            ]);
        }

        return view('erp.sdm.slip-gaji.print', compact('slip', 'attendances'));
    }

    public function regenerate(int $id, PayrollCalculationService $payroll)
    {
        $slip = SlipGaji::findOrFail($id);
        $payroll->regenerateSlip($slip);
        return back()->with('success', 'Slip dihitung ulang.');
    }

    protected function syncKomponen(SlipGaji $slip, array $rows): void
    {
        $existing = $slip->komponen()->get()->keyBy('id');
        $seenIds = [];

        foreach (array_values($rows) as $idx => $row) {
            $label = trim((string) ($row['label'] ?? ''));
            $nilaiRaw = $row['nilai'] ?? '';
            $nilai = $nilaiRaw === '' ? 0 : (float) clean_number($nilaiRaw);

            if ($label === '' || $nilai == 0) {
                continue;
            }

            $payload = [
                'slip_gaji_id' => $slip->id,
                'urutan'       => $idx,
                'label'        => $label,
                'metode'       => $row['metode'] ?? 'nominal',
                'nilai'        => $nilai,
                'basis'        => $row['metode'] === 'percentage' ? ($row['basis'] ?? 'gaji_pokok') : null,
                'notes'        => $row['notes'] ?? null,
            ];

            $rowId = isset($row['id']) ? (int) $row['id'] : 0;
            if ($rowId > 0 && $existing->has($rowId)) {
                $existing[$rowId]->update($payload);
                $seenIds[] = $rowId;
            } else {
                $new = SlipGajiKomponen::create($payload);
                $seenIds[] = $new->id;
            }
        }

        $slip->komponen()->whereNotIn('id', $seenIds)->delete();
    }
}
