@php
    // Subtotal kini adalah NET (sudah dikurangi diskon per item)
    $subtotal = $so->items->sum('line_total');
    $discountItem = 0; // Tidak ditampilkan di summary agar tidak redundan
    $dpp = $subtotal - $so->global_discount_amount;
    $advancePaid = $advancePaid ?? 0;
    $remaining = $so->grand_total - $advancePaid;
@endphp

@include('erp.components.transaction-summary', [
    'notes' => $so->notes ?? '-',
    'updateNotesUrl' => route('sales.orders.update-notes', $so->id),
    'subtotal' => $subtotal,
    'discountItem' => $discountItem,
    'discountGlobal' => $so->global_discount_amount,
    'dpp' => $dpp,
    'ppn' => $so->ppn_amount,
    'shipping' => $so->shipping_cost,
    'shippingGross' => $so->shipping_gross,
    'shippingDiscount' => max(0, (float) ($so->shipping_gross ?? 0) - (float) ($so->shipping_cost ?? 0)),
    'additionalFee' => $so->additional_fee,
    'marketplaceFee' => $so->marketplace_fee,
    'uniqueCode' => (int) ($so->unique_code ?? 0),
    'grandTotal' => $so->grand_total,
    'advancePaid' => $advancePaid,
    'remaining' => ($invoiceStatus === 'invoiced') ? null : $remaining
])

{{-- Kesepakatan batas minimal DP — inilah yang dipakai tautan pembayaran DP. --}}
@if($so->status !== 'void' && $remaining > 0)
    @php
        $mdBase = $so->minDpBaseAmount();
        $mdSisa = $so->minDpAmount();
    @endphp
    <div class="mt-4 p-3 rounded-lg border text-sm flex items-start justify-between gap-3
        {{ $so->hasCustomMinDp() ? 'bg-amber-50 border-amber-200' : 'bg-gray-50 border-gray-200' }}">
        <div>
            <div class="font-bold {{ $so->hasCustomMinDp() ? 'text-amber-800' : 'text-gray-700' }}">
                Minimal DP
                @if($so->hasCustomMinDp())
                    <span class="ml-1 px-1.5 py-0.5 rounded bg-amber-200 text-amber-900 text-[10px] font-bold uppercase tracking-wide">Kesepakatan</span>
                @endif
            </div>
            <div class="text-[11px] text-gray-500 mt-0.5">
                {{ rtrim(rtrim(number_format($so->minDpPercent(), 2, ',', '.'), '0'), ',') }}% dari total
                @if($advancePaid > 0)
                    &mdash; sisa yang harus dibayar minimal <b>{{ format_rupiah($mdSisa) }}</b>
                @endif
                @unless($so->hasCustomMinDp())
                    (bawaan)
                @endunless
            </div>
        </div>
        <div class="text-right font-bold whitespace-nowrap {{ $so->hasCustomMinDp() ? 'text-amber-800' : 'text-gray-700' }}">
            {{ format_rupiah($mdBase) }}
        </div>
    </div>
@endif

{{-- Kesepakatan pembayaran TEMPO. Sama seperti keep stock, ada di halaman lihat karena
     kesepakatan tempo sering lahir setelah SO dikonfirmasi — dan SO terkonfirmasi tidak
     bisa diedit lewat form. --}}
