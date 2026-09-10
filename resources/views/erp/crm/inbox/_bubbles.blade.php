{{-- Satu gelembung pesan. Dipisah karena dipakai DUA jalur: render awal
     halaman, dan penambahan lewat fetch (kirim & pesan masuk). Kalau
     markupnya digandakan, gelembung baru cepat berbeda dari yang lama. --}}
@foreach($pesan as $m)
                @php
                    $masuk = $m->isInbound();
                    /*
                     * Kutipan menunjuk pesan lewat WAMID Meta. Pesan keluar baru
                     * punya wamid setelah SAMPAI (ia menumpang webhook
                     * 'delivered'), jadi tombol Balas pada pesan yang baru saja
                     * dikirim memang belum muncul — dan itu jujur: mengutipnya
                     * sekarang akan diterima API lalu diabaikan diam-diam.
                     */
                    $bisaDikutip     = $m->bisaDikutip();
                    $bisaDiteruskan  = trim((string) $m->content) !== '' || $m->attachments->isNotEmpty();
                    $adaAksi         = $bisaDikutip || $bisaDiteruskan;
                @endphp
                {{-- data-mid: pegangan buat menaikkan centang jadi dua/biru saat
                     webhook 'delivered'/'read' datang, tanpa menggambar ulang
                     gelembungnya.
                     data-pmid & kawan-kawan: bekal menu aksi (balas/teruskan),
                     yang dikelola SATU penangan di thread — bukan Alpine per
                     gelembung, karena gelembung datang belakangan lewat
                     insertAdjacentHTML dan tak selalu ikut terinisialisasi. --}}
                <div class="group flex {{ $masuk ? 'justify-start' : 'justify-end' }}"
                     data-mid="{{ $m->id }}"
                     data-masuk="{{ $masuk ? 1 : 0 }}"
                     data-pmid="{{ $m->idKutipan() }}"
                     data-nama="{{ $m->labelPengirim() }}"
                     data-ringkas="{{ $m->ringkas() }}"
                     data-bisa-kutip="{{ $bisaDikutip ? 1 : 0 }}"
                     data-bisa-terus="{{ $bisaDiteruskan ? 1 : 0 }}">
                    {{-- Ruang kanan disisakan saat tombolnya ada (pr-8), bukan padding
                         seragam: chevron melayang di atas isi gelembung, dan pada
                         pesan yang baris pertamanya panjang huruf-hurufnya masuk ke
                         bawah tombol lalu terbaca setengah. --}}
                    <div class="relative max-w-[80%] rounded-lg py-2 text-sm shadow-sm {{ $adaAksi ? 'pl-3 pr-8' : 'px-3' }} {{ $masuk ? 'bg-white' : 'bg-[#d9fdd3]' }}">
                        {{-- Chevron aksi di pojok kanan atas, seperti WhatsApp.
                             SELALU terlihat, tidak menunggu kursor: kalau disembunyikan
                             sampai disentuh, tak ada satu pun petunjuk bahwa gelembungnya
                             bisa ditindaklanjuti — orang harus menemukannya secara tak
                             sengaja. Warnanya dibuat samar supaya thread tetap tenang,
                             lalu menegas saat kursor lewat. --}}
                        @if($adaAksi)
                            <button type="button" data-aksi-pesan
                                    title="Balas atau teruskan"
                                    aria-label="Aksi pesan"
                                    class="absolute top-0.5 right-0.5 w-6 h-6 flex items-center justify-center rounded
                                           text-gray-400 hover:text-gray-700 group-hover:text-gray-600
                                           hover:bg-black/10 transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/>
                                </svg>
                            </button>
                        @endif

                        @if($m->forwarded_from_message_id)
                            {{-- Label "Diteruskan" ini HANYA ada di ERP. Cloud API tidak
                                 punya penanda teruskan, jadi di HP penerima pesan ini
                                 tampak seperti pesan biasa — jangan sampai ada yang
                                 mengira pelanggan ikut melihat asalnya. --}}
                            <div class="mb-1 flex items-center gap-1 text-[11px] text-gray-500 italic"
                                 title="Disalin dari chat {{ $m->diteruskanDari?->conversation?->namaTampil() ?? 'lain' }} — pelanggan tidak melihat keterangan ini">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5-5 5M6 7l5 5-5 5"/>
                                </svg>
                                Diteruskan
                            </div>
                        @endif

                        @if($m->reply_to_wam_id)
                            @include('erp.crm.inbox._kutipan', ['dikutip' => $m->pesanDikutip()])
                        @endif

                        {{-- Media DI ATAS teks, seperti WhatsApp: di HP pelanggan yang
                             terlihat lebih dulu adalah gambarnya, dan captionnya
                             duduk di bawahnya. Kalau di sini urutannya terbalik,
                             admin memeriksa sesuatu yang bentuknya bukan yang
                             diterima pelanggan. --}}
                        @if($m->attachments->isNotEmpty())
                            <div class="space-y-2 {{ $m->content ? 'mb-2' : '' }}">
                                @foreach($m->attachments as $l)
                                    <div>
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
                            </div>
                        @endif

                        {{-- Tautan dibuat bisa diklik: thread ini dipakai MEMERIKSA apa
                             yang sudah terkirim, dan pemeriksaan itu setengah jalan
                             kalau alamatnya harus disalin dulu ke tab baru. Isinya
                             di-escape lebih dulu di dalam TeksPesan — lihat di sana
                             kenapa urutannya tidak boleh dibalik. --}}
                        @if($m->content)
                            <div class="whitespace-pre-wrap">{!! \App\Modules\CRM\Support\TeksPesan::tautkan($m->content) !!}</div>
                        @endif

                        {{-- Tombol balasan cepat yang IKUT dikirim ke pelanggan.

                             Digambar meniru WhatsApp — dipisah garis, teks biru,
                             selebar gelembung — karena inilah yang benar-benar
                             dilihat pelanggan di HP-nya. Sebelum ini gelembung
                             kita cuma menampilkan teksnya, dan kalimat "silakan
                             tekan tombol di bawah ini" tampil tanpa tombol apa
                             pun: yang terbaca admin adalah fiturnya rusak,
                             padahal tombolnya sampai dengan baik.

                             MATI di sisi kita, dan memang begitu seharusnya:
                             yang menekannya pelanggan. Ditandai lewat title,
                             bukan dibuat seolah bisa diklik lalu diam saja. --}}
                        @php $tombolCepat = $m->tombolBalasanCepat(); @endphp
                        @if($tombolCepat)
                            <div class="mt-2 -mx-3 border-t border-black/10"
                                 title="Tombol ini dilihat &amp; ditekan pelanggan di WhatsApp — tekanannya masuk ke sini sebagai pesan baru">
                                @foreach($tombolCepat as $judul)
                                    <div class="px-3 py-1.5 text-center text-[13px] font-medium text-sky-700 {{ ! $loop->first ? 'border-t border-black/10' : '' }}">
                                        <svg class="inline-block w-3.5 h-3.5 -mt-0.5 mr-1" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h11M9 21V3m0 18-6-6m6 6 6-6M21 6h-4"/>
                                        </svg>
                                        {{ $judul }}
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <div class="mt-1 text-[11px] text-gray-500 flex items-center gap-2 {{ $masuk ? '' : 'justify-end' }}">
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
                            @if($m->centang() !== 'tidak')
                                @include('erp.crm.inbox._centang', ['status' => $m->centang()])
                            @endif
                        </div>
                    </div>
                </div>
@endforeach
