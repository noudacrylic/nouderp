<?php

namespace App\Modules\SDM\Controllers;

use App\Modules\SDM\Models\NationalHoliday;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class NationalHolidayController extends Controller
{
    public function index(Request $request)
    {
        $year = $request->integer('year') ?: now()->year;

        $records = NationalHoliday::whereYear('tanggal', $year)
            ->orderBy('tanggal')
            ->get();

        $years = NationalHoliday::query()
            ->selectRaw('DISTINCT YEAR(tanggal) as y')
            ->orderByDesc('y')
            ->pluck('y')
            ->push($year)
            ->push(now()->year)
            ->unique()
            ->sortDesc()
            ->values();

        return view('erp.sdm.libur.index', compact('records', 'year', 'years'));
    }

    public function create()
    {
        return view('erp.sdm.libur.create');
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $data['created_by'] = auth()->id();
        $data['is_cuti_bersama'] = $request->boolean('is_cuti_bersama');

        NationalHoliday::create($data);
        return redirect()->route('sdm.libur.index')->with('success', 'Hari libur ditambahkan.');
    }

    public function edit(int $id)
    {
        $libur = NationalHoliday::findOrFail($id);
        return view('erp.sdm.libur.edit', compact('libur'));
    }

    public function update(Request $request, int $id)
    {
        $libur = NationalHoliday::findOrFail($id);
        $data = $this->validateData($request, $libur->id);
        $data['is_cuti_bersama'] = $request->boolean('is_cuti_bersama');
        $libur->update($data);

        return redirect()->route('sdm.libur.index')->with('success', 'Hari libur diperbarui.');
    }

    public function destroy(int $id)
    {
        NationalHoliday::findOrFail($id)->delete();
        return back()->with('success', 'Hari libur dihapus.');
    }

    protected function validateData(Request $request, ?int $id = null): array
    {
        $unique = $id ? ",{$id}" : '';
        return $request->validate([
            'tanggal' => 'required|date|unique:sdm_national_holidays,tanggal' . $unique,
            'nama'    => 'required|string|max:200',
            'catatan' => 'nullable|string',
        ]);
    }
}
