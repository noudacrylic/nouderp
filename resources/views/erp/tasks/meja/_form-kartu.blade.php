{{-- Form di kartu Meja Kerja dari SkillAdmin::formKartu(). --}}
@if ($form['jenis'] === 'selesaikan')
    <form method="POST" action="{{ $form['url'] }}"
          onsubmit="return confirm('Selesaikan pesanan? Faktur dibuat dan stok masuk sesuai PO. Kalau ada barang kurang atau rusak, tetap selesaikan lalu buat retur dari fakturnya.')">@csrf
        <button class="bg-green-600 text-white px-2 py-1 rounded text-xs font-semibold">✓ Selesaikan pesanan</button>
    </form>
@else
    <details class="relative">
        <summary class="list-none cursor-pointer bg-green-600 text-white px-2 py-1 rounded text-xs font-semibold">{{ $form['label'] }}</summary>
        <form method="POST" action="{{ $form['url'] }}" enctype="multipart/form-data" class="absolute z-10 mt-1 w-72 bg-white border rounded shadow p-2 space-y-1.5">@csrf
            <div class="text-[11px] text-gray-500">{{ $form['keterangan'] }}</div>
            <select name="rekening" required class="w-full border rounded px-2 py-1 text-xs">
                <option value="">Pilih rekening…</option>
                @foreach ($form['daftar'] ?? $rekeningOwner as $r)
                    <option value="{{ $r->id }}">{{ $r->code }} {{ $r->name }}</option>
                @endforeach
            </select>
            @isset($form['nominal'])
                <input type="text" inputmode="numeric" name="nominal" required value="{{ number_format($form['nominal'], 0, ',', '.') }}" class="rupiah-input w-full border rounded px-2 py-1 text-xs" placeholder="Nominal">
            @endisset
            @if ($form['bukti'])
                <input type="file" name="nota[]" accept="image/*,application/pdf" class="w-full text-[11px]">
            @endif
            <button class="w-full bg-green-600 text-white px-2 py-1 rounded text-xs font-semibold">{{ $form['tombol'] ?? 'Simpan' }}</button>
        </form>
    </details>
@endif
