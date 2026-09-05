{{-- Satu gelembung pesan. Dipisah karena dipakai DUA jalur: render awal
     halaman, dan penambahan lewat fetch (kirim & pesan masuk). Kalau
     markupnya digandakan, gelembung baru cepat berbeda dari yang lama. --}}
@foreach($pesan as $m)
                @php $masuk = $m->isInbound(); @endphp
                <div class="flex {{ $masuk ? 'justify-start' : 'justify-end' }}">
                    <div class="max-w-[80%] rounded-lg px-3 py-2 text-sm shadow-sm {{ $masuk ? 'bg-white' : 'bg-[#d9fdd3]' }}">
                        @if($m->content)
                            <div class="whitespace-pre-wrap">{{ $m->content }}</div>
                        @endif

                        @foreach($m->attachments as $l)
                            <div class="mt-2">
                                @if($l->sudahDisapu())
                                    <div class="text-xs text-gray-500 italic">
                                        Lampiran dihapus otomatis {{ $l->purged_at->translatedFormat('d M Y') }} (lewat masa simpan).
                                    </div>
                                @elseif(! $l->tersimpanAman())
                                    <div class="text-xs text-amber-700">
                                        Lampiran belum tersimpan{{ $l->download_error ? ': ' . $l->download_error : ' — menunggu diunduh.' }}
                                    </div>
                                @elseif($l->isGambar())
                                    <a href="{{ route('crm.inbox.lampiran', $l->id) }}" target="_blank">
                                        <img src="{{ route('crm.inbox.lampiran', $l->id) }}" alt="{{ $l->original_name }}"
                                             class="rounded border max-h-56">
                                    </a>
                                @else
                                    <a href="{{ route('crm.inbox.lampiran', $l->id) }}"
                                       class="inline-block border border-gray-300 rounded px-2 py-1 text-xs hover:bg-gray-50">
                                        ⬇ {{ $l->original_name ?: 'Unduh lampiran' }}
                                    </a>
                                @endif
                            </div>
                        @endforeach

                        <div class="mt-1 text-[11px] text-gray-500 flex items-center gap-2">
                            <span>{{ $m->sent_at?->translatedFormat('d M Y H:i') }}</span>
                            @if($m->dibalasDariHp())
                                {{-- Kebocoran triase: dibalas langsung dari HP, jadi tidak lewat
                                     penugasan, catatan, maupun antrean di layar ini. --}}
                                <span class="px-1.5 py-0.5 rounded bg-amber-100 text-amber-800" title="Dibalas langsung dari aplikasi WhatsApp, bukan dari ERP">dari HP</span>
                            @endif
                            @if($m->status === 'failed')
                                <span class="px-1.5 py-0.5 rounded bg-red-100 text-red-700" title="{{ $m->error }}">gagal</span>
                            @elseif($m->status === \App\Modules\CRM\Models\CrmMessage::STATUS_TIDAK_DIKIRIM)
                                {{-- Tercatat tapi tidak keluar (mode aman). Tanpa penanda ini
                                     admin mengira pelanggan sudah dijawab. --}}
                                <span class="px-1.5 py-0.5 rounded bg-amber-100 text-amber-800" title="Mode aman menyala: pesan ini tidak dikirim ke pelanggan">tidak dikirim</span>
                            @endif
                        </div>
                    </div>
                </div>
@endforeach
