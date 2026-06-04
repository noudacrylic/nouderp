{{--
    Summary box komponen gaji bulanan.
    Dipakai di Daftar Absensi dashboard & Slip Gaji show.

    Var yang dipakai:
      $karyawan, $bulan, $tahun
      $kolomDinamis, $totalKolom
      $totalGajiHari, $totalLembur, $totalLemburJam
      $summaryRows, $summaryValue, $summaryShow
      $taxBpjs, $payrollSetting, $brutoSebelumPotongan, $totalDibayarkan
      $editable (bool, default true) - boleh inline-edit baris per-karyawan/one-time?
--}}
@php
    $editableInline = $editable ?? true;
@endphp
<table class="w-full text-sm">
    <tbody>
        <tr class="border-b">
            <td class="px-4 py-2 text-gray-600 w-1/2">Gaji Pokok</td>
            <td class="px-4 py-2 text-right font-mono">{{ rupiah($totalGajiHari) }}</td>
        </tr>
        <tr class="border-b">
            <td class="px-4 py-2 text-gray-600">
                Lembur
                @if($totalLemburJam > 0)
                    <span class="text-xs text-gray-400">@ {{ rtrim(rtrim(number_format($totalLemburJam, 1, ',', '.'), '0'), ',') }} Jam</span>
                @endif
            </td>
            <td class="px-4 py-2 text-right font-mono">{{ rupiah($totalLembur) }}</td>
        </tr>

        @foreach($kolomDinamis as $kol)
            @continue($kol->tipe === 'flag')
            <tr class="border-b">
                <td class="px-4 py-2 text-gray-600">{{ $kol->label }}</td>
                <td class="px-4 py-2 text-right font-mono">{{ rupiah($totalKolom[$kol->key] ?? 0) }}</td>
            </tr>
        @endforeach

        @foreach($summaryRows as $s)
            @continue($s->key === \App\Modules\SDM\Models\KebijakanSummary::KEY_TOTAL)
            @continue(! ($summaryShow[$s->key] ?? true))
            @php
                $val    = (float) ($summaryValue[$s->key] ?? 0);
                $scope  = $s->scope ?? 'all';
                $recur  = $s->recurrence ?? 'monthly';
                $canEdit = $editableInline && $s->mode === 'manual' && ($scope === 'per_karyawan' || $recur === 'one_time');
            @endphp
            <tr class="border-b">
                <td class="px-4 py-2 text-gray-600">
                    {{ $s->label }}
                    @if($s->mode === 'auto')
                        <span class="text-[10px] text-blue-500">[auto]</span>
                    @elseif($canEdit)
                        <span class="text-[10px] text-emerald-600">[{{ $scope === 'per_karyawan' ? 'per-org' : '' }}{{ $scope === 'per_karyawan' && $recur === 'one_time' ? ' · ' : '' }}{{ $recur === 'one_time' ? 'bln ' . $bulan . '/' . $tahun : '' }}]</span>
                    @endif
                </td>
                <td class="px-4 py-2 text-right font-mono">
                    @if($canEdit)
                        <form method="POST" action="{{ route('sdm.kebijakan.summary.value.save', $s->id) }}" class="inline-flex items-center gap-1 justify-end summary-val-form">
                            @csrf
                            <input type="hidden" name="karyawan_id" value="{{ $karyawan->id }}">
                            <input type="hidden" name="bulan" value="{{ $bulan }}">
                            <input type="hidden" name="tahun" value="{{ $tahun }}">
                            @if($s->arah === 'minus' && $val > 0)<span class="text-red-500">−</span>@endif
                            <input type="number" step="1" min="0" name="nominal" value="{{ (int) $val }}"
                                   data-original="{{ (int) $val }}"
                                   class="border rounded px-2 py-1 w-32 text-right font-mono text-sm bg-emerald-50 focus:bg-white focus:border-emerald-400">
                        </form>
                    @else
                        {{ $s->arah === 'minus' && $val > 0 ? '−' : '' }}{{ rupiah($val) }}
                    @endif
                </td>
            </tr>
        @endforeach

        @php
            $availableOneTime = $summaryRows->filter(function ($s) use ($summaryShow) {
                return $s->key !== \App\Modules\SDM\Models\KebijakanSummary::KEY_TOTAL
                    && $s->mode === 'manual'
                    && ($s->recurrence ?? 'monthly') === 'one_time'
                    && ! ($summaryShow[$s->key] ?? true);
            });
        @endphp
        @if($editableInline && $availableOneTime->count())
            <tr class="border-b bg-emerald-50/50">
                <td class="px-4 py-2 text-[11px] uppercase tracking-wider text-emerald-700 font-semibold" colspan="2">
                    Komponen Bulan-Tertentu (isi nominal lalu Enter / klik ikon save untuk mengaktifkan di {{ \Carbon\Carbon::create($tahun, $bulan, 1)->translatedFormat('F Y') }})
                </td>
            </tr>
            @foreach($availableOneTime as $s)
                <tr class="border-b">
                    <td class="px-4 py-2 text-gray-500 italic">{{ $s->label }} <span class="text-[10px] text-gray-400">(belum di-set untuk bulan ini)</span></td>
                    <td class="px-4 py-2 text-right">
                        <form method="POST" action="{{ route('sdm.kebijakan.summary.value.save', $s->id) }}" class="inline-flex items-center gap-1 justify-end">
                            @csrf
                            @if(($s->scope ?? 'all') === 'per_karyawan')
                                <input type="hidden" name="karyawan_id" value="{{ $karyawan->id }}">
                            @endif
                            <input type="hidden" name="bulan" value="{{ $bulan }}">
                            <input type="hidden" name="tahun" value="{{ $tahun }}">
                            <input type="number" step="1" min="0" name="nominal" placeholder="Nominal" required
                                   class="border rounded px-2 py-1 w-32 text-right font-mono text-sm">
                            <button type="submit" title="Simpan (Enter)" class="text-emerald-600 hover:text-emerald-800 hover:bg-emerald-100 rounded p-1.5 inline-flex items-center justify-center">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            </button>
                        </form>
                    </td>
                </tr>
            @endforeach
        @endif

        @if($taxBpjs)
            @php
                $showBpjsKes = $karyawan->ikut_bpjs_kesehatan;
                $showBpjsTk  = $karyawan->ikut_bpjs_tk;
                $showPph21   = $payrollSetting->pph21_enabled && $karyawan->ptkp_category && $karyawan->ptkp_category !== 'NONE';
                $showSection = $showBpjsKes || $showBpjsTk || $showPph21;
            @endphp

            @if($showSection)
                <tr class="border-b bg-gray-50">
                    <td class="px-4 py-2 text-gray-700 font-semibold">Subtotal Bruto</td>
                    <td class="px-4 py-2 text-right font-mono font-semibold">{{ rupiah($brutoSebelumPotongan) }}</td>
                </tr>

                <tr class="border-b bg-amber-50">
                    <td class="px-4 py-1.5 text-[11px] uppercase tracking-wider text-amber-700 font-semibold" colspan="2">
                        Potongan BPJS &amp; Pajak (otomatis dari Pengaturan Akun &amp; Rate)
                    </td>
                </tr>

                @if($showBpjsKes)
                    <tr class="border-b">
                        <td class="px-4 py-2 text-gray-600">
                            BPJS Kesehatan
                            <span class="text-[10px] text-blue-500">[auto]</span>
                            <span class="text-[11px] text-gray-400">{{ number_format($payrollSetting->bpjs_kesehatan_employee_rate, 2, ',', '.') }}% × maks {{ rupiah($payrollSetting->bpjs_kesehatan_max_base) }}</span>
                        </td>
                        <td class="px-4 py-2 text-right font-mono {{ $taxBpjs['bpjs_kesehatan_employee'] > 0 ? 'text-red-600' : 'text-gray-400' }}">
                            {{ $taxBpjs['bpjs_kesehatan_employee'] > 0 ? '−' . rupiah($taxBpjs['bpjs_kesehatan_employee']) : rupiah(0) }}
                        </td>
                    </tr>
                @endif

                @if($showBpjsTk)
                    <tr class="border-b">
                        <td class="px-4 py-2 text-gray-600">
                            BPJS Ketenagakerjaan
                            <span class="text-[10px] text-blue-500">[auto]</span>
                            <span class="text-[11px] text-gray-400">
                                JHT {{ number_format($payrollSetting->bpjs_jht_employee_rate, 2, ',', '.') }}% + JP {{ number_format($payrollSetting->bpjs_jp_employee_rate, 2, ',', '.') }}%
                            </span>
                        </td>
                        <td class="px-4 py-2 text-right font-mono {{ $taxBpjs['bpjs_tk_employee_total'] > 0 ? 'text-red-600' : 'text-gray-400' }}">
                            {{ $taxBpjs['bpjs_tk_employee_total'] > 0 ? '−' . rupiah($taxBpjs['bpjs_tk_employee_total']) : rupiah(0) }}
                        </td>
                    </tr>
                @endif

                @if($showPph21)
                    <tr class="border-b">
                        <td class="px-4 py-2 text-gray-600">
                            PPh 21
                            <span class="text-[10px] text-blue-500">[auto]</span>
                            <span class="text-[11px] text-gray-400">
                                TER kategori {{ $karyawan->ptkp_category }} · {{ number_format(\App\Modules\SDM\Services\PayrollTaxBpjsCalculator::lookupTerRate($karyawan->ptkp_category, $brutoSebelumPotongan) * 100, 2, ',', '.') }}%
                            </span>
                        </td>
                        <td class="px-4 py-2 text-right font-mono {{ $taxBpjs['pph21'] > 0 ? 'text-red-600' : 'text-gray-400' }}">
                            {{ $taxBpjs['pph21'] > 0 ? '−' . rupiah($taxBpjs['pph21']) : rupiah(0) }}
                        </td>
                    </tr>
                @endif

                @if(($taxBpjs['bpjs_kesehatan_company'] + $taxBpjs['bpjs_tk_company_total']) > 0)
                    <tr class="border-b bg-blue-50">
                        <td class="px-4 py-2 text-gray-500 text-xs">
                            <em>Iuran ditanggung perusahaan</em>
                            <span class="text-[10px] text-gray-400">
                                (Kesehatan {{ rupiah($taxBpjs['bpjs_kesehatan_company']) }} + TK {{ rupiah($taxBpjs['bpjs_tk_company_total']) }})
                            </span>
                        </td>
                        <td class="px-4 py-2 text-right font-mono text-xs text-gray-500">
                            {{ rupiah($taxBpjs['bpjs_kesehatan_company'] + $taxBpjs['bpjs_tk_company_total']) }}
                        </td>
                    </tr>
                @endif
            @endif
        @endif

        @php $totalRow = $summaryRows->firstWhere('key', \App\Modules\SDM\Models\KebijakanSummary::KEY_TOTAL); @endphp
        <tr class="bg-green-100">
            <td class="px-4 py-3 font-bold">{{ $totalRow->label ?? 'Total Gaji Yang Dibayarkan' }}</td>
            <td class="px-4 py-3 text-right font-mono font-bold text-base">{{ rupiah($totalDibayarkan) }}</td>
        </tr>
    </tbody>
</table>
