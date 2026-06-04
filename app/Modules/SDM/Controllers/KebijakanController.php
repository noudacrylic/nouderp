<?php

namespace App\Modules\SDM\Controllers;

use App\Modules\SDM\Models\KebijakanKolom;
use App\Modules\SDM\Models\KebijakanRule;
use App\Modules\SDM\Models\KebijakanSummary;
use App\Modules\SDM\Models\KebijakanSummaryValue;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class KebijakanController extends Controller
{
    // Landing tab Kebijakan → Rule (pengaturan jam & tunjangan SP sudah di master karyawan)
    public function index()
    {
        return redirect()->route('sdm.kebijakan.rule.index');
    }

    // ============================================================
    // Tab Kolom Akibat (CRUD kolom dinamis di tabel absen)
    // ============================================================
    public function kolomIndex()
    {
        $kolom    = KebijakanKolom::ordered()->get();
        $summary  = KebijakanSummary::with('values')->ordered()->get();
        $karyawans = \App\Modules\SDM\Models\Karyawan::active()->orderBy('name')->get(['id', 'name', 'staf_code']);
        return view('erp.sdm.kebijakan.kolom', compact('kolom', 'summary', 'karyawans'));
    }

    public function kolomStore(Request $request)
    {
        $data = $request->validate([
            'key'    => ['required', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/', 'unique:sdm_kebijakan_kolom,key', 'unique:sdm_kebijakan_summary,key'],
            'label'  => 'required|string|max:100',
            'tipe'   => ['required', Rule::in(array_keys(KebijakanKolom::TIPE))],
            'urutan' => 'nullable|integer|min:0',
        ]);
        KebijakanKolom::create(array_merge($data, [
            'is_system' => false,
            'is_active' => true,
            'urutan'    => $data['urutan'] ?? 100,
        ]));
        return back()->with('success', 'Kolom ditambahkan.');
    }

    public function kolomUpdate(Request $request, int $id)
    {
        $kol = KebijakanKolom::findOrFail($id);
        $data = $request->validate([
            'label'     => 'required|string|max:100',
            'tipe'      => ['required', Rule::in(array_keys(KebijakanKolom::TIPE))],
            'urutan'    => 'required|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);
        if ($kol->is_system) {
            unset($data['tipe']); // tipe kolom system tidak boleh diubah
        }
        $kol->update(array_merge($data, [
            'is_active' => $request->boolean('is_active'),
        ]));
        return back()->with('success', 'Kolom diperbarui.');
    }

    public function kolomDestroy(int $id)
    {
        $kol = KebijakanKolom::findOrFail($id);
        if ($kol->is_system) {
            return back()->with('error', 'Kolom sistem tidak dapat dihapus.');
        }
        $this->cleanupRuleEffectsForKey($kol->key);
        $kol->delete();
        return back()->with('success', 'Kolom dihapus.');
    }

    // ============================================================
    // Tab Kolom Akibat — Baris Summary (CRUD baris di summary box)
    // ============================================================
    public function summaryStore(Request $request)
    {
        $data = $request->validate([
            'key'            => ['required', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/', 'unique:sdm_kebijakan_summary,key', 'unique:sdm_kebijakan_kolom,key'],
            'label'          => 'required|string|max:120',
            'urutan'         => 'nullable|integer|min:0',
            'mode'           => ['required', Rule::in(array_keys(KebijakanSummary::MODES))],
            'arah'           => ['required', Rule::in(array_keys(KebijakanSummary::ARAH))],
            'nominal_manual' => 'nullable|numeric|min:0',
            'scope'          => ['nullable', Rule::in(array_keys(KebijakanSummary::SCOPES))],
            'recurrence'     => ['nullable', Rule::in(array_keys(KebijakanSummary::RECURRENCES))],
            'values'         => 'nullable|array',
        ]);
        $sum = KebijakanSummary::create(array_merge($data, [
            'is_system'      => false,
            'is_active'      => $request->boolean('is_active', true),
            'urutan'         => $data['urutan'] ?? 100,
            'nominal_manual' => $data['nominal_manual'] ?? 0,
            'scope'          => $data['scope'] ?? 'all',
            'recurrence'     => $data['recurrence'] ?? 'monthly',
        ]));
        $this->syncSummaryValues($sum, (array) $request->input('values', []));
        return redirect()->route('sdm.kebijakan.kolom.index')->with('success', 'Baris summary ditambahkan.');
    }

    public function summaryUpdate(Request $request, int $id)
    {
        $sum  = KebijakanSummary::findOrFail($id);
        $data = $request->validate([
            'label'          => 'required|string|max:120',
            'urutan'         => 'required|integer|min:0',
            'mode'           => ['required', Rule::in(array_keys(KebijakanSummary::MODES))],
            'arah'           => ['required', Rule::in(array_keys(KebijakanSummary::ARAH))],
            'nominal_manual' => 'nullable|numeric|min:0',
            'scope'          => ['nullable', Rule::in(array_keys(KebijakanSummary::SCOPES))],
            'recurrence'     => ['nullable', Rule::in(array_keys(KebijakanSummary::RECURRENCES))],
            'is_active'      => 'nullable|boolean',
            'values'         => 'nullable|array',
        ]);
        if ($sum->is_system) {
            // Summary system: hanya label & urutan & is_active yang boleh diubah
            $sum->update([
                'label'     => $data['label'],
                'urutan'    => $data['urutan'],
                'is_active' => $request->boolean('is_active'),
            ]);
        } else {
            $sum->update(array_merge($data, [
                'is_active'      => $request->boolean('is_active'),
                'nominal_manual' => $data['nominal_manual'] ?? 0,
                'scope'          => $data['scope'] ?? 'all',
                'recurrence'     => $data['recurrence'] ?? 'monthly',
            ]));
            $this->syncSummaryValues($sum->fresh(), (array) $request->input('values', []));
        }
        return redirect()->route('sdm.kebijakan.kolom.index')->with('success', 'Baris summary diperbarui.');
    }

    /**
     * Sinkronisasi tabel sdm_kebijakan_summary_value berdasarkan shape:
     *   - all + monthly         → tidak ada row di value table, gunakan nominal_manual
     *   - all + one_time        → karyawan_id NULL, bulan/tahun set (bisa banyak bulan)
     *   - per_karyawan + monthly→ karyawan_id set, bulan/tahun NULL (bisa banyak karyawan)
     *   - per_karyawan + one_time→ TIDAK di-sync dari modal; di-isi inline di Summary Absensi
     */
    protected function syncSummaryValues(KebijakanSummary $sum, array $values): void
    {
        $scope = $sum->scope ?? 'all';
        $recur = $sum->recurrence ?? 'monthly';

        // Hapus row yang shape-nya tidak match dengan setting baru (cleanup data lama)
        if ($scope === 'all') {
            KebijakanSummaryValue::where('summary_id', $sum->id)->whereNotNull('karyawan_id')->delete();
        } else {
            KebijakanSummaryValue::where('summary_id', $sum->id)->whereNull('karyawan_id')->delete();
        }
        if ($recur === 'monthly') {
            KebijakanSummaryValue::where('summary_id', $sum->id)->whereNotNull('bulan')->delete();
        } else {
            KebijakanSummaryValue::where('summary_id', $sum->id)->whereNull('bulan')->delete();
        }

        // Mode auto / shape "all+monthly" / shape "per_karyawan+one_time" tidak pakai values dari modal
        if ($sum->mode === 'auto') return;
        if ($scope === 'all' && $recur === 'monthly') return;
        if ($scope === 'per_karyawan' && $recur === 'one_time') return;

        // Replace strategy: hapus semua sisa value yang masih match shape, lalu insert ulang dari modal
        KebijakanSummaryValue::where('summary_id', $sum->id)->delete();

        foreach ($values as $v) {
            $nominal = (float) ($v['nominal'] ?? 0);
            if ($nominal <= 0) continue;

            $karyawanId = $scope === 'per_karyawan' ? (int) ($v['karyawan_id'] ?? 0) : null;
            $bulan      = $recur === 'one_time' ? (int) ($v['bulan'] ?? 0) : null;
            $tahun      = $recur === 'one_time' ? (int) ($v['tahun'] ?? 0) : null;

            if ($scope === 'per_karyawan' && ! $karyawanId) continue;
            if ($recur === 'one_time' && (! $bulan || ! $tahun)) continue;

            KebijakanSummaryValue::create([
                'summary_id'  => $sum->id,
                'karyawan_id' => $karyawanId,
                'bulan'       => $bulan,
                'tahun'       => $tahun,
                'nominal'     => $nominal,
            ]);
        }
    }

    /**
     * Simpan nilai baris summary untuk konteks tertentu (karyawan/bulan/tahun).
     * Dipanggil dari Summary Absensi via AJAX/form inline.
     */
    public function summaryValueSave(Request $request, int $summaryId)
    {
        $sum  = KebijakanSummary::findOrFail($summaryId);

        if ($sum->mode === 'auto') {
            return back()->with('error', 'Baris auto tidak bisa diisi manual — diatur via Rule.');
        }
        if ($sum->scope === 'all' && $sum->recurrence === 'monthly') {
            return back()->with('error', 'Gunakan kolom Nominal Manual di Kebijakan untuk baris global.');
        }

        $data = $request->validate([
            'karyawan_id' => 'nullable|integer|exists:sdm_karyawan,id',
            'bulan'       => 'nullable|integer|min:1|max:12',
            'tahun'       => 'nullable|integer|min:2000|max:2100',
            'nominal'     => 'required|numeric|min:0',
        ]);

        $karyawanId = $sum->scope === 'per_karyawan' ? ($data['karyawan_id'] ?? null) : null;
        $bulan      = $sum->recurrence === 'one_time' ? ($data['bulan'] ?? null) : null;
        $tahun      = $sum->recurrence === 'one_time' ? ($data['tahun'] ?? null) : null;

        if ($sum->scope === 'per_karyawan' && ! $karyawanId) {
            return back()->with('error', 'Karyawan wajib dipilih untuk baris per-karyawan.');
        }
        if ($sum->recurrence === 'one_time' && (! $bulan || ! $tahun)) {
            return back()->with('error', 'Bulan & tahun wajib diisi untuk baris bulan-tertentu.');
        }

        // Upsert berdasarkan (summary_id, karyawan_id, bulan, tahun)
        $existing = KebijakanSummaryValue::where('summary_id', $sum->id)
            ->where(fn ($q) => $karyawanId ? $q->where('karyawan_id', $karyawanId) : $q->whereNull('karyawan_id'))
            ->where(fn ($q) => $bulan ? $q->where('bulan', $bulan) : $q->whereNull('bulan'))
            ->where(fn ($q) => $tahun ? $q->where('tahun', $tahun) : $q->whereNull('tahun'))
            ->first();

        if ((float) $data['nominal'] <= 0 && $existing) {
            $existing->delete();
            return back()->with('success', 'Nilai dihapus.');
        }

        if ($existing) {
            $existing->update(['nominal' => $data['nominal']]);
        } else {
            KebijakanSummaryValue::create([
                'summary_id'  => $sum->id,
                'karyawan_id' => $karyawanId,
                'bulan'       => $bulan,
                'tahun'       => $tahun,
                'nominal'     => $data['nominal'],
            ]);
        }

        return back()->with('success', "Nilai {$sum->label} disimpan.");
    }

    public function summaryDestroy(int $id)
    {
        $sum = KebijakanSummary::findOrFail($id);
        if ($sum->is_system) {
            return back()->with('error', 'Baris summary sistem tidak dapat dihapus.');
        }
        $this->cleanupRuleEffectsForKey($sum->key);
        $sum->delete();
        return back()->with('success', 'Baris summary dihapus.');
    }

    protected function cleanupRuleEffectsForKey(string $key): void
    {
        foreach (KebijakanRule::all() as $r) {
            $effects = collect($r->effects ?? [])->reject(fn ($e) => ($e['kolom'] ?? null) === $key)->values()->all();
            if (count($effects) !== count($r->effects ?? [])) {
                $r->update(['effects' => $effects]);
            }
        }
    }

    // ============================================================
    // Tab Rule (CRUD kebijakan if-then)
    // ============================================================
    public function ruleIndex()
    {
        $rules     = KebijakanRule::ordered()->get();
        $targets   = $this->ruleTargets();
        return view('erp.sdm.kebijakan.rule_index', compact('rules', 'targets'));
    }

    public function ruleCreate()
    {
        $targets = $this->ruleTargets();
        $rule    = new KebijakanRule(['priority' => 100, 'is_active' => true, 'conditions' => [], 'effects' => []]);
        return view('erp.sdm.kebijakan.rule_form', compact('rule', 'targets'));
    }

    public function ruleStore(Request $request)
    {
        $payload = $this->validateRule($request);
        KebijakanRule::create($payload);
        return redirect()->route('sdm.kebijakan.rule.index')->with('success', 'Rule disimpan.');
    }

    public function ruleEdit(int $id)
    {
        $rule    = KebijakanRule::findOrFail($id);
        $targets = $this->ruleTargets();
        return view('erp.sdm.kebijakan.rule_form', compact('rule', 'targets'));
    }

    /**
     * Daftar target effect: kolom + summary mode=auto, dikelompokkan.
     */
    protected function ruleTargets(): array
    {
        $kolom = KebijakanKolom::aktif()->ordered()->get()->map(fn ($k) => [
            'key' => $k->key, 'label' => $k->label, 'group' => 'Kolom Akibat', 'tipe' => $k->tipe,
        ]);
        $summary = KebijakanSummary::aktif()->where('mode', 'auto')->ordered()->get()->map(fn ($s) => [
            'key' => $s->key, 'label' => $s->label, 'group' => 'Baris Summary (auto)', 'tipe' => 'rupiah',
        ]);
        return $kolom->concat($summary)->all();
    }

    public function ruleUpdate(Request $request, int $id)
    {
        $rule    = KebijakanRule::findOrFail($id);
        $payload = $this->validateRule($request);
        $rule->update($payload);
        return redirect()->route('sdm.kebijakan.rule.index')->with('success', 'Rule diperbarui.');
    }

    public function ruleDestroy(int $id)
    {
        KebijakanRule::findOrFail($id)->delete();
        return back()->with('success', 'Rule dihapus.');
    }

    protected function validateRule(Request $request): array
    {
        $data = $request->validate([
            'nama'        => 'required|string|max:200',
            'deskripsi'   => 'nullable|string',
            'priority'    => 'required|integer|min:0',
            'is_active'   => 'nullable|boolean',
            'conditions'  => 'array',
            'conditions.*.field'    => ['required', Rule::in(array_keys(KebijakanRule::FIELDS))],
            'conditions.*.op'       => ['required', Rule::in(array_keys(KebijakanRule::OPERATORS))],
            'conditions.*.value'    => 'nullable',
            'effects'     => 'array',
            'effects.*.kolom' => ['required', 'string', function ($attr, $value, $fail) {
                $existsKolom   = \App\Modules\SDM\Models\KebijakanKolom::where('key', $value)->exists();
                $existsSummary = \App\Modules\SDM\Models\KebijakanSummary::where('key', $value)->exists();
                if (! $existsKolom && ! $existsSummary) {
                    $fail("Target {$value} tidak ditemukan di Kolom maupun Baris Summary.");
                }
            }],
            'effects.*.kind'  => ['required', Rule::in(array_keys(KebijakanRule::EFFECT_KINDS))],
            'effects.*.value' => 'nullable',
        ]);

        // Normalisasi 'in' value: string "a,b,c" → array
        $conditions = collect($data['conditions'] ?? [])->map(function ($c) {
            if (($c['op'] ?? null) === 'in' && isset($c['value']) && is_string($c['value'])) {
                $c['value'] = array_values(array_filter(array_map('trim', explode(',', $c['value']))));
            }
            return $c;
        })->values()->all();

        return [
            'nama'       => $data['nama'],
            'deskripsi'  => $data['deskripsi'] ?? null,
            'priority'   => $data['priority'],
            'is_active'  => $request->boolean('is_active'),
            'conditions' => $conditions,
            'effects'    => $data['effects'] ?? [],
        ];
    }
}
