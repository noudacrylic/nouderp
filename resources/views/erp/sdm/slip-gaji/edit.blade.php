@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Edit Slip <span class="font-mono text-sm text-gray-500">{{ $slip->code }}</span></h1>
    <a href="{{ route('sdm.slip-gaji.show', $slip->id) }}" class="text-gray-600 px-3 py-1.5 text-sm">Batal</a>
</div>

<p class="text-xs text-gray-500 mb-3">
    Yang bisa diedit: Bonus, THR, Cuti tidak terpakai, potongan fix (BPJS/PPh/Kasbon), dan baris komponen tambahan (manual earnings).
    Gaji pokok, lembur, tunjangan auto dihitung dari attendance.
</p>


<form method="POST" action="{{ route('sdm.slip-gaji.update', $slip->id) }}" class="space-y-4">
    @csrf @method('PUT')

    <div class="bg-white rounded shadow p-5 max-w-5xl">
        <h2 class="font-semibold text-sm mb-3 border-b pb-2">Info Slip</h2>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs text-gray-500 mb-1">Karyawan</label>
                <input type="text" value="{{ $slip->karyawan->name }} ({{ $slip->karyawan->staf_code }})" disabled class="border rounded px-3 py-2 w-full bg-gray-50">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Periode</label>
                <input type="text" value="{{ $slip->periode->label }}" disabled class="border rounded px-3 py-2 w-full bg-gray-50">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Hari Kerja Periode</label>
                <input type="number" min="1" max="31" name="hari_kerja_periode" value="{{ old('hari_kerja_periode', $slip->hari_kerja_periode ?: 25) }}" class="border rounded px-3 py-2 w-full">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Catatan</label>
                <input type="text" name="notes" value="{{ old('notes', $slip->notes) }}" class="border rounded px-3 py-2 w-full">
            </div>
        </div>
    </div>

    <div class="bg-blue-50 border border-blue-200 rounded p-3 max-w-5xl text-xs text-blue-800">
        <b>Bonus, THR &amp; Cuti tidak terpakai</b> kini diinput lewat <b>Kebijakan → Summary</b>
        (per-karyawan / sekali bayar) supaya ikut terhitung di pembayaran <i>dan</i> tampil di slip cetak.
        Komponen tambahan tetap di bawah ini.
    </div>

    {{-- KOMPONEN TAMBAHAN (dynamic rows) --}}
    <div class="bg-white rounded shadow p-5 max-w-5xl" id="komponenSection">
        <div class="flex items-center justify-between mb-3 border-b pb-2">
            <h2 class="font-semibold text-sm">Komponen Tambahan (Earning)</h2>
            <button type="button" onclick="addKomponen()" class="bg-blue-600 hover:bg-blue-700 text-white text-xs px-3 py-1 rounded">+ Tambah Baris</button>
        </div>
        <p class="text-xs text-gray-500 mb-3">Untuk tunjangan jabatan, tunjangan masa kerja, atau bonus lain. Bisa nominal atau persentase dari basis tertentu.</p>
        <table class="w-full text-sm">
            <thead class="text-xs text-gray-500 border-b">
                <tr>
                    <th class="text-left py-1">Label</th>
                    <th class="text-left py-1 w-28">Metode</th>
                    <th class="text-right py-1 w-32">Nilai</th>
                    <th class="text-left py-1 w-40">Basis (%)</th>
                    <th class="text-left py-1">Catatan</th>
                    <th class="w-10"></th>
                </tr>
            </thead>
            <tbody id="komponenRows">
                @foreach($slip->komponen as $idx => $k)
                    <tr class="komponen-row border-b" data-idx="{{ $idx }}">
                        <td class="py-1 pr-2"><input type="hidden" name="komponen[{{ $idx }}][id]" value="{{ $k->id }}"><input type="text" name="komponen[{{ $idx }}][label]" value="{{ $k->label }}" class="border rounded px-2 py-1 w-full text-sm" placeholder="Tunjangan Jabatan"></td>
                        <td class="py-1 pr-2">
                            <select name="komponen[{{ $idx }}][metode]" class="border rounded px-2 py-1 text-sm">
                                <option value="nominal" @selected($k->metode === 'nominal')>Nominal</option>
                                <option value="percentage" @selected($k->metode === 'percentage')>%</option>
                            </select>
                        </td>
                        <td class="py-1 pr-2"><input type="text" name="komponen[{{ $idx }}][nilai]" value="{{ rtrim(rtrim(number_format($k->nilai, 4, ',', '.'), '0'), ',') }}" class="border rounded px-2 py-1 text-sm text-right w-full"></td>
                        <td class="py-1 pr-2">
                            <select name="komponen[{{ $idx }}][basis]" class="border rounded px-2 py-1 text-sm w-full">
                                <option value="gaji_pokok" @selected($k->basis === 'gaji_pokok')>Gaji Pokok</option>
                                <option value="gaji_pokok_dibayar" @selected($k->basis === 'gaji_pokok_dibayar')>Gaji Pokok Dibayar</option>
                                <option value="subtotal" @selected($k->basis === 'subtotal')>Subtotal</option>
                            </select>
                        </td>
                        <td class="py-1 pr-2"><input type="text" name="komponen[{{ $idx }}][notes]" value="{{ $k->notes }}" class="border rounded px-2 py-1 w-full text-sm"></td>
                        <td class="py-1 text-center"><button type="button" onclick="removeKomponen(this)" class="text-red-600 text-xs">✕</button></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <template id="komponenTpl">
            <tr class="komponen-row border-b" data-idx="__IDX__">
                <td class="py-1 pr-2"><input type="hidden" name="komponen[__IDX__][id]" value=""><input type="text" name="komponen[__IDX__][label]" class="border rounded px-2 py-1 w-full text-sm" placeholder="Tunjangan Jabatan"></td>
                <td class="py-1 pr-2">
                    <select name="komponen[__IDX__][metode]" class="border rounded px-2 py-1 text-sm">
                        <option value="nominal">Nominal</option>
                        <option value="percentage">%</option>
                    </select>
                </td>
                <td class="py-1 pr-2"><input type="text" name="komponen[__IDX__][nilai]" class="border rounded px-2 py-1 text-sm text-right w-full" placeholder="0"></td>
                <td class="py-1 pr-2">
                    <select name="komponen[__IDX__][basis]" class="border rounded px-2 py-1 text-sm w-full">
                        <option value="gaji_pokok">Gaji Pokok</option>
                        <option value="gaji_pokok_dibayar">Gaji Pokok Dibayar</option>
                        <option value="subtotal">Subtotal</option>
                    </select>
                </td>
                <td class="py-1 pr-2"><input type="text" name="komponen[__IDX__][notes]" class="border rounded px-2 py-1 w-full text-sm"></td>
                <td class="py-1 text-center"><button type="button" onclick="removeKomponen(this)" class="text-red-600 text-xs">✕</button></td>
            </tr>
        </template>
    </div>

    {{-- POTONGAN FIX --}}
    <div class="bg-white rounded shadow p-5 max-w-5xl">
        <h2 class="font-semibold text-sm mb-3 border-b pb-2">Potongan Fix (Terkait Jurnal)</h2>
        <p class="text-xs text-gray-500 mb-3">Hanya 4 jenis potongan ini yang fix karena bertaut dengan jurnal akuntansi.</p>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-xs text-gray-500 mb-1">BPJS Kesehatan (Rp)</label>
                <input type="text" inputmode="numeric" name="bpjs_kesehatan_amount" value="{{ old('bpjs_kesehatan_amount', number_format((float)$slip->bpjs_kesehatan_amount, 0, ',', '.')) }}" class="rupiah-input border rounded px-3 py-2 w-full text-right">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">BPJS TK (Rp)</label>
                <input type="text" inputmode="numeric" name="bpjs_tk_amount" value="{{ old('bpjs_tk_amount', number_format((float)$slip->bpjs_tk_amount, 0, ',', '.')) }}" class="rupiah-input border rounded px-3 py-2 w-full text-right">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">PPh 21 (Rp)</label>
                <input type="text" inputmode="numeric" name="pph21_amount" value="{{ old('pph21_amount', number_format((float)$slip->pph21_amount, 0, ',', '.')) }}" class="rupiah-input border rounded px-3 py-2 w-full text-right">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Potongan Kasbon (Rp)</label>
                <input type="text" inputmode="numeric" name="kasbon_payment" value="{{ old('kasbon_payment', number_format((float)$slip->kasbon_payment, 0, ',', '.')) }}" class="rupiah-input border rounded px-3 py-2 w-full text-right">
                @if(isset($kasbonAktif) && $kasbonAktif->isNotEmpty())
                    @php $totalCicilan = $kasbonAktif->sum('cicilan_per_bulan'); @endphp
                    <p class="text-[11px] text-amber-700 mt-1">Saran cicilan: Rp {{ number_format($totalCicilan, 0, ',', '.') }} dari {{ $kasbonAktif->count() }} kasbon aktif.</p>
                @endif
            </div>
        </div>
    </div>

    <div class="bg-gray-50 rounded p-3 text-xs text-gray-600 max-w-5xl">
        <div><strong>Preview hitungan otomatis:</strong></div>
        <div>Subtotal: {{ rupiah($slip->subtotal) }} · Total komponen tambahan: {{ rupiah($slip->total_komponen_earning) }} · Total potongan: {{ rupiah($slip->total_potongan) }}</div>
        <div>Total Gaji: <span class="font-semibold">{{ rupiah($slip->total_gaji) }}</span></div>
        <div class="text-gray-400 mt-1">Setelah simpan, slip akan di-hitung ulang & nilai komponen percentage di-resolve dari basis.</div>
    </div>

    <div class="max-w-5xl flex gap-2">
        <button class="bg-blue-600 text-white px-4 py-2 rounded text-sm font-semibold">Simpan &amp; Hitung Ulang</button>
        <a href="{{ route('sdm.slip-gaji.show', $slip->id) }}" class="text-gray-600 px-3 py-2 text-sm">Batal</a>
    </div>
</form>

<script>
let komponenIdx = {{ $slip->komponen->count() }};
function addKomponen() {
    const tpl = document.getElementById('komponenTpl');
    const html = tpl.innerHTML.replaceAll('__IDX__', komponenIdx);
    const tbody = document.getElementById('komponenRows');
    const tmp = document.createElement('tbody');
    tmp.innerHTML = html;
    tbody.appendChild(tmp.firstElementChild);
    komponenIdx++;
}
function removeKomponen(btn) {
    btn.closest('tr').remove();
}
</script>
@endsection
