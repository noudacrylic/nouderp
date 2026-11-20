@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-1 gap-2 flex-wrap">
    <h1 class="text-lg font-semibold">Kata Kunci SEO</h1>
    <form method="POST" action="{{ route('store.kata-kunci.riset') }}" onsubmit="return confirm('Riset semua jenis aktif dengan AI + pencarian web? Perkiraan biaya ±Rp4.000–5.000 per jenis.')">
        @csrf <input type="hidden" name="jenis" value="semua">
        <button class="border border-gray-300 text-gray-700 px-3 py-1.5 rounded text-sm hover:bg-gray-50">🔎 Riset semua jenis</button>
    </form>
</div>
<p class="text-xs text-gray-500 mb-3">Istilah yang dipakai calon pembeli di Google, per jenis produk. Sumber: riset AI (Claude + pencarian web), Google Search Console (kata yang benar-benar mendatangkan pengunjung), dan tambahan manual. Dipakai untuk judul, keterangan & alt foto di Galeri web.</p>

{{-- Search Console --}}
<div class="bg-white rounded shadow p-3 mb-3 text-sm flex items-center justify-between gap-3 flex-wrap">
    <div>
        <span class="font-semibold">Google Search Console</span>
        @if ($gsc['tersambung'])
            <span class="ml-1 text-xs bg-green-100 text-green-800 rounded px-1.5 py-0.5">tersambung</span>
            <span class="text-xs text-gray-500 ml-1">{{ $gsc['email'] }}</span>
            @if ($s = $gsc['status'])
                <div class="text-xs mt-0.5 {{ isset($s['galat']) ? 'text-red-600' : 'text-gray-500' }}">
                    Terakhir {{ \Illuminate\Support\Carbon::parse($s['waktu'])->translatedFormat('d M H:i') }}:
                    {{ isset($s['galat']) ? 'gagal — ' . $s['galat'] : $s['baris'] . ' kata pencarian (28 hari), ' . $s['baru'] . ' kata baru' }}
                </div>
            @else
                <div class="text-xs text-gray-500 mt-0.5">Belum pernah ditarik. Otomatis tiap hari 04:10.</div>
            @endif
        @else
            <span class="ml-1 text-xs bg-amber-100 text-amber-800 rounded px-1.5 py-0.5">belum disambungkan</span>
            <div class="text-xs text-gray-500 mt-0.5">Pasang kunci service account di server (lihat panduan dari tim IT). Sampai itu, bank hanya berisi hasil riset AI & manual.</div>
        @endif
    </div>
    @if ($gsc['tersambung'])
        <form method="POST" action="{{ route('store.kata-kunci.tarik-gsc') }}">@csrf
            <button class="bg-gray-700 text-white px-3 py-1.5 rounded text-sm">Tarik sekarang</button>
        </form>
    @endif
</div>

