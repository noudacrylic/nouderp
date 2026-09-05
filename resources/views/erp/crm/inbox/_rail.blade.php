{{-- Kolom KANAN: alat bantu membalas.

     Tabnya sengaja diawali "Info" — triase & kepemilikan adalah hal yang paling
     sering disentuh, dan menyembunyikannya di balik tab kedua akan membuatnya
     berhenti dipakai. Produk & Pesanan menyusul di putaran berikutnya. --}}
<div class="flex flex-col h-full min-h-0 bg-white border border-gray-200 rounded-lg overflow-hidden" x-data="{ tab: 'info' }">

    <div class="shrink-0 flex gap-1 px-2 py-2 border-b border-gray-200 bg-gray-50 text-xs">
        {{-- Produk pindah ke menu klip di kotak ketik — tempatnya memang di sisi
             lampiran, bukan panel telaah. --}}
        @foreach(['info' => 'Info', 'template' => 'Template', 'pesanan' => 'Pesanan'] as $key => $label)
            <button type="button" @click="tab = '{{ $key }}'"
                    :class="tab === '{{ $key }}' ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-gray-300 bg-white hover:bg-gray-50'"
                    class="px-2 py-1 rounded border">{{ $label }}</button>
        @endforeach
    </div>

    <div class="flex-1 min-h-0 overflow-y-auto p-2 space-y-2">

        {{-- ----------------------------------------------------------- info --}}
        <div x-show="tab === 'info'">
            @if($terpilih)
                @include('erp.crm.inbox._info')
            @else
                <p class="text-sm text-gray-500 px-1">Pilih percakapan untuk mengatur antrean, pemilik, dan catatan.</p>
            @endif
        </div>

        {{-- ------------------------------------------------------- template --}}
        <div x-show="tab === 'template'" x-cloak class="space-y-2">
            <div class="rounded border border-gray-200 bg-gray-50 px-2 py-1.5 text-[11px] text-gray-600">
                Potongan balasan milik sendiri — gratis, tapi <b>hanya sah di dalam jendela 24 jam</b>.
                Untuk menyapa nomor dingin, pakai <a href="{{ route('crm.template.index') }}" class="text-emerald-700 hover:underline">template Meta</a>.
            </div>

            @forelse($snippets as $s)
                <div class="border border-gray-200 rounded p-2">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="text-sm font-medium truncate">{{ $s->title }}</div>
                            @if($s->category)
                                <div class="text-[10px] text-gray-400 uppercase tracking-wide">{{ $s->category }}</div>
                            @endif
                        </div>
                        @if($terpilih && $terpilih->windowIsOpen())
                            {{-- Disisipkan ke kotak balasan, BUKAN langsung dikirim: potongan
                                 teks hampir selalu perlu disunting sedikit, dan tombol yang
                                 langsung mengirim akan meloloskan kalimat setengah jadi. --}}
                            <button type="button" class="shrink-0 text-xs text-emerald-700 hover:underline"
                                    @click="$dispatch('sisip-snippet', { teks: @js($s->render($terpilih, auth()->user()?->name)) })">
                                Sisipkan
                            </button>
                        @endif
                    </div>
                    <p class="mt-1 text-xs text-gray-600 whitespace-pre-wrap">{{ \Illuminate\Support\Str::limit($s->body, 180) }}</p>
                </div>
            @empty
                <p class="text-sm text-gray-500 px-1">Belum ada potongan balasan.</p>
            @endforelse

            <details class="border border-gray-200 rounded p-2">
                <summary class="text-sm font-medium cursor-pointer">Tambah potongan</summary>
                <form method="POST" action="{{ route('crm.snippet.store') }}" class="mt-2 space-y-2">
                    @csrf
                    <input type="text" name="title" required maxlength="120" placeholder="Judul, mis. Sapaan awal"
                           class="border rounded w-full px-2 py-1.5 text-sm">
                    <input type="text" name="category" maxlength="60" placeholder="Grup (opsional), mis. Harga"
                           class="border rounded w-full px-2 py-1.5 text-sm">
                    <textarea name="body" rows="4" required maxlength="2000" placeholder="Isi balasan…"
                              class="border rounded w-full px-2 py-1.5 text-sm"></textarea>
                    <div class="text-[11px] text-gray-500 leading-relaxed">
                        Isian yang bisa dipakai:
                        @foreach($isian as $kode => $arti)
                            <span class="font-mono">{{ $kode }}</span>@if(! $loop->last), @endif
                        @endforeach
                        <span class="block text-gray-400">Yang tidak punya jawaban dikosongkan, bukan dibiarkan sebagai kurung kurawal.</span>
                    </div>
                    <button class="w-full border border-emerald-600 text-emerald-700 hover:bg-emerald-50 px-3 py-1.5 rounded text-sm">Simpan</button>
                </form>
            </details>
        </div>

        {{-- --------------------------------------------------------- pesanan --}}
        <div x-show="tab === 'pesanan'" x-cloak>
            <p class="text-sm text-gray-500 px-1">
                Pesanan pelanggan ini beserta statusnya, dan tombol buat SO dari chat — menyusul.
            </p>
        </div>
    </div>
</div>
