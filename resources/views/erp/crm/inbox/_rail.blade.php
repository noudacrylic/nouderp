{{-- Kolom KANAN: alat bantu membalas.

     Info & Template DIBUANG dari sini (10 Sep 2026). Triase (label, pemilik)
     sudah hidup di kolom kiri; catatan, arsip, dan unduh lampiran pindah ke
     menu titik tiga di kepala percakapan. Template pindah ke kotak ketik:
     tekan "/" seperti di WhatsApp — potongan balasan dibutuhkan SAAT mengetik,
     bukan setelah pindah tangan ke rail. --}}
{{-- Tabnya bisa dipindahkan dari dalam panel: "Tambahkan ke Pesanan" di tab
     Ongkir mengantar operator ke tab Pesanan, karena itu memang langkah
     berikutnya dan menyuruhnya mengklik tab sendiri cuma satu langkah sia-sia. --}}
{{-- $gayaWadah: di desktop ia kartu bersisi di kolom kanan; di PWA CRM ia isi
     lembar geser yang sudah punya bingkainya sendiri. --}}
@php $gayaWadah = $gayaWadah ?? 'bg-white border border-gray-200 rounded-lg'; @endphp
<div class="flex flex-col h-full min-h-0 overflow-hidden {{ $gayaWadah }}"
     x-data="{ tab: 'produk' }"
     x-init="$watch('tab', t => window.dispatchEvent(new CustomEvent('tab-rail', { detail: { tab: t } })))"
     @buka-tab.window="tab = $event.detail.tab">

    <div class="shrink-0 flex gap-1 px-2 py-2 border-b border-gray-200 bg-gray-50 text-xs">
        {{-- Produk kembali ke rail (7 Sep 2026): di menu klip ia cuma jadi
             pengirim tautan, padahal yang paling sering dibutuhkan justru
             MEMBACA — stok dan harga — sambil mengetik balasan. --}}
        @foreach(['produk' => 'Produk', 'ongkir' => 'Ongkir', 'pesanan' => 'Pesanan'] as $key => $label)
            <button type="button" @click="tab = '{{ $key }}'"
                    :class="tab === '{{ $key }}' ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-gray-300 bg-white hover:bg-gray-50'"
                    class="px-2 py-1 rounded border">{{ $label }}</button>
        @endforeach
    </div>

    <div class="flex-1 min-h-0 overflow-y-auto p-2 space-y-2">

        {{-- ---------------------------------------------------------- produk --}}
        <div x-show="tab === 'produk'">
            @include('erp.crm.inbox._produk')
        </div>

        {{-- ---------------------------------------------------------- ongkir --}}
        {{-- Dirender langsung, bukan lewat x-if: autocomplete wilayah mengikat
             elemennya lewat id saat halaman dimuat, jadi panel yang baru lahir
             saat tab diklik tidak akan pernah terpasang. Tidak ada permintaan
             jaringan yang jalan sampai ada yang mengetik, jadi murah. --}}
        <div x-show="tab === 'ongkir'" x-cloak>
            @include('erp.crm.inbox._ongkir')
        </div>

        {{-- --------------------------------------------------------- pesanan --}}
        <div x-show="tab === 'pesanan'" x-cloak>
            @if($terpilih)
                @include('erp.crm.inbox._pesanan')
            @else
                <p class="text-sm text-gray-500 px-1">Pilih percakapan untuk menyusun pesanan.</p>
            @endif
        </div>
    </div>
</div>
