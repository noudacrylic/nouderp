@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Kredit Pelanggan Baru</h1>
    <a href="{{ route('sales.kredit.index') }}" class="text-sm text-gray-500 hover:underline">← Kembali</a>
</div>

<form method="POST" action="{{ route('sales.kredit.store') }}" class="bg-white rounded shadow p-4 max-w-2xl space-y-4 text-sm">
    @csrf

    <div class="grid grid-cols-2 gap-4">
        <div class="col-span-2">
            <label class="block text-xs font-semibold text-gray-600 mb-1">Pelanggan *</label>
            <select name="customer_id" id="customer_id" required class="w-full border rounded px-2 py-1.5">
                <option value="">— pilih pelanggan —</option>
                @foreach($customers as $cust)
                    <option value="{{ $cust->id }}" @selected(old('customer_id') == $cust->id)>{{ $cust->name }}</option>
                @endforeach
            </select>
            <p id="saldo-info" class="text-xs text-gray-500 mt-1">Saldo kredit saat ini: —</p>
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Tanggal *</label>
            <input type="date" name="credit_date" required value="{{ old('credit_date', now()->toDateString()) }}"
                   class="w-full border rounded px-2 py-1.5">
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Jenis *</label>
            <select name="direction" required class="w-full border rounded px-2 py-1.5">
                @foreach($directions as $val => $label)
                    <option value="{{ $val }}" @selected(old('direction', 'tambah') == $val)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Nominal *</label>
            <input type="text" name="amount" required value="{{ old('amount') }}" placeholder="0"
                   class="rupiah-input w-full border rounded px-2 py-1.5 text-right">
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Akun Lawan *</label>
            <select name="counter_account_id" required class="w-full border rounded px-2 py-1.5">
                @foreach($accounts as $acc)
                    <option value="{{ $acc->id }}" @selected(old('counter_account_id', $defaultAccountId) == $acc->id)>
                        {{ $acc->code }} — {{ $acc->name }}
                    </option>
                @endforeach
            </select>
            <p class="text-xs text-gray-400 mt-1">
                Bawaannya 6105 Beban Kerugian Retur — dipakai saat kredit lahir dari barang yang kembali tanpa refund.
            </p>
        </div>

        <div class="col-span-2">
            <label class="block text-xs font-semibold text-gray-600 mb-1">Alasan</label>
            <textarea name="reason" rows="3" class="w-full border rounded px-2 py-1.5"
                      placeholder="Mis. tukar ukuran TBKD-13-t1-M3 → A5, pesanan Shopee 21 Sep 2026">{{ old('reason') }}</textarea>
        </div>
    </div>

    <div class="bg-amber-50 border border-amber-200 rounded p-3 text-xs text-amber-800">
        Dokumen ini langsung diposting: jurnalnya terbentuk dan saldo pelanggan berubah seketika.
        Selama saldonya belum terpakai membayar faktur, dokumen masih bisa di-void.
    </div>

    <div class="flex justify-end gap-2">
        <a href="{{ route('sales.kredit.index') }}" class="px-3 py-2 rounded border text-gray-600">Batal</a>
        <button class="bg-blue-600 text-white px-4 py-2 rounded">Simpan</button>
    </div>
</form>

<script>
document.getElementById('customer_id').addEventListener('change', function () {
    const info = document.getElementById('saldo-info');
    if (!this.value) { info.textContent = 'Saldo kredit saat ini: —'; return; }
    info.textContent = 'Saldo kredit saat ini: memuat...';
    fetch('{{ route('sales.ajax.returns.customer_balance') }}?customer_id=' + this.value)
        .then(r => r.json())
        .then(d => {
            info.textContent = 'Saldo kredit saat ini: Rp ' + Number(d.balance || 0).toLocaleString('id-ID');
        })
        .catch(() => { info.textContent = 'Saldo kredit saat ini: gagal dimuat'; });
});
</script>
@endsection
