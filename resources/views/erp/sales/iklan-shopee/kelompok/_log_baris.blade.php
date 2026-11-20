<tr class="border-b border-gray-100">
    <td class="px-3 py-1 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($l->created_at)->translatedFormat('d M H:i') }}</td>
    @if (!empty($tampilKelompok))<td class="px-2">{{ $kelompok ?: '—' }}</td>@endif
    <td class="px-2 max-w-[20rem] truncate" title="{{ $nama }}">{{ $nama ?: '#' . $l->campaign_id }}</td>
    <td class="px-2">
        <span class="{{ $l->aksi === 'lanjut' ? 'text-green-700' : 'text-gray-600' }}">{{ $l->aksi === 'lanjut' ? '▶ lanjut' : '⏸ jeda' }}</span>
    </td>
    <td class="px-2">
        <span class="rounded px-1.5 py-0.5 {{ ['bayangan' => 'bg-amber-50 text-amber-800', 'berhasil' => 'bg-green-50 text-green-800', 'gagal' => 'bg-red-50 text-red-700', 'dilewati' => 'bg-gray-100 text-gray-500'][$l->hasil] ?? '' }}">{{ $l->hasil }}</span>
    </td>
    <td class="px-3 text-gray-600">{{ $l->alasan }}{{ $l->pesan ? ' — ' . $l->pesan : '' }}</td>
</tr>
