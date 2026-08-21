{{--
    Dasar perhitungan + rekonsiliasi — sama untuk Ready maupun Bundle, karena keduanya
    memakai tarif dan overhead packing yang sama. Param: $basis.
--}}
@php
    $rpBasis    = fn (?float $v) => $v === null ? '—' : 'Rp ' . number_format((float) $v, 0, ',', '.');
    $angkaBasis = fn (?float $v, int $d = 1) => $v === null ? '—' : number_format($v, $d, ',', '.');
@endphp

<div class="bg-white border border-slate-100 rounded-2xl shadow-sm px-5 py-4 mb-4">
    <div class="flex flex-wrap items-start justify-between gap-5">
        <div>
            <div class="text-sm font-bold text-slate-800">Dasar Perhitungan</div>
            <div class="text-[11px] text-slate-500 mt-0.5">
                Fixed cost {{ $rpBasis($basis['fixed_total']) }} ÷ {{ $angkaBasis($basis['available_hours'], 0) }} slot-jam tersedia
            </div>
        </div>
        <div class="flex flex-wrap gap-6 text-right">
            <div>
                <div class="text-[11px] font-bold text-slate-400 uppercase">Tarif</div>
                <div class="text-lg font-black text-slate-800">{{ $rpBasis($basis['rate_per_slot_hour']) }}<span class="text-xs font-semibold text-slate-400">/slot-jam</span></div>
            </div>
            <div>
                <div class="text-[11px] font-bold text-slate-400 uppercase">Overhead packing</div>
                <div class="text-lg font-black text-teal-700">{{ $rpBasis($basis['packing_per_transaction']) }}</div>
                <div class="text-[10px] text-slate-400">{{ $angkaBasis($basis['transactions_per_month'], 0) }} surat jalan/bulan</div>
            </div>
            <div class="border-l border-slate-100 pl-6">
                <div class="text-[11px] font-bold text-slate-400 uppercase">Terserap</div>
                <div class="text-lg font-black text-emerald-600">{{ $rpBasis($basis['absorbed']) }}</div>
                <div class="text-[10px] text-slate-400">utilisasi {{ $angkaBasis($basis['utilization']) }}%</div>
            </div>
            <div>
                <div class="text-[11px] font-bold text-slate-400 uppercase">Tidak terserap</div>
                <div class="text-lg font-black text-amber-600">{{ $rpBasis($basis['unabsorbed']) }}</div>
                <div class="text-[10px] text-slate-400">{{ $angkaBasis($basis['unabsorbed_percent']) }}% &mdash; biaya kapasitas menganggur</div>
            </div>
        </div>
    </div>
    <p class="text-[11px] text-slate-400 mt-3 leading-relaxed border-t border-slate-100 pt-3">
        <strong>Rekonsiliasi.</strong> Terserap + tidak terserap harus sama dengan fixed cost sebulan. Yang
        tidak terserap adalah jam yang dibayar tapi tidak menghasilkan apa-apa &mdash; sengaja tidak dibebankan
        ke produk, supaya HPP tidak naik-turun ikut sepi-ramainya bulan. Pembaginya jam
        <strong>tersedia</strong>; ubah jamnya di <a href="{{ route('analisa.kuota.index') }}" class="text-blue-600 font-semibold hover:underline">Kuota Produksi</a>,
        ubah waktu per unitnya di <a href="{{ route('analisa.waktu-produksi.index') }}" class="text-blue-600 font-semibold hover:underline">Waktu Produksi</a>.
    </p>
</div>
