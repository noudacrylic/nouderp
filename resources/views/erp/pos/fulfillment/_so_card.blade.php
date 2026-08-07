{{-- Kartu pesanan ringkas (SO). Param: $row, $mode ('perlu_diproses'|'belum_siap'|'telah_diproses') --}}
@php
    $r = $row;
    $mode = $mode ?? 'perlu_diproses';
    // "Telah Diproses", "Dikirim" & "Selesai" berbagi tampilan footer/pengiriman yang sama.
    $isDone  = in_array($mode, ['telah_diproses', 'dikirim', 'selesai'], true);
    $isFinal = $mode === 'selesai';
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
    // Sampai di pembeli — penanda manual di tab "Dikirim", ini yang memindahkan ke "Selesai".
    $deliveredCount   = $shipDeliveries->filter(fn ($d) => $d->delivered_at !== null)->count();
    $allDelivered     = $shipCount > 0 && $deliveredCount === $shipCount;

    // ID untuk aksi massal di tab "Telah Diproses".
    $bulkInvoiceId = $r['invoice']->id ?? '';
    $bulkSjIds     = $postedDeliveries->pluck('id')->implode(',');                                          // semua SJ posted (utk Cetak SJ)
    $bulkResiIds   = $shipDeliveries->filter(fn ($d) => !empty($d->tracking_number))->pluck('id')->implode(','); // SJ ber-resi (utk Cetak Resi)
    $bulkGenIds    = $shipDeliveries->filter(fn ($d) => empty($d->tracking_number))->pluck('id')->implode(',');  // SJ belum resi (utk Generate Resi)

    $qtyRow = function ($ln) use ($fmtQty) {
        $isService = !empty($ln['is_service']);
        $shipped = (float) $ln['shipped'];
        $remaining = (float) $ln['remaining'];
        $nameCell = '<span class="flex-1 text-gray-700">' . e($ln['name'])
            . ($isService ? '<span class="ml-1 px-1.5 py-0.5 rounded text-[9px] font-bold bg-violet-100 text-violet-700 align-middle">JASA</span>' : '')
            . ($ln['sku'] ? '<span class="text-gray-400"> · ' . e($ln['sku']) . '</span>' : '') . '</span>';
        if ($isService) {
            // Jasa/non-stok: tampil di daftar tapi tak dikirim → kolom Dikirim/Belum kosong.
            return '<div class="flex items-start px-3 py-1.5 border-t border-gray-200">'
                . $nameCell
                . '<span class="w-16 text-right text-gray-700">' . $fmtQty($ln['ordered']) . '</span>'
                . '<span class="w-16 text-right text-gray-300">—</span>'
                . '<span class="w-16 text-right text-gray-300">—</span>'
                . '</div>';
        }
        return '<div class="flex items-start px-3 py-1.5 border-t border-gray-200">'
            . $nameCell
            . '<span class="w-16 text-right text-gray-700">' . $fmtQty($ln['ordered']) . '</span>'
            . '<span class="w-16 text-right ' . ($shipped > 0 ? 'text-green-600' : 'text-gray-400') . '">' . $fmtQty($shipped) . '</span>'
            . '<span class="w-16 text-right ' . ($remaining > 0 ? 'text-amber-600 font-semibold' : 'text-gray-400') . '">' . $fmtQty($remaining) . '</span>'
            . '</div>';
    };

    // Tandai kartu yang GAGAL diproses (perlu_diproses) dgn warna merah agar menonjol.
    $isFailed = $mode === 'perlu_diproses' && !empty($r['process_failed']);

    // Boleh diproses: lunas atau pesanan tempo (bayar belakangan memang kesepakatannya).
    // Tempo/tidaknya ditetapkan admin di form SO — bukan keputusan bagian packing.
    $canProcess = !empty($r['is_lunas']) || !empty($r['is_tempo']);
