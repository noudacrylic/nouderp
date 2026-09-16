{{--
    Isi form cabang — dipakai bersama oleh create & edit.
    `$cabang` null saat menambah.
--}}
@csrf

<div class="grid grid-cols-2 gap-4">

    <div class="col-span-2">
        <label class="block text-sm mb-1">Nama Cabang <span class="text-red-500">*</span></label>
        <input type="text" name="name" value="{{ old('name', $cabang?->name ?? '') }}"
            class="border rounded px-3 py-2 w-full" required
            placeholder="{{ $customer->name }} - Cabang ">
        <p class="text-xs text-gray-400 mt-1">
            Nama ini yang <b>dicetak di nota</b>. Tulis utuh bersama induknya, mis.
            &ldquo;{{ $customer->name }} &ndash; Cabang Bandung&rdquo; &mdash; kartu piutangnya atas nama
            {{ $customer->name }}, jadi nama yang utuh membuat keduanya langsung nyambung saat dicocokkan.
        </p>
    </div>

    <div>
        <label class="block text-sm mb-1">Nama PIC <span class="text-gray-400 font-normal">(opsional)</span></label>
        <input type="text" name="pic_name" value="{{ old('pic_name', $cabang?->pic_name ?? '') }}"
            class="border rounded px-3 py-2 w-full" placeholder="Orang yang menerima barang">
    </div>

    <div>
        <label class="block text-sm mb-1">No. WhatsApp Cabang</label>
        <input type="text" name="phone" value="{{ old('phone', $cabang?->phone ?? '') }}"
            class="border rounded px-3 py-2 w-full">
        <p class="text-xs text-gray-400 mt-1">
            Nomor yang dikabari untuk pesanan cabang ini. Dikosongkan = ikut nomor utama {{ $customer->name }}.
        </p>
    </div>

    <div class="col-span-2">
        <p class="text-xs text-gray-500 bg-gray-50 border rounded px-3 py-2 leading-relaxed">
            Tagihan tetap ke <b>{{ $customer->name }}</b>. Cabang hanya menentukan
            ke mana barang dikirim dan siapa yang dikabari.
        </p>
    </div>

    @include('erp.master.customers._shipping_fields', [
        'customer'   => $cabang,
        'judul'        => 'Alamat Kirim Cabang',
        'keterangan'   => 'Alamat ini yang dicetak di nota & dipakai menghitung ongkir untuk pesanan cabang ini.',
        'hintPenerima' => 'Diberikan ke kurir & dicetak di label. Dikosongkan = pakai No. WhatsApp Cabang di atas, lalu nomor pusat.',
    ])

</div>
