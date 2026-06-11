@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4">

    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Konfirmasi Hasil Produksi</h1>
            <p class="text-xs text-gray-500 mt-0.5">
                <a href="{{ route('production.orders.show', $order->id) }}" class="font-bold text-blue-600 hover:underline">{{ $order->order_number }}</a>
                · Semua langkah selesai — konfirmasi qty output sebelum masuk stok
            </p>
        </div>
        <a href="{{ route('production.process.index') }}"
           class="border border-gray-200 text-gray-600 hover:bg-gray-50 px-4 py-2 rounded-xl text-sm font-semibold transition">
            ← Kembali ke Proses
        </a>
    </div>


    <form action="{{ route('production.orders.finalize', $order->id) }}" method="POST">
        @csrf

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">

            {{-- Section 1: Output Aktual (operator isi di akhir langkah, admin masih bisa koreksi) --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-bold text-gray-800">Output Produksi</h2>
                    <span class="text-[10px] font-bold bg-green-100 text-green-700 border border-green-200 px-2 py-0.5 rounded uppercase tracking-widest">
                        ✓ Diisi Operator — masih bisa dikoreksi
                    </span>
                </div>

                <div class="space-y-3">
                    @foreach($order->outputs as $i => $out)
                        @php
                            $qtyActualSaved = (float) ($out->qty_produced ?? 0);
                            $qtyPlanned     = (float) $out->qty_planned;
                            // Default qty aktual = target jika belum diisi, supaya admin lihat angka jelas.
                            $qtyDefault     = $qtyActualSaved > 0 ? $qtyActualSaved : $qtyPlanned;
                            $pctSaved       = (float) $out->percentage;
                        @endphp
                        <input type="hidden" name="outputs[{{ $i }}][output_id]" value="{{ $out->id }}">

                        <div class="border border-gray-100 rounded-xl p-4 bg-gray-50/50">
                            <div class="flex items-center gap-2 mb-3 flex-wrap">
                                <span class="font-bold text-gray-800 text-sm">{{ $out->product?->name ?? '—' }}</span>
                                @if($out->output_type === 'main')
                                    <span class="text-[10px] bg-green-100 text-green-700 px-1.5 py-0.5 rounded font-black">UTAMA</span>
                                @else
                                    <span class="text-[10px] bg-gray-200 text-gray-600 px-1.5 py-0.5 rounded font-black">SAMPINGAN</span>
                                @endif
                                @if($out->product?->sku)
                                    <span class="text-xs text-gray-400">{{ $out->product->sku }}</span>
                                @endif
                            </div>

                            <div class="grid grid-cols-3 gap-3">
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-500 mb-1">Target</label>
                                    <div class="text-sm font-bold text-gray-600 bg-gray-100 rounded-lg px-3 py-2 text-right">
                                        {{ number_format($qtyPlanned, 2) }}
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-500 mb-1">Qty Aktual *</label>
                                    <input type="number"
                                           name="outputs[{{ $i }}][qty_produced]"
                                           value="{{ number_format($qtyDefault, 2, '.', '') }}"
                                           step="0.01" min="0" required
                                           class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm font-bold focus:outline-none focus:ring-2 focus:ring-green-400 bg-white text-right">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-500 mb-1">Persentase Cost (%) *</label>
                                    <input type="number"
                                           name="outputs[{{ $i }}][percentage]"
                                           value="{{ number_format($pctSaved, 2, '.', '') }}"
                                           step="0.01" min="0" max="100" required
                                           class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm font-bold focus:outline-none focus:ring-2 focus:ring-green-400 bg-white text-right">
                                </div>
                            </div>

                            <div class="mt-3">
                                <label class="block text-[10px] font-bold text-gray-500 mb-1">
                                    Keterangan
                                    <span class="font-normal text-gray-400">(audit — penyebab kelebihan/kurangnya output, kondisi material, dsb.)</span>
                                </label>
                                <input type="text"
                                       name="outputs[{{ $i }}][variance_notes]"
                                       value="{{ $out->variance_notes }}"
                                       placeholder="cth: 1 pcs cacat saat cutting, lembar akrilik tipis di sudut kanan..."
                                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 bg-white text-gray-700">
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Section 2: Ringkasan Waktu Produksi --}}
            <div class="space-y-5">
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <h2 class="font-bold text-gray-800 mb-4">Ringkasan Waktu Produksi</h2>

                    @if($deptSummary->isNotEmpty())
                        <div class="space-y-3">
                            @foreach($deptSummary as $dept)
                            <div class="border border-gray-100 rounded-xl p-3 bg-gray-50/50">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="flex-1 min-w-0">
                                        <div class="font-bold text-gray-700 text-sm">{{ $dept['dept_name'] }}</div>
                                        <div class="text-xs text-gray-400 mt-0.5 truncate">{{ $dept['step_names'] }}</div>
                                        @if($dept['operators'] !== '—')
                                            <div class="text-xs text-gray-500 mt-1">👤 {{ $dept['operators'] }}</div>
                                        @endif
                                    </div>
                                    <div class="text-right flex-shrink-0">
                                        @if($dept['total_sec'] > 0)
                                            <div class="font-mono font-black text-indigo-700 text-sm">{{ $dept['total_dur'] }}</div>
                                        @else
                                            <div class="text-xs text-gray-400">—</div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            @endforeach
                        </div>

                        <div class="mt-3 pt-3 border-t border-gray-100 flex justify-between items-center">
                            <span class="text-xs font-black text-gray-500 uppercase">Total Waktu Produksi</span>
                            <span class="font-mono font-black text-gray-800">{{ $totalDur }}</span>
                        </div>
                    @else
                        <p class="text-xs text-gray-400 text-center py-4">Tidak ada data waktu produksi.</p>
                    @endif
                </div>

                {{-- Info warning --}}
                <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 text-xs text-amber-800">
                    <div class="font-bold mb-1">Saat finalisasi:</div>
                    <ul class="list-disc list-inside space-y-1">
                        <li>Qty aktual di atas sudah dikonfirmasi operator di akhir langkah produksi.</li>
                        <li>Klik <b>Selesaikan Produksi</b> untuk memasukkan output ke stok (FIFO) dan posting jurnal WIP → Persediaan.</li>
                        <li>Jika ada koreksi qty, kembali ke halaman order produksi dan ubah lewat fitur <i>void</i> atau hubungi admin.</li>
                    </ul>
                </div>
            </div>
        </div>

        {{-- Action Buttons --}}
        <div class="mt-6 flex items-center gap-3">
            <button type="submit"
                    onclick="return confirm('Selesaikan produksi dan masukkan output ke stok?')"
                    class="px-6 py-3 bg-green-600 hover:bg-green-700 text-white text-sm font-bold rounded-xl transition shadow-sm">
                ✓ Selesaikan Produksi
            </button>
            <a href="{{ route('production.process.index') }}"
               class="px-5 py-3 border border-gray-200 text-gray-600 hover:bg-gray-50 text-sm font-semibold rounded-xl transition">
                Kembali ke Proses
            </a>
            <span class="text-xs text-gray-400 ml-2">Order tetap tercatat sebagai "Menunggu Finalisasi" jika kembali.</span>
        </div>

    </form>

</div>
@endsection
