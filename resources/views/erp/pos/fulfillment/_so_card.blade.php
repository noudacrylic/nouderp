{{-- Kartu pesanan ringkas (SO). Param: $row, $mode ('perlu_diproses'|'belum_siap'|'telah_diproses') --}}
@php
    $r = $row;
    $mode = $mode ?? 'perlu_diproses';
    $deadline = $r['deadline'] ?? null;
    $lines = $r['delivery']['lines'] ?? [];
    $maxShow = 3;
    $fmtQty = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');

    // Status pengiriman (untuk header tab "Telah Diproses"): batas kirim → "sudah dikirim"
    // bila resi sudah di-generate (ada nomor resi) atau pesanan ambil-toko sudah diambil.
    $postedDeliveries = ($r['deliveries'] ?? collect())->filter(fn ($d) => $d->status === 'posted');
    $shipDeliveries   = $postedDeliveries->where('delivery_method', '!=', 'ambil_toko');
    $shipCount        = $shipDeliveries->count();
    $resiCount        = $shipDeliveries->filter(fn ($d) => !empty($d->tracking_number))->count();
    $pickupDone       = $r['is_pickup'] && (($r['pickup_status'] ?? null) === 'picked_up');
    $fullyShipped     = $pickupDone || ($shipCount > 0 && $resiCount === $shipCount);
    $partlyShipped    = !$fullyShipped && $resiCount > 0;

    // ID untuk aksi massal di tab "Telah Diproses".
    $bulkInvoiceId = $r['invoice']->id ?? '';
    $bulkSjIds     = $postedDeliveries->pluck('id')->implode(',');                                          // semua SJ posted (utk Cetak SJ)
    $bulkResiIds   = $shipDeliveries->filter(fn ($d) => !empty($d->tracking_number))->pluck('id')->implode(','); // SJ ber-resi (utk Cetak Resi)
    $bulkGenIds    = $shipDeliveries->filter(fn ($d) => empty($d->tracking_number))->pluck('id')->implode(',');  // SJ belum resi (utk Generate Resi)

    $qtyRow = function ($ln) use ($fmtQty) {
        $shipped = (float) $ln['shipped'];
        $remaining = (float) $ln['remaining'];
        return '<div class="flex items-start px-3 py-1.5 border-t border-gray-200">'
            . '<span class="flex-1 text-gray-700">' . e($ln['name'])
            . ($ln['sku'] ? '<span class="text-gray-400"> · ' . e($ln['sku']) . '</span>' : '') . '</span>'
            . '<span class="w-16 text-right text-gray-700">' . $fmtQty($ln['ordered']) . '</span>'
            . '<span class="w-16 text-right ' . ($shipped > 0 ? 'text-green-600' : 'text-gray-400') . '">' . $fmtQty($shipped) . '</span>'
            . '<span class="w-16 text-right ' . ($remaining > 0 ? 'text-amber-600 font-semibold' : 'text-gray-400') . '">' . $fmtQty($remaining) . '</span>'
            . '</div>';
    };
