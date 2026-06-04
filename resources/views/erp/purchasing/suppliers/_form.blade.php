@csrf
@isset($supplier)
    <div class="text-sm text-gray-500 mb-3">Kode: <span class="font-semibold text-gray-700">{{ $supplier->code }}</span> <span class="text-xs">(otomatis)</span></div>
@endisset
<div class="grid grid-cols-2 gap-4 max-w-4xl">
    <div class="col-span-2">
        <label class="block text-sm mb-1">Nama <span class="text-red-500">*</span></label>
        <input type="text" name="name" class="border rounded px-3 py-2 w-full" value="{{ old('name', $supplier->name ?? '') }}" required>
    </div>
    <div>
        <label class="block text-sm mb-1">Telp</label>
        <input type="text" name="phone" class="border rounded px-3 py-2 w-full" value="{{ old('phone', $supplier->phone ?? '') }}">
    </div>
    <div>
        <label class="block text-sm mb-1">Email</label>
        <input type="email" name="email" class="border rounded px-3 py-2 w-full" value="{{ old('email', $supplier->email ?? '') }}">
    </div>
    <div>
        <label class="block text-sm mb-1">Kota</label>
        <input type="text" name="city" class="border rounded px-3 py-2 w-full" value="{{ old('city', $supplier->city ?? '') }}">
    </div>
    <div>
        <label class="block text-sm mb-1">NPWP</label>
        <input type="text" name="npwp" class="border rounded px-3 py-2 w-full" value="{{ old('npwp', $supplier->npwp ?? '') }}">
    </div>
    <div class="col-span-2">
        <label class="block text-sm mb-1">Alamat</label>
        <textarea name="address" rows="2" class="border rounded px-3 py-2 w-full">{{ old('address', $supplier->address ?? '') }}</textarea>
    </div>
    <div class="col-span-2">
        <label class="block text-sm mb-1">Alamat NPWP</label>
        <textarea name="tax_address" rows="2" class="border rounded px-3 py-2 w-full">{{ old('tax_address', $supplier->tax_address ?? '') }}</textarea>
    </div>
    <div>
        <label class="block text-sm mb-1">Term Pembayaran (hari)</label>
        <input type="number" name="payment_term_days" class="border rounded px-3 py-2 w-full" value="{{ old('payment_term_days', $supplier->payment_term_days ?? 30) }}" min="0">
    </div>
    <div></div>
    <div>
        <label class="block text-sm mb-1">Bank</label>
        <input type="text" name="bank_name" class="border rounded px-3 py-2 w-full" value="{{ old('bank_name', $supplier->bank_name ?? '') }}">
    </div>
    <div>
        <label class="block text-sm mb-1">No. Rekening</label>
        <input type="text" name="bank_account_no" class="border rounded px-3 py-2 w-full" value="{{ old('bank_account_no', $supplier->bank_account_no ?? '') }}">
    </div>
    <div class="col-span-2">
        <label class="block text-sm mb-1">Nama Pemilik Rekening</label>
        <input type="text" name="bank_account_name" class="border rounded px-3 py-2 w-full" value="{{ old('bank_account_name', $supplier->bank_account_name ?? '') }}">
    </div>
    @php
        $defaultApName = optional($accounts->firstWhere('code', '2101'))->name ?? 'Hutang Usaha';
        $defaultDpName = optional($accounts->firstWhere('code', '1107'))->name ?? 'Uang Muka Supplier';
    @endphp
    <div>
        <label class="block text-sm mb-1">Akun Hutang Default</label>
        <select name="account_payable_id" class="border rounded px-3 py-2 w-full">
            <option value="">— pakai default: 2101 {{ $defaultApName }} —</option>
            @foreach($accounts as $a)
                <option value="{{ $a->id }}" @selected(old('account_payable_id', $supplier->account_payable_id ?? '') == $a->id)>{{ $a->code }} — {{ $a->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm mb-1">Akun DP Supplier Default</label>
        <select name="account_dp_id" class="border rounded px-3 py-2 w-full">
            <option value="">— pakai default: 1107 {{ $defaultDpName }} —</option>
            @foreach($accounts as $a)
                <option value="{{ $a->id }}" @selected(old('account_dp_id', $supplier->account_dp_id ?? '') == $a->id)>{{ $a->code }} — {{ $a->name }}</option>
            @endforeach
        </select>
    </div>
    @isset($supplier)
        <div class="col-span-2">
            <label class="inline-flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $supplier->is_active))>
                Aktif
            </label>
        </div>
    @endisset
</div>

<div class="mt-6 flex gap-2">
    <button class="bg-blue-600 text-white px-4 py-2 rounded">Simpan</button>
    <a href="{{ route('purchasing.suppliers.index') }}" class="px-4 py-2 border rounded">Batal</a>
</div>
