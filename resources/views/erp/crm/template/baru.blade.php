@extends('layouts.erp')

@section('content')
<div class="max-w-2xl mx-auto">
    <a href="{{ route('crm.inbox.index') }}" class="inline-block text-xs text-blue-600 hover:underline mb-3">← Inbox</a>

    <div class="bg-white rounded shadow p-4">
        <h1 class="text-lg font-semibold mb-1">Chat Baru</h1>
        <p class="text-sm text-gray-500 mb-4">
            Untuk nomor yang belum pernah menghubungi kita — misalnya pelanggan yang meninggalkan nomor di toko.
            Hanya template yang boleh dikirim; begitu ia membalas, jendela 24 jam terbuka dan Anda bisa mengetik bebas.
        </p>

        @if(filled($nomorAwal ?? ''))
            <div class="mb-4 rounded border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                Tujuannya sudah diisikan: <b class="font-mono">{{ $nomorAwal }}</b>. Ganti kalau bukan nomor ini.
            </div>
        @endif

        @if($dryRun)
            <div class="mb-4 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                <b>Mode aman menyala.</b> Percakapan tetap dibuat dan tercatat, tapi templatenya tidak benar-benar dikirim.
            </div>
        @endif

        @if($templates->isEmpty())
            <div class="rounded border border-amber-300 bg-amber-50 px-3 py-3 text-sm text-amber-800">
                <b>Belum ada template yang disetujui Meta.</b>
                Buat dulu di <a href="{{ route('crm.template.index') }}" class="text-blue-700 underline">Template Pesan</a>,
                centang &ldquo;Daftarkan ke Meta&rdquo;, lalu tekan Ajukan.
            </div>
        @else
            <form method="POST" action="{{ route('crm.template.mulai') }}" class="space-y-4"
                  x-data="{ pilih: @js(old('template')),
                            daftar: @js($templates->map(fn ($t) => [
                                'id'        => $t->id,
                                'nama'      => $t->title,
                                'body'      => $t->body,
                                'variables' => $t->jumlahVariabel(),
                            ])->values()),
                            get t() { return this.daftar.find(x => String(x.id) === String(this.pilih)) || null } }">
                @csrf

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Nomor Tujuan</label>
                    <input type="text" name="nomor" value="{{ old('nomor', $nomorAwal ?? '') }}" required
                           class="w-full border rounded px-3 py-2 text-sm font-mono"
                           placeholder="0855-777-4446">
                    <p class="text-xs text-gray-400 mt-1">Bentuk apa pun boleh — dinormalkan otomatis.</p>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Template</label>
                    <select name="template" x-model="pilih" required class="w-full border rounded px-3 py-2 text-sm">
                        <option value="">— pilih —</option>
                        @foreach($templates as $t)
                            <option value="{{ $t->id }}">{{ $t->title }} &middot; {{ $t->meta_name }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Isian dibuat sebanyak variabel template, dan bunyinya ditampilkan
                     utuh — admin harus melihat kalimat yang akan diterima pelanggan
                     SEBELUM menekan kirim, karena template tidak bisa ditarik kembali. --}}
                <template x-if="t && t.variables > 0">
                    <div class="space-y-2">
                        <template x-for="i in t.variables" :key="i">
                            <div>
                                <label class="block text-xs text-gray-500 mb-1" x-text="'Isian ke-' + i"></label>
                                <input type="text" :name="'variabel[' + (i - 1) + ']'" required
                                       class="w-full border rounded px-3 py-2 text-sm">
                            </div>
                        </template>
                    </div>
                </template>

                <div x-show="t" x-cloak>
                    <div class="text-xs text-gray-500 mb-1">Bunyi template</div>
                    <div class="rounded border bg-gray-50 px-3 py-2 text-sm whitespace-pre-wrap" x-text="t?.body"></div>
                </div>

                <div class="pt-1">
                    <button class="bg-blue-600 text-white px-4 py-2 rounded text-sm">Kirim &amp; Mulai Percakapan</button>
                </div>
            </form>
        @endif
    </div>
</div>
@endsection
