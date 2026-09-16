{{--
    Isi form pelanggan — dipakai bersama create & edit, pola yang sama dengan
    form Pemasok. `$customer` null saat menambah.
--}}
@csrf

@isset($customer)
    <div class="text-sm text-gray-500 mb-3">
        Kode: <span class="font-semibold text-gray-700">{{ $customer->code }}</span> <span class="text-xs">(otomatis)</span>
    </div>
@endisset

<div class="grid grid-cols-2 gap-4">

    <div class="col-span-2">
        <label class="block text-sm mb-1">Nama <span class="text-red-500">*</span></label>
        <input type="text" name="name" value="{{ old('name', $customer->name ?? '') }}"
            class="border rounded px-3 py-2 w-full" required>
    </div>

    <div>
        <label class="block text-sm mb-1">No. WhatsApp Utama</label>
        <input type="text" name="phone" value="{{ old('phone', $customer->phone ?? '') }}"
            class="border rounded px-3 py-2 w-full">
        <p class="text-xs text-gray-400 mt-1">
            Nomor yang dikabari soal pesanan &amp; pembayaran, sekaligus penanda pelanggan di kotak cari.
        </p>
    </div>

    <div>
        <label class="block text-sm mb-1">Email</label>
        <input type="email" name="email" value="{{ old('email', $customer->email ?? '') }}"
            class="border rounded px-3 py-2 w-full">
    </div>

    <div class="col-span-2">
        <label class="flex items-start gap-2 text-sm text-gray-700">
            <input type="checkbox" name="wa_opt_out" value="1" class="mt-1"
                @checked(old('wa_opt_out', isset($customer) && $customer->wa_opt_out_at !== null))>
            <span>
                Jangan kirim notifikasi pesanan lewat WhatsApp
                @if (isset($customer) && $customer->wa_opt_out_at)
                    <span class="block text-xs text-gray-500">
                        Menyatakan keberatan {{ $customer->wa_opt_out_at->translatedFormat('d M Y H:i') }}
                    </span>
                @elseif (isset($customer) && $customer->wa_opt_in_at)
                    <span class="block text-xs text-gray-500">
                        Menyetujui {{ $customer->wa_opt_in_at->translatedFormat('d M Y H:i') }}
                        @if ($customer->wa_opt_in_source) &middot; {{ str_replace('_', ' ', $customer->wa_opt_in_source) }} @endif
                    </span>
                @else
                    <span class="block text-xs text-gray-500">
                        Bawaannya pelanggan DIKABARI soal pesanannya sendiri (pembayaran, siap diambil, nomor resi).
                        Centang ini hanya bila ia menyatakan keberatan.
                    </span>
                @endif
            </span>
        </label>
    </div>

    <div>
        <label class="block text-sm mb-1">Tipe Pelanggan</label>
        <select name="customer_type" class="border rounded px-3 py-2 w-full">
            @foreach (['regular' => 'Regular', 'marketplace' => 'Marketplace', 'reseller' => 'Reseller'] as $nilai => $label)
                <option value="{{ $nilai }}" @selected(old('customer_type', $customer->customer_type ?? 'regular') === $nilai)>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div></div>

    {{--
        Nomor tambahan yang IKUT dikabari. Satu perusahaan sering ditangani
        beberapa orang berbeda posisi — purchasing mengurus tagihan, tim
        lapangan menunggu barang — dan keduanya perlu kabar yang sama.
    --}}
    <div class="col-span-2 border-t pt-5 mt-1">
        <h2 class="text-sm font-bold text-gray-700 mb-1">Nomor Notifikasi Tambahan</h2>
        <p class="text-xs text-gray-400 mb-3">
            Nomor di sini <b>ikut menerima semua kabar</b> bersama No. WhatsApp Utama &mdash; untuk perusahaan
            yang orangnya beda-beda posisi. Kabar &ldquo;Jatuh Tempo&rdquo; lewat jalur resmi berbayar,
            jadi tiap nomor tambahan menambah ongkos untuk jenis itu.
        </p>

        <div id="nomorNotifikasiList" class="space-y-2">
            @php
                $daftarNomor = old('nomor_notifikasi', isset($customer)
                    ? $customer->notificationPhones->map(fn ($n) => ['label' => $n->label, 'phone' => $n->phone])->all()
                    : []);
            @endphp

            @foreach ($daftarNomor as $i => $baris)
                <div class="flex gap-2 items-start" data-baris-nomor>
                    <input type="text" name="nomor_notifikasi[{{ $i }}][label]" value="{{ $baris['label'] ?? '' }}"
                        class="border rounded px-3 py-2 w-1/3" placeholder="Posisi / nama (mis. Purchasing)">
                    <input type="text" name="nomor_notifikasi[{{ $i }}][phone]" value="{{ $baris['phone'] ?? '' }}"
                        class="border rounded px-3 py-2 flex-1" placeholder="08xxxxxxxxxx">
                    <button type="button" class="px-3 py-2 text-sm text-red-600 hover:underline" data-hapus-nomor>Hapus</button>
                </div>
            @endforeach
        </div>

        <button type="button" id="tambahNomorNotifikasi"
            class="mt-2 text-sm border border-blue-600 text-blue-600 hover:bg-blue-50 px-3 py-1.5 rounded">
            + Tambah Nomor
        </button>
    </div>

    <div class="col-span-2">
        <label class="block text-sm mb-1">Alamat Penagihan <span class="text-gray-400 font-normal">(umum)</span></label>
        <textarea name="address" rows="2" class="border rounded px-3 py-2 w-full"
            placeholder="Alamat umum / penagihan pelanggan">{{ old('address', $customer->address ?? '') }}</textarea>
    </div>

    @include('erp.master.customers._shipping_fields', ['customer' => $customer ?? null])

</div>

<div class="mt-6 flex gap-2">
    <button class="bg-blue-600 text-white px-4 py-2 rounded">Simpan</button>
    <a href="{{ list_url('customers.index') }}" class="px-4 py-2 border rounded">Batal</a>
</div>

<script>
(function () {
    const daftar = document.getElementById('nomorNotifikasiList');
    const tombol = document.getElementById('tambahNomorNotifikasi');
    if (!daftar || !tombol) return;

    // Penomoran name[] diteruskan dari yang terakhir, bukan dari jumlah baris:
    // menghapus baris tengah lalu menambah baru akan menghasilkan indeks kembar
    // kalau dihitung dari jumlah, dan satu nomor diam-diam menimpa nomor lain.
    let urut = daftar.querySelectorAll('[data-baris-nomor]').length;

    tombol.addEventListener('click', function () {
        const baris = document.createElement('div');
        baris.className = 'flex gap-2 items-start';
        baris.setAttribute('data-baris-nomor', '');
        baris.innerHTML =
            '<input type="text" name="nomor_notifikasi[' + urut + '][label]" class="border rounded px-3 py-2 w-1/3" placeholder="Posisi / nama (mis. Purchasing)">' +
            '<input type="text" name="nomor_notifikasi[' + urut + '][phone]" class="border rounded px-3 py-2 flex-1" placeholder="08xxxxxxxxxx">' +
            '<button type="button" class="px-3 py-2 text-sm text-red-600 hover:underline" data-hapus-nomor>Hapus</button>';
        daftar.appendChild(baris);
        urut++;
    });

    daftar.addEventListener('click', function (e) {
        if (e.target.matches('[data-hapus-nomor]')) {
            e.target.closest('[data-baris-nomor]').remove();
        }
    });
})();
</script>
