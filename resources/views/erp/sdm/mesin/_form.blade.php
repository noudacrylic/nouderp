@php $machine = $machine ?? null; @endphp

@if($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded text-sm mb-3">
        <ul class="list-disc pl-5">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<div class="space-y-6 max-w-3xl mx-auto">
    <div class="bg-white rounded shadow p-5">
        <div class="text-sm font-semibold text-gray-700 mb-3 border-b pb-2">Identitas Mesin</div>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs text-gray-500 mb-1">Code <span class="text-red-500">*</span></label>
                <input type="text" name="code" required value="{{ old('code', $machine->code ?? '') }}" class="border rounded px-3 py-2 w-full" placeholder="FP-01">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Nama <span class="text-red-500">*</span></label>
                <input type="text" name="name" required value="{{ old('name', $machine->name ?? '') }}" class="border rounded px-3 py-2 w-full" placeholder="Mesin Pabrik">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Serial Number</label>
                <input type="text" name="serial_number" value="{{ old('serial_number', $machine->serial_number ?? '') }}" class="border rounded px-3 py-2 w-full font-mono" placeholder="ZXPD09280860">
                <div class="text-[11px] text-gray-400 mt-1">Wajib diisi untuk ADMS push (mesin akan kirim SN ini ke endpoint).</div>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Lokasi</label>
                <input type="text" name="location" value="{{ old('location', $machine->location ?? '') }}" class="border rounded px-3 py-2 w-full" placeholder="Pintu masuk pabrik">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Model</label>
                <input type="text" name="model" value="{{ old('model', $machine->model ?? '') }}" class="border rounded px-3 py-2 w-full" placeholder="TH600">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Firmware</label>
                <input type="text" name="firmware_version" value="{{ old('firmware_version', $machine->firmware_version ?? '') }}" class="border rounded px-3 py-2 w-full" placeholder="V9.9">
            </div>
        </div>
    </div>

    <div class="bg-white rounded shadow p-5">
        <div class="text-sm font-semibold text-gray-700 mb-3 border-b pb-2">Network</div>
        <div class="grid grid-cols-3 gap-4">
            <div class="col-span-2">
                <label class="block text-xs text-gray-500 mb-1">IP Address</label>
                <input type="text" name="ip_address" value="{{ old('ip_address', $machine->ip_address ?? '') }}" class="border rounded px-3 py-2 w-full font-mono" placeholder="192.168.1.224">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Port</label>
                <input type="number" name="port" min="1" max="65535" value="{{ old('port', $machine->port ?? 5005) }}" class="border rounded px-3 py-2 w-full">
            </div>
            <div class="col-span-2">
                <label class="block text-xs text-gray-500 mb-1">Comm Key (opsional)</label>
                <input type="text" name="comm_key" value="{{ old('comm_key', $machine->comm_key ?? '') }}" class="border rounded px-3 py-2 w-full font-mono">
                <div class="text-[11px] text-gray-400 mt-1">Untuk autentikasi ADMS — kosongkan kalau mesin tidak pakai.</div>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Status</label>
                <select name="is_active" class="border rounded px-3 py-2 w-full">
                    <option value="1" @selected(old('is_active', $machine->is_active ?? true))>Aktif</option>
                    <option value="0" @selected(! old('is_active', $machine->is_active ?? true))>Nonaktif</option>
                </select>
            </div>
        </div>
    </div>

    <div class="bg-white rounded shadow p-5">
        <label class="block text-xs text-gray-500 mb-1">Catatan</label>
        <textarea name="notes" rows="3" class="border rounded px-3 py-2 w-full">{{ old('notes', $machine->notes ?? '') }}</textarea>
    </div>

    <div class="flex gap-2">
        <button class="bg-blue-600 text-white px-4 py-2 rounded text-sm font-semibold">Simpan</button>
        <a href="{{ route('sdm.mesin.index') }}" class="text-gray-600 px-3 py-2 text-sm">Batal</a>
    </div>
</div>