@if($so->status !== 'void')
    @php $tempoLewat = $so->isTempoOverdue(); @endphp
    <div class="mt-3 p-3 rounded-lg border text-sm
        {{ $tempoLewat ? 'bg-red-50 border-red-200' : ($so->is_tempo ? 'bg-indigo-50 border-indigo-200' : 'bg-gray-50 border-gray-200') }}">
        <form method="POST" action="{{ route('sales.orders.tempo', $so->id) }}" class="flex items-start gap-2">
            @csrf
            <input type="hidden" name="is_tempo" value="{{ $so->is_tempo ? 0 : 1 }}">
            <input type="hidden" name="tempo_days" value="{{ $so->tempo_days }}">
            <input type="checkbox" onchange="this.form.submit()" {{ $so->is_tempo ? 'checked' : '' }}
                class="mt-0.5 rounded border-gray-300 text-indigo-500 focus:ring-indigo-400 cursor-pointer">
            <div class="flex-1">
                <div class="font-bold {{ $tempoLewat ? 'text-red-800' : ($so->is_tempo ? 'text-indigo-800' : 'text-gray-700') }}">
                    Pembayaran Tempo
                    @if($so->is_tempo && $so->tempo_days)
                        <span class="ml-1 px-1.5 py-0.5 rounded bg-indigo-200 text-indigo-900 text-[10px] font-bold uppercase tracking-wide">{{ $so->tempo_days }} hari</span>
                    @endif
                </div>
                <div class="text-[11px] text-gray-500 mt-0.5">
                    @if($tempoLewat)
                        <span class="text-red-700 font-semibold">
                            Lewat jatuh tempo {{ abs($so->tempoDaysLeft()) }} hari ({{ $so->tempo_due_date->format('d/m/Y') }})
                            — sisa {{ format_rupiah(max(0, (float) $so->grand_total - (float) $so->paid_amount)) }}.
                        </span>
                    @elseif($so->is_tempo && $so->tempo_due_date)
                        Barang boleh dikirim sebelum dibayar. Jatuh tempo <b>{{ $so->tempo_due_date->format('d/m/Y') }}</b>
                        ({{ $so->tempoDaysLeft() }} hari lagi).
                    @elseif($so->is_tempo)
                        Barang boleh dikirim sebelum dibayar, tanpa batas waktu yang disepakati.
                    @else
                        Centang bila pembeli membayar belakangan — pesanan langsung masuk antrean kerja
                        tanpa menunggu DP maupun pelunasan.
                    @endif
                </div>
            </div>
        </form>

        {{-- Termin dipisah dari kotak centangnya (form sendiri, tak boleh bersarang) supaya
             lama tempo bisa dikoreksi tanpa harus mematikan lalu menyalakan ulang tempo.
             Dikosongkan = tempo tanpa batas waktu, sama seperti di form SO. --}}
        @if($so->is_tempo)
            <form method="POST" action="{{ route('sales.orders.tempo', $so->id) }}"
                  class="mt-2 pl-6 flex items-center gap-2 flex-wrap">
                @csrf
                <input type="hidden" name="is_tempo" value="1">
                <span class="text-[11px] text-gray-500">Termin</span>
                <input type="text" name="tempo_days" inputmode="numeric" value="{{ $so->tempo_days }}"
                       placeholder="30"
                       class="border border-gray-300 rounded px-2 py-1 w-20 text-right text-sm bg-white focus:ring-blue-500 focus:border-blue-500">
                <span class="text-[11px] text-gray-500">hari</span>
                <button type="submit"
                        class="text-[11px] px-2.5 py-1 rounded border border-indigo-300 text-indigo-700 hover:bg-indigo-50 font-semibold">
                    Simpan
                </button>
                <span class="text-[11px] text-gray-400">
                    dihitung dari tanggal SO ({{ $so->order_date ? \Carbon\Carbon::parse($so->order_date)->format('d/m/Y') : '—' }});
                    kosongkan = tanpa batas waktu
                </span>
            </form>
        @endif
    </div>
@endif

{{-- Kesepakatan keep stock + kondisi stok saat ini. Ada di sini karena kesepakatannya
     sering lahir setelah SO dikonfirmasi (pembeli menelepon saat pembayarannya tertahan),
     dan SO yang sudah dikonfirmasi tidak bisa diedit lewat form. --}}
@if($so->status !== 'void')
    @php
        $kurangStok = $so->allow_backorder ? [] : app(\App\Modules\Sales\Services\SalesOrderStockCheck::class)->shortages($so);
    @endphp
    <div class="mt-3 p-3 rounded-lg border text-sm
        {{ $so->allow_backorder ? 'bg-amber-50 border-amber-200' : (empty($kurangStok) ? 'bg-gray-50 border-gray-200' : 'bg-red-50 border-red-200') }}">
        <form method="POST" action="{{ route('sales.orders.keep-stock', $so->id) }}" class="flex items-start gap-2">
            @csrf
            <input type="hidden" name="allow_backorder" value="{{ $so->allow_backorder ? 0 : 1 }}">
            <input type="checkbox" onchange="this.form.submit()" {{ $so->allow_backorder ? 'checked' : '' }}
                class="mt-0.5 rounded border-gray-300 text-amber-500 focus:ring-amber-400 cursor-pointer">
            <div class="flex-1">
                <div class="font-bold {{ $so->allow_backorder ? 'text-amber-800' : 'text-gray-700' }}">Keep stock (stok menyusul)</div>
                <div class="text-[11px] text-gray-500 mt-0.5">
                    @if($so->allow_backorder)
                        Disepakati dengan pembeli — pembayaran tetap diterima walau stok tidak cukup, stok boleh minus.
                    @elseif(empty($kurangStok))
                        Stok saat ini mencukupi. Centang bila pembeli setuju memesan barang yang stoknya belum ada.
                    @else
                        <span class="text-red-700 font-semibold">Pembayaran lewat tautan sedang ditolak — stok tidak cukup:</span>
                        <ul class="mt-1 space-y-0.5 text-red-700">
                            @foreach($kurangStok as $k)
                                <li>{{ $k['sku'] }} — dipesan {{ format_qty($k['needed']) }}, tersisa {{ format_qty($k['available']) }} (kurang {{ format_qty($k['short']) }})</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </form>
    </div>
@endif

@if(($invoiceStatus ?? '') === 'invoiced')
    <div class="mt-4 p-3 bg-green-50 rounded-lg border border-green-200 text-green-700 text-sm font-bold flex items-center gap-2 shadow-sm">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
        </svg>
        <span>Pesanan Selesai Di-Faktur — Pembayaran dikelola melalui modul Faktur</span>
    </div>
@endif