@endphp
<div class="bg-white rounded-xl border border-gray-300 border-l-4 border-l-emerald-500 shadow-md hover:shadow-lg transition-shadow p-4">
    {{-- Header: nomor + kurir/ambil-toko + status/batas waktu --}}
    <div class="flex items-center gap-2 flex-wrap -mx-4 -mt-4 px-4 py-2.5 bg-emerald-100 rounded-t-xl border-b border-emerald-200">
        @if($mode === 'perlu_diproses')
            <input type="checkbox" class="js-bulk-check w-4 h-4 accent-indigo-600 cursor-pointer"
                   value="{{ $r['id'] }}" data-number="{{ $r['number'] }}"
                   data-lunas="{{ $r['is_lunas'] ? 1 : 0 }}" data-pickup="{{ $r['is_pickup'] ? 1 : 0 }}"
                   title="Pilih untuk aksi massal">
        @elseif($mode === 'telah_diproses')
            <input type="checkbox" class="js-bulk-td w-4 h-4 accent-emerald-600 cursor-pointer"
                   value="{{ $r['id'] }}" data-number="{{ $r['number'] }}"
                   data-invoice="{{ $bulkInvoiceId }}" data-sj="{{ $bulkSjIds }}"
                   data-resi="{{ $bulkResiIds }}" data-gen="{{ $bulkGenIds }}"
                   title="Pilih untuk aksi massal">
        @endif
        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-blue-100 text-blue-700">SO</span>
        <span class="js-copy font-bold text-gray-800 cursor-pointer hover:text-indigo-600" data-copy="{{ $r['number'] }}" title="Klik untuk salin nomor">{{ $r['number'] }}</span>
        @if(!empty($r['delivery_display']))
            <span class="px-2 py-0.5 rounded text-[10px] font-bold {{ $r['is_pickup'] ? 'bg-amber-100 text-amber-700' : 'bg-sky-100 text-sky-700' }}">
                {{ $r['is_pickup'] ? '🏬' : '🚚' }} {{ $r['delivery_display'] }}
            </span>
        @endif
        @if($mode === 'belum_siap' && !empty($r['reason']))
            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-gray-100 text-gray-500">⏳ {{ $r['reason'] }}</span>
        @elseif($mode === 'telah_diproses')
            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-green-100 text-green-700">✓ DIPROSES</span>
        @endif
        @if($mode === 'telah_diproses' && $fullyShipped)
            <span class="ml-auto text-xs whitespace-nowrap text-green-600 font-semibold">
                ✓ {{ $pickupDone ? 'Sudah diambil' : 'Sudah dikirim' }}
            </span>
        @elseif($mode === 'telah_diproses' && $partlyShipped)
            <span class="ml-auto text-xs whitespace-nowrap text-sky-600 font-semibold">
                🚚 Sebagian dikirim ({{ $resiCount }}/{{ $shipCount }})
            </span>
        @elseif($r['is_pickup'] && !empty($r['pickup_date']))
            @php $pickupOverdue = \Carbon\Carbon::parse($r['pickup_date'])->lt(\Carbon\Carbon::today()); @endphp
            <span class="ml-auto text-xs whitespace-nowrap {{ $pickupOverdue ? 'text-red-600 font-bold' : 'text-amber-700 font-semibold' }}">
                🏬 Ambil {{ \Carbon\Carbon::parse($r['pickup_date'])->format('d M Y') }}{{ $pickupOverdue ? ' (lewat)' : '' }}
            </span>
        @elseif($deadline)
            <span class="ml-auto text-xs whitespace-nowrap {{ $r['is_overdue'] ? 'text-red-600 font-bold' : 'text-gray-500' }}">
                ⏱ Batas kirim {{ $deadline->format('d M Y') }}{{ $r['is_overdue'] ? ' (lewat)' : '' }}
            </span>
        @endif
    </div>

    {{-- Body 2 kolom — kiri: produk & qty + catatan pembeli · kanan: customer/alamat + nilai + instruksi --}}
    <div class="mt-3 grid grid-cols-1 lg:grid-cols-2 gap-x-5 gap-y-3">

        {{-- ───── KIRI ───── --}}
        <div class="space-y-3 min-w-0">
            {{-- Produk + SKU + Qty (Dipesan / Dikirim / Belum). Maks 3, sisanya expand --}}
            <div class="border border-gray-200 rounded-lg overflow-hidden text-[13px]">
                <div class="flex items-center bg-gray-50 text-gray-500 px-3 py-2 font-semibold uppercase tracking-wide text-[11px]">
                    <span class="flex-1">Produk &amp; SKU</span>
                    <span class="w-16 text-right">Dipesan</span>
                    <span class="w-16 text-right">Dikirim</span>
                    <span class="w-16 text-right">Belum</span>
                </div>
                @foreach(array_slice($lines, 0, $maxShow) as $ln)
                    {!! $qtyRow($ln) !!}
                @endforeach
                @if(count($lines) > $maxShow)
                    <details class="border-t border-gray-100 group">
                        <summary class="px-3 py-1.5 cursor-pointer text-indigo-600 hover:bg-gray-50 select-none font-semibold">
                            +{{ count($lines) - $maxShow }} produk lainnya
                        </summary>
                        @foreach(array_slice($lines, $maxShow) as $ln)
                            {!! $qtyRow($ln) !!}
                        @endforeach
                    </details>
                @endif
                @if(empty($lines))
                    <div class="px-3 py-1.5 border-t border-gray-100 text-gray-400">Tidak ada barang fisik.</div>
                @endif
            </div>

            {{-- Catatan Pembeli --}}
            <div class="text-[13px]">
                <div class="text-gray-400 font-semibold mb-0.5">💬 Catatan Pembeli</div>
                <div class="text-gray-700 whitespace-pre-line break-words">{{ $r['notes'] ?: '—' }}</div>
            </div>
        </div>

        {{-- ───── KANAN ───── --}}
        <div class="space-y-3 min-w-0 lg:pl-6 lg:border-l lg:border-gray-100">
            {{-- Customer + alamat --}}
            <div>
                <div class="text-base font-bold text-gray-800">
                    {{ $r['customer'] }}
                    @if($r['phone'])<span class="font-normal text-gray-500 text-xs"> · {{ $r['phone'] }}</span>@endif
                </div>
                @if($r['address'])
                    <div class="text-[13px] text-gray-600 mt-1 leading-snug break-words">📍 {{ $r['address'] }}</div>
                @endif
            </div>

            {{-- Nilai pesanan --}}
            <div class="text-[13px] flex items-center gap-1.5 flex-wrap">
                <span class="text-gray-500">Total <b class="text-gray-800">Rp {{ number_format($r['grand_total'], 0, ',', '.') }}</b></span>
                <span class="text-gray-300">·</span>
                <span class="text-gray-500">DP <b class="text-gray-800">Rp {{ number_format($r['paid'], 0, ',', '.') }}</b></span>
                <span class="text-gray-300">·</span>
                @if($r['is_lunas'])
                    <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-green-100 text-green-700">✓ LUNAS</span>
                @else
                    <span class="text-amber-700 font-semibold">Sisa Rp {{ number_format($r['remaining'], 0, ',', '.') }}</span>
                @endif
            </div>

            {{-- Instruksi (catatan packing/pengiriman — sebelumnya "Catatan Penjual") --}}
            <div class="text-[13px] js-seller" data-so="{{ $r['id'] }}">
                <div class="text-gray-400 font-semibold mb-0.5">
                    🏷 Instruksi
                    <button type="button" class="js-seller-edit text-indigo-600 hover:underline ml-0.5 font-normal" title="Edit instruksi">✎</button>
                </div>
                <div class="js-seller-text text-gray-700 whitespace-pre-line break-words">{{ $r['seller_notes'] ?: '—' }}</div>
                <div class="js-seller-form hidden mt-1">
                    <textarea class="js-seller-input w-full border rounded px-2 py-1 text-[13px]" rows="2" placeholder="Instruksi packing/pengiriman… (mis. kirim sebelum jam 12, packing kayu)">{{ $r['seller_notes'] }}</textarea>
                    <div class="flex gap-2 mt-1">
                        <button type="button" class="js-seller-save text-[11px] px-2.5 py-1 rounded bg-indigo-600 hover:bg-indigo-700 text-white font-semibold">Simpan</button>
                        <button type="button" class="js-seller-cancel text-[11px] px-2.5 py-1 rounded border border-gray-300 text-gray-500 hover:bg-gray-50">Batal</button>
                    </div>
                </div>
            </div>
        </div>

    </div>

    {{-- ───────── Footer aksi (berbeda per tab) ───────── --}}
    @if($mode === 'perlu_diproses')
        <div class="mt-3 flex items-center gap-2 flex-wrap border-t border-gray-50 pt-3">
            <a href="{{ route('sales.orders.print', $r['id']) }}"
               class="text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">🖨 Cetak SO</a>
            @unless($r['is_lunas'])
                <a href="{{ route('sales.payment.create', ['customer_id' => $r['customer_id'], 'so_id' => $r['id'], 'mode' => 'uang_muka']) }}"
                   class="text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">💵 Cash</a>
                <button type="button" onclick="window._midtransOpenSo({{ $r['id'] }})"
                        class="text-xs px-3 py-1.5 rounded border border-indigo-300 text-indigo-700 hover:bg-indigo-50 font-semibold">💳 Link</button>
                <button type="button" onclick="window._midtransOpenSoQris({{ $r['id'] }})"
                        class="text-xs px-3 py-1.5 rounded border border-emerald-300 text-emerald-700 hover:bg-emerald-50 font-semibold">QRIS</button>
            @endunless
            <div class="ml-auto flex items-center gap-2 flex-wrap justify-end">
                <a href="{{ route('sales.deliveries.createFromSO', $r['id']) }}"
                   class="text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">📦 Kirim Sebagian</a>
                <form action="{{ route('pos.fulfillment.proses', $r['id']) }}" method="POST" class="flex items-center gap-1.5"
                      onsubmit="return confirm('Proses pesanan {{ $r['number'] }}? Faktur + Surat Jalan akan dibuat otomatis.')">
                    @csrf
                    @if($r['is_pickup'])
                        <input type="text" name="pickup_code" placeholder="Kode (4 angka)" required
                               inputmode="numeric" maxlength="5"
                               class="border rounded px-2.5 py-1.5 text-xs w-32 font-mono tracking-widest">
                    @endif
                    <button type="submit" name="print_after" value="0" {{ $r['is_lunas'] ? '' : 'disabled' }}
                            class="px-3 py-1.5 rounded text-xs font-bold text-white {{ $r['is_lunas'] ? 'bg-green-600 hover:bg-green-700' : 'bg-gray-300 cursor-not-allowed' }}">✅ Proses</button>
                </form>
            </div>
        </div>
        @unless($r['is_lunas'])
            <p class="text-[11px] text-amber-600 mt-1 text-right">Belum lunas — selesaikan pembayaran dulu untuk memproses.</p>
        @endunless

    @elseif($mode === 'belum_siap')
        <div class="mt-3 flex items-center gap-2 flex-wrap border-t border-gray-50 pt-3">
            <a href="{{ route('sales.orders.print', $r['id']) }}"
               class="text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">🖨 Cetak SO</a>
            @unless($r['is_lunas'])
                <a href="{{ route('sales.payment.create', ['customer_id' => $r['customer_id'], 'so_id' => $r['id'], 'mode' => 'uang_muka']) }}"
                   class="text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">💵 Cash</a>
                <button type="button" onclick="window._midtransOpenSo({{ $r['id'] }})"
                        class="text-xs px-3 py-1.5 rounded border border-indigo-300 text-indigo-700 hover:bg-indigo-50 font-semibold">💳 Link</button>
                <button type="button" onclick="window._midtransOpenSoQris({{ $r['id'] }})"
                        class="text-xs px-3 py-1.5 rounded border border-emerald-300 text-emerald-700 hover:bg-emerald-50 font-semibold">QRIS</button>
            @endunless
            <span class="ml-auto text-[11px] text-gray-400 italic">Otomatis pindah ke "Perlu Diproses" saat syarat terpenuhi.</span>
        </div>

    @elseif($mode === 'telah_diproses')
        @php
            $deliveries = ($r['deliveries'] ?? collect())->filter(fn ($d) => $d->status === 'posted');
        @endphp
        <div class="mt-3 flex items-center gap-2 flex-wrap border-t border-gray-50 pt-3">
            <a href="{{ route('sales.orders.print', $r['id']) }}"
               class="text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">🖨 Cetak SO</a>
            @if(!empty($r['invoice']))
                <a href="{{ route('sales.invoices.print', $r['invoice']->id) }}"
                   class="text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">🧾 Cetak Faktur</a>
                <a href="{{ route('sales.invoices.show', $r['invoice']->id) }}"
                   class="text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">Lihat Faktur</a>
            @endif
        </div>
        @if($deliveries->count())
            <div class="mt-2 space-y-1.5">
                @foreach($deliveries as $d)
                    <div class="flex items-center justify-between gap-2 flex-wrap text-xs bg-gray-50/70 rounded-lg px-3 py-1.5">
                        <div class="min-w-0">
                            <span class="font-semibold text-gray-700">📄 <span class="js-copy cursor-pointer hover:text-indigo-600" data-copy="{{ $d->delivery_number }}" title="Klik untuk salin nomor SJ">{{ $d->delivery_number }}</span></span>
                            @if($d->tracking_number)<span class="text-green-600 ml-1">Resi: <span class="js-copy cursor-pointer hover:underline" data-copy="{{ $d->tracking_number }}" title="Klik untuk salin resi">{{ $d->tracking_number }}</span></span>@endif
                        </div>
                        <div class="flex items-center gap-2 flex-wrap">
                            @if($d->delivery_method !== 'ambil_toko')
                                @php $isManual = \App\Models\ManualCourier::isManualCode($d->shipping_courier_code); @endphp
                                @if($d->tracking_number)
                                    {{-- Sudah ada resi (Biteship) → cetak ulang resi & lacak --}}
                                    <a href="{{ route('sales.deliveries.resi', $d->id) }}"
                                       class="px-2.5 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">🏷️ Cetak Resi</a>
                                    <a href="{{ route('sales.deliveries.track', $d->id) }}"
                                       class="px-2.5 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">🔎 Lacak</a>
                                @elseif($isManual)
                                    {{-- Kurir manual → cetak label tanpa resi --}}
                                    <a href="{{ route('sales.deliveries.label', $d->id) }}"
                                       class="px-2.5 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">🏷️ Cetak Label</a>
                                @elseif($d->shipping_courier_code)
                                    {{-- Kurir Biteship → generate resi via API --}}
                                    <button type="button"
                                            class="js-genresi px-2.5 py-1 rounded border border-indigo-300 text-indigo-700 hover:bg-indigo-50 font-semibold"
                                            data-id="{{ $d->id }}" data-number="{{ $d->delivery_number }}">📮 Generate Resi</button>
                                @else
                                    {{-- Kurir belum spesifik → tawarkan keduanya --}}
                                    <button type="button"
                                            class="js-genresi px-2.5 py-1 rounded border border-indigo-300 text-indigo-700 hover:bg-indigo-50 font-semibold"
                                            data-id="{{ $d->id }}" data-number="{{ $d->delivery_number }}">📮 Generate Resi</button>
                                    <a href="{{ route('sales.deliveries.label', $d->id) }}"
                                       class="px-2.5 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">🏷️ Cetak Label</a>
                                @endif
                            @endif
                            <a href="{{ route('sales.deliveries.print', $d->id) }}"
                               class="px-2.5 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">Cetak SJ</a>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            {{-- Belum ada Surat Jalan (mis. faktur masih draft) → tetap bisa cetak label dari SO. --}}
            @unless($r['is_pickup'] ?? false)
                <div class="mt-2 flex justify-end">
                    <a href="{{ route('sales.orders.label', $r['id']) }}"
                       class="text-xs px-2.5 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">🏷️ Cetak Label</a>
                </div>
            @endunless
        @endif
    @endif
</div>