<div class="flex gap-3 items-start flex-col lg:flex-row">
    {{-- Jenis produk --}}
    <div class="bg-white rounded shadow w-full lg:w-72 shrink-0 text-sm">
        <div class="px-3 py-2 border-b font-semibold text-gray-600 text-xs">Jenis produk</div>
        <a href="{{ route('store.kata-kunci.index') }}" class="flex justify-between px-3 py-1.5 hover:bg-blue-50 {{ !$pilih ? 'bg-blue-50 font-semibold' : '' }}">
            <span>Semua</span><span class="text-gray-400">{{ $jenis->sum('kata_kunci_count') + $tanpaJenis }}</span>
        </a>
        @foreach ($jenis as $j)
            <a href="{{ route('store.kata-kunci.index', ['jenis' => $j->id]) }}"
               class="flex justify-between gap-2 px-3 py-1.5 hover:bg-blue-50 {{ (string) $pilih === (string) $j->id ? 'bg-blue-50 font-semibold' : '' }} {{ $j->aktif ? '' : 'opacity-50' }}">
                <span class="truncate">{{ $j->nama }}
                    @if ($j->riset_diminta_at)<span class="text-[10px] text-amber-600">⏳</span>@endif
                </span>
                <span class="text-gray-400 shrink-0">{{ $j->kata_kunci_count }}</span>
            </a>
        @endforeach
        @if ($tanpaJenis)
            <a href="{{ route('store.kata-kunci.index', ['jenis' => 'tanpa']) }}" class="flex justify-between px-3 py-1.5 hover:bg-blue-50 text-amber-700 {{ $pilih === 'tanpa' ? 'bg-amber-50 font-semibold' : '' }}">
                <span>Belum dikelompokkan</span><span>{{ $tanpaJenis }}</span>
            </a>
        @endif
        <details class="border-t">
            <summary class="px-3 py-2 cursor-pointer text-xs text-blue-700">+ Tambah jenis</summary>
            <form method="POST" action="{{ route('store.kata-kunci.jenis.simpan') }}" class="px-3 pb-3 space-y-1.5">@csrf
                <input name="nama" required maxlength="100" placeholder="mis. Akrilik Cetak UV" class="w-full border rounded px-2 py-1 text-sm">
                <input name="petunjuk" maxlength="500" placeholder="Keterangan untuk riset AI (opsional)" class="w-full border rounded px-2 py-1 text-xs">
                <button class="bg-blue-600 text-white px-3 py-1 rounded text-xs">Simpan</button>
            </form>
        </details>
    </div>

    <div class="flex-1 min-w-0 w-full space-y-3">
        {{-- Jenis terpilih: riset & keterangan --}}
        @if ($jenisAktif)
            <div class="bg-white rounded shadow p-3 text-sm space-y-2">
                <div class="flex items-center justify-between gap-2 flex-wrap">
                    <div>
                        <span class="font-semibold">{{ $jenisAktif->nama }}</span>
                        <span class="text-xs text-gray-500 ml-1">
                            @if ($jenisAktif->riset_diminta_at) ⏳ menunggu riset…
                            @elseif ($jenisAktif->riset_at) Riset {{ $jenisAktif->riset_at->translatedFormat('d M Y H:i') }} — {{ $jenisAktif->riset_ringkasan }}{{ $jenisAktif->riset_biaya_rp ? ' · Rp' . number_format($jenisAktif->riset_biaya_rp, 0, ',', '.') : '' }}
                            @else {{ $jenisAktif->riset_ringkasan ?: 'Belum pernah diriset.' }}
                            @endif
                        </span>
                    </div>
                    <form method="POST" action="{{ route('store.kata-kunci.riset') }}">@csrf
                        <input type="hidden" name="jenis" value="{{ $jenisAktif->id }}">
                        <button class="bg-blue-600 text-white px-3 py-1.5 rounded text-sm disabled:opacity-40" @disabled($jenisAktif->riset_diminta_at || !$jenisAktif->aktif)>🔎 Riset AI</button>
                    </form>
                </div>
                <form method="POST" action="{{ route('store.kata-kunci.jenis.ubah', $jenisAktif->id) }}" class="flex gap-2 items-center flex-wrap">@csrf @method('PUT')
                    <input name="petunjuk" value="{{ $jenisAktif->petunjuk }}" maxlength="500" placeholder="Keterangan untuk riset AI (mis. untuk siapa, bahan, ukuran)" class="border rounded px-2 py-1 text-xs flex-1 min-w-[16rem]">
                    <input type="hidden" name="aktif" value="0">
                    <label class="text-xs flex items-center gap-1"><input type="checkbox" name="aktif" value="1" @checked($jenisAktif->aktif)> Aktif</label>
                    <button class="bg-gray-700 text-white px-2 py-1 rounded text-xs">Simpan</button>
                </form>
            </div>
        @endif

        {{-- Filter & tambah manual --}}
        <div class="bg-white rounded shadow p-3 flex gap-3 items-end text-sm flex-wrap">
            <form method="GET" class="flex gap-2 items-end flex-wrap">
                @if ($pilih)<input type="hidden" name="jenis" value="{{ $pilih }}">@endif
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Cari</label>
                    <input type="text" name="q" value="{{ $q }}" placeholder="kata kunci…" class="border rounded px-2 py-1.5 w-52">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Sumber</label>
                    <select name="sumber" class="border rounded px-2 py-1.5">
                        <option value="">Semua</option>
                        @foreach (['riset' => 'Riset AI', 'gsc' => 'Search Console', 'manual' => 'Manual'] as $k => $l)
                            <option value="{{ $k }}" @selected(request('sumber') === $k)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                @include('erp._partials.per-page-select')
                <button class="bg-gray-700 text-white px-3 py-1.5 rounded text-sm">Filter</button>
            </form>
            <form method="POST" action="{{ route('store.kata-kunci.simpan') }}" class="flex gap-2 items-end flex-wrap ml-auto">@csrf
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Tambah manual</label>
                    <input name="kata" required maxlength="150" placeholder="kata kunci baru" class="border rounded px-2 py-1.5 w-48">
                </div>
                <select name="seo_jenis_id" class="border rounded px-2 py-1.5">
                    <option value="">— jenis —</option>
                    @foreach ($jenis as $j)<option value="{{ $j->id }}" @selected($jenisAktif?->id === $j->id)>{{ $j->nama }}</option>@endforeach
                </select>
                <button class="bg-blue-600 text-white px-3 py-1.5 rounded text-sm">+ Tambah</button>
            </form>
        </div>

        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b text-gray-600">
                    <tr>
                        <th class="px-3 py-2 text-left">Kata kunci</th>
                        <th class="px-3 py-2 text-left">Jenis</th>
                        <th class="px-3 py-2 text-left">Niat</th>
                        <th class="px-3 py-2 text-left" title="Taksiran AI dari bukti pencarian — bukan volume">Taksiran</th>
                        <th class="px-3 py-2 text-right" title="Search Console 28 hari: tayang / klik / posisi rata-rata">GSC tayang</th>
                        <th class="px-3 py-2 text-right">Klik</th>
                        <th class="px-3 py-2 text-right">Posisi</th>
                        <th class="px-3 py-2 text-left">Sumber</th>
                        <th class="px-3 py-2 text-right w-24">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($kata as $k)
                        <tr class="border-b hover:bg-blue-50 {{ $k->aktif ? '' : 'opacity-50' }}">
                            <td class="px-3 py-1.5 font-medium" title="{{ $k->catatan }}">{{ $k->kata }}</td>
                            <td class="px-3 py-1.5">
                                <form method="POST" action="{{ route('store.kata-kunci.ubah', $k->id) }}">@csrf @method('PUT')
                                    <select name="seo_jenis_id" onchange="this.form.submit()" class="border-0 bg-transparent text-xs py-0 pl-0 {{ $k->seo_jenis_id ? '' : 'text-amber-700' }}">
                                        <option value="">— belum —</option>
                                        @foreach ($jenis as $j)<option value="{{ $j->id }}" @selected($k->seo_jenis_id === $j->id)>{{ $j->nama }}</option>@endforeach
                                    </select>
                                </form>
                            </td>
                            <td class="px-3 py-1.5">
                                <form method="POST" action="{{ route('store.kata-kunci.ubah', $k->id) }}">@csrf @method('PUT')
                                    <select name="niat" onchange="this.form.submit()" class="border-0 bg-transparent text-xs py-0 pl-0">
                                        <option value="">—</option>
                                        @foreach (\App\Modules\Seo\Models\SeoKataKunci::NIAT as $nk => $nl)<option value="{{ $nk }}" @selected($k->niat === $nk)>{{ $nl }}</option>@endforeach
                                    </select>
                                </form>
                            </td>
                            <td class="px-3 py-1.5 text-xs">{{ $k->perkiraan ?? '—' }}</td>
                            <td class="px-3 py-1.5 text-right">{{ $k->gsc_tayang !== null ? number_format($k->gsc_tayang, 0, ',', '.') : '—' }}</td>
                            <td class="px-3 py-1.5 text-right">{{ $k->gsc_klik !== null ? number_format($k->gsc_klik, 0, ',', '.') : '—' }}</td>
                            <td class="px-3 py-1.5 text-right">{{ $k->gsc_posisi !== null ? number_format($k->gsc_posisi, 1, ',', '.') : '—' }}</td>
                            <td class="px-3 py-1.5 text-xs text-gray-500">{{ ['riset' => 'AI', 'gsc' => 'GSC', 'manual' => 'Manual'][$k->sumber] ?? $k->sumber }}</td>
                            <td class="px-3 py-1.5 text-right">
                                <div class="flex gap-1 flex-row-reverse">
                                    <form method="POST" action="{{ route('store.kata-kunci.hapus', $k->id) }}" onsubmit="return confirm('Hapus kata kunci ini?')">@csrf @method('DELETE')
                                        <button class="bg-red-600 text-white px-2 py-0.5 rounded text-xs">Hapus</button>
                                    </form>
                                    <form method="POST" action="{{ route('store.kata-kunci.ubah', $k->id) }}">@csrf @method('PUT')
                                        <input type="hidden" name="aktif" value="{{ $k->aktif ? 0 : 1 }}">
                                        <button class="border px-2 py-0.5 rounded text-xs" title="Kata nonaktif tidak dipakai untuk caption">{{ $k->aktif ? 'Matikan' : 'Aktifkan' }}</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-3 py-6 text-center text-gray-500">
                            Belum ada kata kunci{{ $jenisAktif ? ' untuk ' . $jenisAktif->nama . ' — tekan 🔎 Riset AI' : '' }}.
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $kata->links() }}</div>
    </div>
</div>
@endsection