@endphp
<div data-so-number="{{ $r['number'] }}"
     class="bg-white rounded-xl border shadow-md hover:shadow-lg transition-shadow p-4 {{ $isFailed ? 'border-red-300 border-l-4 border-l-red-500' : 'border-gray-300 border-l-4 border-l-emerald-500' }}">
    {{-- Header: nomor + kurir/ambil-toko + status/batas waktu.
         Latarnya warna pekat supaya batas antar kartu terbaca sekali lihat di daftar panjang.
         Aturan kontras di dalamnya: chip tetap berlatar terang (pastel/putih) dengan tulisan
         gelap, teks lepas memakai putih. JANGAN memakai teks berwarna gelap (text-*-600) di
         sini — hilang tertelan latar. --}}
    <div class="flex items-center gap-2 flex-wrap -mx-4 -mt-4 px-4 py-2.5 rounded-t-xl border-b {{ $isFailed ? 'bg-red-600 border-red-700' : 'bg-emerald-600 border-emerald-700' }}">
        @if($mode === 'perlu_diproses')
            <input type="checkbox" class="js-bulk-check w-4 h-4 accent-indigo-600 cursor-pointer"
                   value="{{ $r['id'] }}" data-number="{{ $r['number'] }}"
                   {{-- "Boleh diproses", bukan "lunas": pesanan tempo juga lolos, jadi
                        peringatan massal tak boleh memakai status lunas. --}}
                   data-canprocess="{{ $canProcess ? 1 : 0 }}" data-pickup="{{ $r['is_pickup'] ? 1 : 0 }}"
                   data-failed="{{ !empty($r['process_failed']) ? 1 : 0 }}"
                   title="Pilih untuk aksi massal">
        @elseif($mode === 'telah_diproses' && empty($r['is_marketplace']))
            {{-- Aksi massal Telah Diproses (Cetak SJ/Generate Resi Biteship) tak berlaku utk marketplace. --}}
            <input type="checkbox" class="js-bulk-td w-4 h-4 accent-emerald-600 cursor-pointer"
                   value="{{ $r['id'] }}" data-number="{{ $r['number'] }}"
                   data-invoice="{{ $bulkInvoiceId }}" data-sj="{{ $bulkSjIds }}"
                   data-resi="{{ $bulkResiIds }}" data-gen="{{ $bulkGenIds }}"
                   title="Pilih untuk aksi massal">
        @elseif($mode === 'telah_diproses' && !empty($r['tracking_no']))
            {{-- Marketplace yang sudah ber-resi → aksi massal Cetak Resi gabungan (report Jubelio). --}}
            <input type="checkbox" class="js-bulk-td w-4 h-4 accent-emerald-600 cursor-pointer"
                   value="{{ $r['id'] }}" data-number="{{ $r['number'] }}"
                   data-mp="1" data-so="{{ $r['id'] }}"
                   title="Pilih untuk cetak resi massal">
        @endif
        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-blue-100 text-blue-700">SO</span>
        @if(!empty($r['is_marketplace']))
            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-purple-100 text-purple-700">🛒 {{ $r['channel'] }}</span>
        @endif
        @php
            // Nomor untuk disalin (lihat marketplace_copy_number): TikTok/Tokopedia ambil
            // bagian tengah di antara '-'; marketplace lain buang prefix; non-MP utuh.
            $copyNumber = marketplace_copy_number($r['number'], !empty($r['is_marketplace']));
        @endphp
        <span class="js-copy text-sm font-bold text-white cursor-pointer hover:text-white/70" data-copy="{{ $copyNumber }}" title="Klik untuk salin nomor (tanpa prefix channel)">{{ $r['number'] }}</span>
        @php
            // Untuk pesanan marketplace, tampilkan nama kurir Jubelio (mis. "J&T REG",
            // "Grab Instant") bila ada — lebih informatif daripada label generik "Kurir".
            $courierLabel = (!empty($r['is_marketplace']) && !empty($r['shipper']))
                ? $r['shipper']
                : ($r['delivery_display'] ?? null);
        @endphp
        @if(!empty($courierLabel))
            {{-- Ambil di toko ditebalkan setara ⚡ INSTANT: pembelinya menunggu di tempat,
                 jadi sama mendesaknya dengan kurir on-demand. --}}
            <span class="px-2 py-0.5 rounded text-[10px] {{ $r['is_pickup'] ? 'font-black bg-amber-100 text-amber-700 ring-1 ring-amber-300' : 'font-bold bg-sky-100 text-sky-700' }}"
                  @if($r['is_pickup']) title="Pembeli mengambil sendiri di toko — dahulukan" @endif>
                {{ $r['is_pickup'] ? '🏬' : '🚚' }} {{ $courierLabel }}
            </span>
        @endif
        @if(!empty($r['is_instant']) || !empty($r['j_is_instant']))
            <span class="px-2 py-0.5 rounded text-[10px] font-black bg-orange-100 text-orange-700 ring-1 ring-orange-300"
                  title="Pesanan instant courier — proses & serahkan ke kurir segera">⚡ INSTANT</span>
        @endif
        @if(!empty($r['is_draft']))
            <span class="px-2 py-0.5 rounded text-[10px] font-black bg-gray-200 text-gray-600"
                  title="Pesanan belum diposting — stok belum direservasi, belum bisa diproses">DRAFT</span>
        @endif
        @if(!empty($r['is_tempo']))
            {{-- Tempo boleh jalan tanpa uang masuk; yang lewat jatuh tempo dimerahkan supaya
                 piutangnya tidak tenggelam di antara pesanan lain. --}}
            @if(!empty($r['tempo_overdue']))
                <span class="px-2 py-0.5 rounded text-[10px] font-black bg-white text-red-700 ring-1 ring-red-300"
                      title="Sisa {{ rupiah($r['remaining']) }} belum dibayar">
                    📅 TEMPO LEWAT {{ abs($r['tempo_days_left']) }} HARI
                </span>
            @else
                <span class="px-2 py-0.5 rounded text-[10px] font-black bg-indigo-100 text-indigo-700 ring-1 ring-indigo-300"
                      title="Pembayaran tempo{{ $r['tempo_due_date'] ? ' — jatuh tempo ' . $r['tempo_due_date']->format('d/m/Y') : ' tanpa batas waktu' }}">
                    📅 TEMPO{{ $r['tempo_days'] ? ' ' . $r['tempo_days'] . ' HARI' : '' }}
                </span>
            @endif
        @endif
        @if($isFailed)
            <span class="px-2 py-0.5 rounded text-[10px] font-black bg-white text-red-700 ring-1 ring-red-300" title="{{ $r['process_error'] ?? '' }}">⚠ GAGAL PROSES</span>
        @endif
        @if(in_array($mode, ['belum_siap', 'belum_bayar', 'belum_lunas', 'perlu_ukur'], true) && !empty($r['reason']))
            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-gray-100 text-gray-700">⏳ {{ $r['reason'] }}</span>
        @elseif($isFinal)
            <span class="px-2 py-0.5 rounded text-[10px] font-black bg-white text-emerald-700 ring-1 ring-emerald-300">✓ SELESAI</span>
        @elseif($mode === 'dikirim')
            <span class="px-2 py-0.5 rounded text-[10px] font-black bg-white text-sky-700 ring-1 ring-sky-300">🚚 DIKIRIM</span>
        @elseif($mode === 'telah_diproses')
            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-green-100 text-green-700">✓ DIPROSES</span>
        @endif
        @if($mode === 'telah_diproses' && in_array($r['resi_state'] ?? null, ['belum_cetak', 'sudah_cetak'], true))
            {{-- Penanda status cetak resi (resi sudah di-generate) --}}
            @if(($r['resi_state'] ?? null) === 'sudah_cetak')
                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-700">🖨 Sudah dicetak</span>
            @else
                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-yellow-100 text-yellow-700">🖨 Belum dicetak</span>
            @endif
        @elseif($mode === 'telah_diproses' && ($r['resi_state'] ?? null) === 'belum_generate')
            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-orange-100 text-orange-700">⚙ Belum di-generate</span>
        @endif
        @if($isDone && ($pickupDone || $allDelivered))
            <span class="ml-auto text-xs whitespace-nowrap text-white font-semibold">
                ✓ {{ $pickupDone ? 'Sudah diambil' : 'Sudah sampai' }}
            </span>
        @elseif($isDone && $fullyShipped)
            <span class="ml-auto text-xs whitespace-nowrap text-white font-semibold">
                🚚 {{ $deliveredCount > 0 ? "Sampai {$deliveredCount}/{$shipCount}" : 'Di jalan' }}
            </span>
        @elseif($isDone && $partlyShipped)
            <span class="ml-auto text-xs whitespace-nowrap text-white font-semibold">
                🚚 Sebagian dikirim ({{ $resiCount }}/{{ $shipCount }})
            </span>
        @elseif($r['is_pickup'] && !empty($r['pickup_date']))
            @php $pickupOverdue = \Carbon\Carbon::parse($r['pickup_date'])->lt(\Carbon\Carbon::today()); @endphp
            {{-- Yang lewat batas naik jadi pil putih supaya tetap menyala di atas latar pekat. --}}
            <span class="ml-auto text-xs whitespace-nowrap {{ $pickupOverdue ? 'px-2 py-0.5 rounded bg-white text-red-700 font-bold' : 'text-white font-semibold' }}">
                🏬 Ambil {{ \Carbon\Carbon::parse($r['pickup_date'])->format('d M Y') }}{{ $pickupOverdue ? ' (lewat)' : '' }}
            </span>
        @elseif($deadline && !in_array($mode, ['dikirim', 'selesai'], true))
            {{-- Batas kirim disembunyikan di tab "Dikirim" & "Selesai": pesanan sudah diserahkan/selesai → tak relevan lagi. --}}
            @php
                // Marketplace: deadline punya jam (mis. 23:59) → tampilkan tgl+jam + hitungan mundur.
                // Non-marketplace: deadline tengah malam (00:00) → cukup tanggal seperti semula.
                $hasTime = $deadline->format('H:i') !== '00:00';
                $mins = (int) \Carbon\Carbon::now()->diffInMinutes($deadline, false); // <0 = sudah lewat
                $abs  = abs($mins);
                $cd   = $abs >= 1440 ? intdiv($abs, 1440) . ' hari'
                      : ($abs >= 60 ? intdiv($abs, 60) . ' jam' : max(1, $abs) . ' mnt');
                $countdown = $r['is_overdue'] ? "lewat {$cd}" : "{$cd} lagi";
                $urgent = !$r['is_overdue'] && $mins <= 720; // < 12 jam → tandai mendesak
                // Lewat batas & mendesak dinaikkan jadi pil (putih / kuning) — teks berwarna
                // gelap tak terbaca di atas latar header yang pekat.
                $cls = $r['is_overdue'] ? 'px-2 py-0.5 rounded bg-white text-red-700 font-bold'
                     : ($urgent ? 'px-2 py-0.5 rounded bg-amber-200 text-amber-900 font-bold' : 'text-white font-semibold');
            @endphp
            @if($hasTime)
                <span class="ml-auto text-xs whitespace-nowrap {{ $cls }}" title="Batas kirim {{ $deadline->format('d M Y H:i') }} WIB">
                    ⏱ Batas kirim {{ $deadline->format('d M') }} {{ $deadline->format('H:i') }} · {{ $countdown }}
                </span>
            @else
                <span class="ml-auto text-xs whitespace-nowrap {{ $r['is_overdue'] ? 'px-2 py-0.5 rounded bg-white text-red-700 font-bold' : 'text-white font-semibold' }}">
                    ⏱ Batas kirim {{ $deadline->format('d M Y') }}{{ $r['is_overdue'] ? ' (lewat)' : '' }}
                </span>
            @endif
        @endif
    </div>

    @if($isFailed && !empty($r['process_error']))
        {{-- Alasan gagal proses (agar operator tahu apa yang harus diperbaiki sebelum proses ulang) --}}
        <div class="-mx-4 px-4 py-2 bg-red-50 border-b border-red-100 text-xs text-red-700 flex items-start gap-1.5">
            <span class="shrink-0">⚠</span>
            <span><span class="font-semibold">Gagal diproses:</span> {{ $r['process_error'] }}</span>
        </div>
    @endif

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
    @if($mode === 'perlu_diproses' && !empty($r['is_marketplace']))
        {{-- Marketplace: satu tombol Proses menjalankan rantai Jubelio (picking → faktur → resi). --}}
        <div class="mt-3 flex items-center gap-2 flex-wrap border-t border-gray-50 pt-3">
            <a href="{{ route('sales.orders.print', $r['id']) }}"
               class="text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">🖨 Cetak SO</a>
            @if(($r['wms_stage'] ?? 'belum') !== 'belum')
                <span class="text-[11px] px-2 py-0.5 rounded bg-purple-50 text-purple-700 font-semibold">⏳ {{ $r['wms_stage_label'] }} — bisa lanjutkan</span>
            @endif
            <div class="ml-auto flex items-center gap-2 flex-wrap justify-end">
                <form action="{{ route('pos.fulfillment.proses', $r['id']) }}" method="POST"
                      onsubmit="return confirm('Jalankan proses Jubelio untuk {{ $r['number'] }}? (picking → faktur → resi otomatis)')">
                    @csrf
                    <button type="submit" name="print_after" value="0"
                            class="px-3 py-1.5 rounded text-xs font-bold text-white bg-purple-600 hover:bg-purple-700">🛒 Proses Pesanan</button>
                </form>
            </div>
        </div>
        @if(!empty($r['wms_error']))
            <p class="text-[11px] text-red-600 mt-1 text-right">Gagal sebelumnya: {{ $r['wms_error'] }}</p>
        @endif

    @elseif($mode === 'perlu_diproses')
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
                    <button type="submit" name="print_after" value="0" {{ $canProcess ? '' : 'disabled' }}
                            class="px-3 py-1.5 rounded text-xs font-bold text-white {{ $canProcess ? 'bg-green-600 hover:bg-green-700' : 'bg-gray-300 cursor-not-allowed' }}">✅ Proses</button>
                </form>
            </div>
        </div>
        @if(!empty($r['measured_at']))
            {{-- Ukuran yang akan dipakai saat resi terbit — ditampilkan supaya salah timbang
                 masih bisa ketahuan sebelum pesanan diproses. --}}
            @php $m = $r['measure'] ?? []; @endphp
            <div class="mt-1 flex items-center justify-end gap-2 flex-wrap">
                <span class="text-[11px] text-gray-500 bg-gray-50 border border-gray-200 rounded px-2 py-0.5">
                    📏 {{ $m['weight_gram'] ? number_format($m['weight_gram'], 0, ',', '.') . ' g' : 'berat belum diisi' }}
                    @if($m['length'] ?? null) · {{ rtrim(rtrim(number_format($m['length'], 1, ',', '.'), '0'), ',') }}×{{ rtrim(rtrim(number_format($m['width'] ?? 0, 1, ',', '.'), '0'), ',') }}×{{ rtrim(rtrim(number_format($m['height'] ?? 0, 1, ',', '.'), '0'), ',') }} cm @endif
                </span>
                <form action="{{ route('pos.fulfillment.batal-ukur', $r['id']) }}" method="POST"
                      onsubmit="return confirm('Ukur ulang {{ $r['number'] }}? Pesanan kembali ke Perlu Ukur.')">
                    @csrf
                    <button type="submit" class="text-[11px] text-gray-500 hover:text-indigo-600 font-semibold">Ukur ulang</button>
                </form>
            </div>
        @endif
        @if(!$r['is_lunas'] && !empty($r['is_tempo']))
            <p class="text-[11px] text-indigo-600 mt-1 text-right">
                Tempo — boleh dikirim dulu, sisa {{ rupiah($r['remaining']) }} ditagih
                {{ $r['tempo_due_date'] ? 'sampai ' . $r['tempo_due_date']->format('d/m/Y') : 'belakangan' }}.
            </p>
        @elseif(!$r['is_lunas'])
            <p class="text-[11px] text-amber-600 mt-1 text-right">Belum lunas — selesaikan pembayaran dulu untuk memproses.</p>
        @endif

    @elseif($mode === 'belum_bayar' || $mode === 'belum_lunas')
        <div class="mt-3 flex items-center gap-2 flex-wrap border-t border-gray-50 pt-3">
            <a href="{{ route('sales.orders.show', $r['id']) }}"
               class="text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">👁 Buka SO</a>
            <a href="{{ route('sales.orders.print', $r['id']) }}"
               class="text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">🖨 Cetak SO</a>

            {{-- Draft belum bisa ditagih (belum diposting); marketplace dibayar di channel-nya. --}}
            @if(empty($r['is_draft']) && empty($r['is_marketplace']))
                <a href="{{ route('sales.payment.create', ['customer_id' => $r['customer_id'], 'so_id' => $r['id'], 'mode' => 'uang_muka']) }}"
                   class="text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">💵 Cash</a>
                <button type="button" onclick="window._midtransOpenSo({{ $r['id'] }})"
                        class="text-xs px-3 py-1.5 rounded border border-indigo-300 text-indigo-700 hover:bg-indigo-50 font-semibold">💳 Link</button>
                <button type="button" onclick="window._midtransOpenSoQris({{ $r['id'] }})"
                        class="text-xs px-3 py-1.5 rounded border border-emerald-300 text-emerald-700 hover:bg-emerald-50 font-semibold">QRIS</button>
            @endif
        </div>
        @if($mode === 'belum_lunas')
            <p class="text-[11px] text-gray-400 mt-1 text-right">
                Otomatis pindah ke "Siap Proses" begitu pelunasan masuk.
            </p>
        @endif

    @elseif($mode === 'perlu_ukur')
        {{-- Timbang & ukur kardus setelah dipacking. Kolom sudah terisi ukuran yang tersimpan di
             SO, atau taksiran dari master produk bila SO belum punya — operator tinggal
             mengoreksi. Yang menandai "sudah diukur" adalah tombolnya, bukan terisinya angka,
             jadi yang menilai taksirannya sudah benar cukup menekan Simpan. --}}
        @php $m = $r['measure'] ?? []; @endphp
        <form action="{{ route('pos.fulfillment.ukur', $r['id']) }}" method="POST"
              class="mt-3 border-t border-gray-50 pt-3 flex items-end gap-2 flex-wrap">
            @csrf
            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase mb-0.5">Berat (gram)</label>
                <input type="number" name="weight_gram" value="{{ $m['weight_gram'] ?? '' }}" min="0" step="1"
                       class="border rounded px-2.5 py-1.5 text-xs w-28 font-mono" placeholder="0">
            </div>
            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase mb-0.5">P × L × T (cm)</label>
                <div class="flex items-center gap-1">
                    <input type="number" name="package_length" value="{{ $m['length'] ?? '' }}" min="0" step="0.1"
                           class="border rounded px-2 py-1.5 text-xs w-16 font-mono" placeholder="P">
                    <span class="text-gray-300 text-xs">×</span>
                    <input type="number" name="package_width" value="{{ $m['width'] ?? '' }}" min="0" step="0.1"
                           class="border rounded px-2 py-1.5 text-xs w-16 font-mono" placeholder="L">
                    <span class="text-gray-300 text-xs">×</span>
                    <input type="number" name="package_height" value="{{ $m['height'] ?? '' }}" min="0" step="0.1"
                           class="border rounded px-2 py-1.5 text-xs w-16 font-mono" placeholder="T">
                </div>
            </div>
            <button type="submit"
                    class="px-3 py-1.5 rounded text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700">📏 Simpan Ukuran</button>
            <a href="{{ route('sales.orders.print', $r['id']) }}"
               class="text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">🖨 Cetak SO</a>
            <span class="ml-auto text-[11px] text-gray-400 italic">
                Ongkir pesanan tidak diubah — selisihnya masuk titipan ongkir.
            </span>
        </form>

    @elseif($mode === 'belum_siap')
        <div class="mt-3 flex items-center gap-2 flex-wrap border-t border-gray-50 pt-3">
            <a href="{{ route('sales.orders.print', $r['id']) }}"
               class="text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">🖨 Cetak SO</a>
            {{-- Marketplace: pembayaran terjadi di channel, BUKAN via ERP. Jangan tawarkan
                 Cash/Link/QRIS — DP otomatis diposting saat Jubelio melaporkan pesanan dibayar
                 (manual pay di sini akan dobel dengan DP marketplace). --}}
            @if(empty($r['is_marketplace']))
                @unless($r['is_lunas'])
                    <a href="{{ route('sales.payment.create', ['customer_id' => $r['customer_id'], 'so_id' => $r['id'], 'mode' => 'uang_muka']) }}"
                       class="text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">💵 Cash</a>
                    <button type="button" onclick="window._midtransOpenSo({{ $r['id'] }})"
                            class="text-xs px-3 py-1.5 rounded border border-indigo-300 text-indigo-700 hover:bg-indigo-50 font-semibold">💳 Link</button>
                    <button type="button" onclick="window._midtransOpenSoQris({{ $r['id'] }})"
                            class="text-xs px-3 py-1.5 rounded border border-emerald-300 text-emerald-700 hover:bg-emerald-50 font-semibold">QRIS</button>
                @endunless
            @endif
            <span class="ml-auto text-[11px] text-gray-400 italic">
                {{ !empty($r['is_marketplace'])
                    ? 'Stok sudah ter-reserve. Otomatis pindah ke "Perlu Diproses" saat dibayar di marketplace.'
                    : 'Otomatis pindah ke "Perlu Diproses" saat syarat terpenuhi.' }}
            </span>
        </div>

    @elseif($isDone && !empty($r['is_marketplace']))
        {{-- Marketplace: cetak resi & faktur dari Jubelio (label resmi kurir marketplace). --}}
        <div class="mt-3 flex items-center gap-2 flex-wrap border-t border-gray-50 pt-3">
            <a href="{{ route('sales.orders.print', $r['id']) }}"
               class="text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">🖨 Cetak SO</a>
            @if($mode === 'telah_diproses' && empty($r['tracking_no']))
                {{-- Resi marketplace terbit ASYNC: Shopee merilis nomor resi saat paket siap kirim,
                     lalu cron jubelio:sync-orders menariknya otomatis. Tombol "Generate Ulang"
                     (mengulang rantai Proses) sengaja DIHAPUS — pada order yang sudah tuntas di
                     Jubelio, mengulang justru ditolak Jubelio & bikin operator salah paham. Cukup tunggu. --}}
                <span class="text-xs px-3 py-1.5 rounded bg-sky-50 border border-sky-200 text-sky-700 font-medium inline-flex items-center gap-1.5"
                      title="Nomor resi diterbitkan marketplace lalu ditarik otomatis oleh sistem — tidak perlu aksi manual.">
                    ⏳ Resi belum di-generate — tunggu beberapa saat, resi akan di-generate otomatis.
                </span>
            @else
                <a href="{{ route('pos.fulfillment.jubelio-resi', $r['id']) }}"
                   class="text-xs px-3 py-1.5 rounded border border-purple-300 text-purple-700 hover:bg-purple-50 font-semibold">🏷️ Cetak Resi</a>
            @endif
            <a href="{{ route('pos.fulfillment.jubelio-faktur', $r['id']) }}"
               class="text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">🧾 Cetak Faktur</a>
            @if(!empty($r['tracking_no']))
                <span class="text-xs text-green-600 font-semibold ml-1">
                    Resi: <span class="js-copy cursor-pointer hover:underline" data-copy="{{ $r['tracking_no'] }}" title="Klik untuk salin resi">{{ $r['tracking_no'] }}</span>
                    @if(!empty($r['shipper']))<span class="text-gray-500 font-normal">· {{ $r['shipper'] }}</span>@endif
                </span>
                {{-- Toggle manual status cetak --}}
                <form action="{{ route('pos.fulfillment.toggle-printed', $r['id']) }}" method="POST" class="ml-auto">
                    @csrf
                    <button type="submit"
                            class="text-[11px] px-2 py-1 rounded border {{ !empty($r['resi_printed']) ? 'border-emerald-300 text-emerald-700 hover:bg-emerald-50' : 'border-gray-300 text-gray-500 hover:bg-gray-50' }} font-semibold"
                            title="Tandai/batal status cetak resi">
                        {{ !empty($r['resi_printed']) ? '✓ Sudah dicetak' : 'Tandai dicetak' }}
                    </button>
                </form>
            @endif
        </div>

    @elseif($isDone)
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
                            {{-- Penanda sampai: yang memindahkan pesanan dari "Dikirim" ke "Selesai".
                                 Biasanya terisi SENDIRI dari webhook Jubelio (status DELIVERED);
                                 tombolnya jalan manual bila status kurir tak kunjung datang.
                                 Ambil di toko tidak punya paket yang ditunggu → tak perlu tombol ini. --}}
                            @if($d->delivery_method !== 'ambil_toko')
                                @if($d->delivered_at)
                                    <span class="px-2 py-1 rounded bg-emerald-50 border border-emerald-200 text-emerald-700 font-semibold"
                                          title="Ditandai sampai {{ $d->delivered_at->format('d/m/Y H:i') }}{{ $d->delivered_by ? '' : ' — otomatis dari status kurir' }}">
                                        ✓ Sampai {{ $d->delivered_at->format('d/m') }}{{ $d->delivered_by ? '' : ' ⚡' }}
                                    </span>
                                    <form action="{{ route('pos.fulfillment.batal-sampai', $d->id) }}" method="POST"
                                          onsubmit="return confirm('Tarik kembali penandaan sampai {{ $d->delivery_number }}?')">
                                        @csrf
                                        <button type="submit" class="px-2 py-1 text-gray-400 hover:text-red-600 font-semibold">batal</button>
                                    </form>
                                @else
                                    <form action="{{ route('pos.fulfillment.sampai', $d->id) }}" method="POST"
                                          onsubmit="return confirm('Tandai {{ $d->delivery_number }} sudah sampai di pembeli?')">
                                        @csrf
                                        <button type="submit"
                                                class="px-2.5 py-1 rounded border border-emerald-300 text-emerald-700 hover:bg-emerald-50 font-semibold">
                                            📦 Sudah Sampai
                                        </button>
                                    </form>
                                @endif
                            @endif
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
