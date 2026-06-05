@if($so->delivery_method === 'ambil_toko')
@php $canConfirm = $so->status === 'confirmed' && $so->pickup_status !== 'picked_up' && $so->pickup_code; @endphp
<div class="mt-4">
    <div class="bg-white border border-gray-100 rounded-lg shadow-sm p-4">
        {{-- Satu baris full: booking code · verifikasi · tanggal pengambilan · status --}}
        <div class="flex items-end gap-x-6 gap-y-3 flex-wrap">
            {{-- Booking code --}}
            <div>
                <div class="text-[11px] font-bold text-gray-400 uppercase tracking-widest">🏬 Ambil di Toko — Booking Code</div>
                @if($so->pickup_code)
                    <div class="text-2xl font-mono font-extrabold text-gray-800 tracking-widest mt-0.5 leading-none">{{ $so->pickup_code }}</div>
                @else
                    <div class="text-sm text-gray-400 italic mt-1">Kode terbit otomatis setelah SO di-post.</div>
                @endif
            </div>

            {{-- Verifikasi booking code (konfirmasi pengambilan) --}}
            @if($canConfirm)
                <form action="{{ route('sales.orders.pickup', $so->id) }}" method="POST"
                      class="flex items-end gap-2"
                      onsubmit="return confirm('Konfirmasi barang diambil pelanggan? Surat Jalan akan terbit & stok berkurang.')">
                    @csrf
                    <div>
                        <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Verifikasi Booking Code</label>
                        <input type="text" name="pickup_code" required placeholder="1234" autocomplete="off"
                               inputmode="numeric" maxlength="5"
                               class="border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono tracking-widest w-40 focus:ring-2 focus:ring-purple-400 outline-none">
                    </div>
                    <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white px-5 py-2 rounded-lg text-sm font-bold whitespace-nowrap">
                        Konfirmasi Pengambilan
                    </button>
                </form>
            @endif

            {{-- Tanggal pengambilan + status — didorong ke kanan --}}
            <div class="flex items-end gap-3 flex-wrap ml-auto">
                @if($so->pickup_status === 'picked_up')
                    @if($so->pickup_date)
                        <div class="text-right">
                            <div class="text-[11px] font-bold text-gray-400 uppercase tracking-widest">Tanggal Pengambilan</div>
                            <div class="text-sm font-semibold text-gray-800">📅 {{ \Carbon\Carbon::parse($so->pickup_date)->format('d M Y') }}</div>
                        </div>
                    @endif
                    <span class="px-3 py-1 rounded-full text-xs font-bold bg-green-100 text-green-700 whitespace-nowrap">
                        ✓ Sudah Diambil{{ $so->picked_up_at ? ' · ' . $so->picked_up_at->format('d/m/Y H:i') : '' }}
                    </span>
                @else
                    {{-- Tanggal pengambilan bisa diedit langsung di sini --}}
                    <form action="{{ route('sales.orders.pickup-date', $so->id) }}" method="POST">
                        @csrf
                        <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Tanggal Pengambilan</label>
                        <div class="flex items-center gap-1.5">
                            <input type="date" name="pickup_date"
                                   value="{{ $so->pickup_date ? \Carbon\Carbon::parse($so->pickup_date)->format('Y-m-d') : '' }}"
                                   class="border border-gray-300 rounded-lg px-2.5 py-1.5 text-sm focus:ring-2 focus:ring-purple-400 outline-none">
                            <button type="submit" class="bg-gray-700 hover:bg-gray-800 text-white px-3 py-1.5 rounded-lg text-xs font-bold">Simpan</button>
                        </div>
                    </form>
                    @if($so->pickup_code)
                        <span class="px-3 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-700 whitespace-nowrap">Menunggu Diambil</span>
                    @endif
                @endif
            </div>
        </div>

        @if($canConfirm)
            <p class="text-[11px] text-gray-400 mt-2">Masukkan kode yang disebutkan pelanggan. Sistem akan menerbitkan Surat Jalan & memotong stok.</p>
        @endif
    </div>
</div>
@endif
