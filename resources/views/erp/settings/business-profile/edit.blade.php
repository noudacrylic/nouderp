@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-lg font-semibold">Profil Bisnis</h1>
        <div class="text-xs text-gray-500 mt-0.5">Data perusahaan yang dipakai di kop surat dokumen (Quotation, Invoice, dll).</div>
    </div>
</div>


<form method="POST" action="{{ route('settings.business-profile.update') }}" enctype="multipart/form-data">
    @csrf

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {{-- KIRI: Identitas + Alamat + Kontak + Bank + Signer --}}
        <div class="lg:col-span-2 space-y-4">
            <div class="bg-white rounded shadow p-4">
                <h2 class="font-semibold text-sm mb-3">Identitas & Alamat</h2>
                <div class="grid grid-cols-2 gap-3">
                    <div class="col-span-2">
                        <label class="block text-xs text-gray-500 mb-1">Nama Bisnis</label>
                        <input type="text" name="name" value="{{ old('name', $profile->name) }}" class="w-full border rounded px-2 py-1.5" placeholder="Mis. Noud Acrylic Shop">
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs text-gray-500 mb-1">Alamat</label>
                        <textarea name="address" rows="2" class="w-full border rounded px-2 py-1.5" placeholder="Jl. Suren Raya No. 25A, Padangsari, Kec. Banyumanik">{{ old('address', $profile->address) }}</textarea>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Kota</label>
                        <input type="text" name="city" value="{{ old('city', $profile->city) }}" class="w-full border rounded px-2 py-1.5" placeholder="Mis. Semarang">
                        <p class="text-xs text-gray-400 mt-1">Dipakai utk format "Kota, Tanggal" di kop surat.</p>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Kode Pos</label>
                        <input type="text" name="postal_code" value="{{ old('postal_code', $profile->postal_code) }}" class="w-full border rounded px-2 py-1.5">
                    </div>
                </div>
            </div>

            <div class="bg-white rounded shadow p-4">
                <h2 class="font-semibold text-sm mb-3">Kontak</h2>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Telepon</label>
                        <input type="text" name="phone" value="{{ old('phone', $profile->phone) }}" class="w-full border rounded px-2 py-1.5">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">WhatsApp</label>
                        <input type="text" name="whatsapp" value="{{ old('whatsapp', $profile->whatsapp) }}" class="w-full border rounded px-2 py-1.5">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Email</label>
                        <input type="email" name="email" value="{{ old('email', $profile->email) }}" class="w-full border rounded px-2 py-1.5">
                    </div>
                </div>
            </div>

            <div class="bg-white rounded shadow p-4"
                 x-data="{
                    banks: @js(old('banks', $profile->bankAccounts->map(fn($b) => [
                        'bank_name' => $b->bank_name,
                        'account_number' => $b->account_number,
                        'holder' => $b->holder,
                    ])->values()->all())),
                    add() { this.banks.push({ bank_name:'', account_number:'', holder:'' }); },
                    remove(i) { this.banks.splice(i, 1); }
                 }">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="font-semibold text-sm">Rekening Bank (untuk info pembayaran di dokumen)</h2>
                    <button type="button" @click="add()" class="text-xs bg-blue-600 text-white px-2.5 py-1 rounded hover:bg-blue-700">
                        + Tambah Rekening
                    </button>
                </div>

                <template x-if="banks.length === 0">
                    <div class="border-2 border-dashed rounded p-4 text-center text-gray-400 text-xs italic">
                        Belum ada rekening. Klik "Tambah Rekening" untuk menambahkan.
                    </div>
                </template>

                <template x-for="(b, i) in banks" :key="i">
                    <div class="grid grid-cols-12 gap-2 mb-2 items-end">
                        <div class="col-span-3">
                            <label class="block text-xs text-gray-500 mb-1" x-show="i === 0">Nama Bank</label>
                            <input type="text" :name="`banks[${i}][bank_name]`" x-model="b.bank_name"
                                class="w-full border rounded px-2 py-1.5" placeholder="BCA">
                        </div>
                        <div class="col-span-4">
                            <label class="block text-xs text-gray-500 mb-1" x-show="i === 0">No. Rekening</label>
                            <input type="text" :name="`banks[${i}][account_number]`" x-model="b.account_number"
                                class="w-full border rounded px-2 py-1.5" placeholder="8030679556">
                        </div>
                        <div class="col-span-4">
                            <label class="block text-xs text-gray-500 mb-1" x-show="i === 0">Atas Nama</label>
                            <input type="text" :name="`banks[${i}][holder]`" x-model="b.holder"
                                class="w-full border rounded px-2 py-1.5" placeholder="Ahmad Burhanudin">
                        </div>
                        <div class="col-span-1">
                            <button type="button" @click="remove(i)"
                                class="text-red-600 hover:bg-red-50 rounded px-2 py-1.5 text-sm w-full" title="Hapus">
                                &times;
                            </button>
                        </div>
                    </div>
                </template>
            </div>

            <div class="bg-white rounded shadow p-4">
                <h2 class="font-semibold text-sm mb-3">Penandatangan</h2>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Nama Penandatangan</label>
                        <input type="text" name="signer_name" value="{{ old('signer_name', $profile->signer_name) }}" class="w-full border rounded px-2 py-1.5">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Jabatan</label>
                        <input type="text" name="signer_title" value="{{ old('signer_title', $profile->signer_title) }}" class="w-full border rounded px-2 py-1.5" placeholder="Mis. Direktur">
                    </div>
                </div>
            </div>

            <div class="bg-white rounded shadow p-4">
                <h2 class="font-semibold text-sm mb-3">Instruksi Pembayaran (Print SO)</h2>
                <label class="block text-xs text-gray-500 mb-1">Teks cara pembayaran yang tampil di Pesanan Penjualan</label>
                <textarea name="payment_instructions" rows="3" class="w-full border rounded px-2 py-1.5 text-sm"
                          placeholder="Mis. Scan QR untuk membayar. Jika kesulitan hubungi admin.">{{ old('payment_instructions', $profile->payment_instructions) }}</textarea>
                <p class="text-xs text-gray-400 mt-1">QR menuju link pembayaran online otomatis ditampilkan di samping teks ini (untuk SO yang belum lunas).</p>
            </div>

        </div>

        {{-- KANAN: Logo + Signature uploads --}}
        <div class="space-y-4">
            <div class="bg-white rounded shadow p-4">
                <h2 class="font-semibold text-sm mb-3">Logo</h2>
                @if($profile->logo_url)
                    <div class="border rounded p-3 mb-2 bg-gray-50 flex items-center justify-center" style="min-height:120px">
                        <img src="{{ $profile->logo_url }}" alt="Logo" style="max-height:120px; max-width:100%;">
                    </div>
                    <label class="inline-flex items-center text-xs text-red-600 mb-2">
                        <input type="checkbox" name="remove_logo" value="1" class="mr-1"> Hapus logo saat ini
                    </label>
                @else
                    <div class="border-2 border-dashed rounded p-6 text-center text-gray-400 text-xs italic mb-2">Belum ada logo</div>
                @endif
                <input type="file" name="logo" accept="image/*" class="w-full text-xs">
                <p class="text-xs text-gray-400 mt-1">Format JPG/PNG/WebP, maks 2 MB.</p>
            </div>

            <div class="bg-white rounded shadow p-4">
                <h2 class="font-semibold text-sm mb-3">Tanda Tangan</h2>
                @if($profile->signature_url)
                    <div class="border rounded p-3 mb-2 bg-gray-50 flex items-center justify-center" style="min-height:100px">
                        <img src="{{ $profile->signature_url }}" alt="Signature" style="max-height:100px; max-width:100%;">
                    </div>
                    <label class="inline-flex items-center text-xs text-red-600 mb-2">
                        <input type="checkbox" name="remove_signature" value="1" class="mr-1"> Hapus tanda tangan
                    </label>
                @else
                    <div class="border-2 border-dashed rounded p-6 text-center text-gray-400 text-xs italic mb-2">Belum ada gambar tanda tangan</div>
                @endif
                <input type="file" name="signature" accept="image/*" class="w-full text-xs">
                <p class="text-xs text-gray-400 mt-1">Sebaiknya PNG dgn background transparan.</p>
            </div>
        </div>
    </div>

    <div class="mt-4 flex gap-2">
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded text-sm">Simpan Profil</button>
    </div>
</form>
@endsection

@push('scripts')
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
@endpush
