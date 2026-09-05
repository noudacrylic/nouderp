<?php

namespace App\Modules\CRM\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmAttachment;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Models\CrmSnippet;
use App\Modules\CRM\Services\CrmReplyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Inbox CRM: daftar percakapan, triase, kepemilikan, dan balasan.
 *
 * Antreannya berbasis "BOLA DI SIAPA", bukan sekadar "sudah/belum dikerjakan".
 * Diskusi custom menggantung berminggu-minggu; tanpa pemisahan itu, percakapan
 * lama yang sedang menunggu jawaban pelanggan akan menenggelamkan yang benar-
 * benar mendesak, dan daftarnya berhenti dipercaya.
 */
class CrmInboxController extends Controller
{
    public function index(Request $request)
    {
        return view('erp.crm.inbox.workspace', $this->ruangKerja($request));
    }

    /**
     * Data satu layar kerja: daftar kiri + (opsional) thread tengah + rail kanan.
     *
     * Dipakai index() maupun show() supaya kolom kirinya IDENTIK — termasuk
     * filter, pembatas per agen, dan penomoran halaman. Kalau daftarnya disusun
     * dua kali dengan aturan yang sedikit beda, membuka satu thread akan
     * mengubah isi daftar di sebelahnya tanpa sebab yang terlihat.
     */
    private function ruangKerja(Request $request, ?CrmConversation $terpilih = null): array
    {
        $antrean = $request->string('antrean')->toString();
        $status  = $request->string('status')->toString() ?: CrmConversation::STATUS_AKTIF;

        /*
         * Daftar BAWAAN untuk agen non-super-admin hanya berisi chat miliknya —
         * tapi ini penyaringan tampilan, BUKAN penguncian: begitu ia memilih
         * pemilik lain atau mencari, seluruh percakapan tetap terbuka, dan
         * thread mana pun tetap bisa dibuka lewat tautan langsung. Sengaja
         * begitu: saat satu orang berhalangan, chat pelanggannya tidak boleh
         * jadi tak terlihat siapa pun.
         */
        $pengguna      = $request->user();
        $lihatSemua    = (bool) $pengguna?->isSuperAdmin();
        $memilihSendiri = $request->filled('pemilik') || $request->filled('search');
        $dibatasiKeSaya = ! $lihatSemua && ! $memilihSendiri && $pengguna;

        $percakapan = CrmConversation::query()
            ->with(['customer:id,name', 'owner:id,name'])
            ->where('status', $status)
            ->when($antrean, fn ($q) => $q->where('queue_state', $antrean))
            // 'semua' = permintaan sadar untuk melepas pembatas bawaan, jadi ia
            // TIDAK menyaring apa pun. Tanpa cabang ini nilainya jatuh ke
            // where('owner_user_id', 'semua') dan daftarnya kosong melompong.
            ->when($request->filled('pemilik') && $request->pemilik !== 'semua', function ($q) use ($request) {
                $request->pemilik === 'belum'
                    ? $q->whereNull('owner_user_id')
                    : $q->where('owner_user_id', $request->pemilik);
            })
            ->when($dibatasiKeSaya, fn ($q) => $q->where('owner_user_id', $pengguna->id))
            ->when($request->filled('search'), function ($q) use ($request) {
                $cari = trim((string) $request->search);
                $q->where(fn ($w) => $w
                    ->where('contact_key', 'like', "%{$cari}%")
                    ->orWhere('display_name', 'like', "%{$cari}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$cari}%")));
            })
            // Yang belum pernah ada pesannya pun harus muncul; ORDER BY kolom
            // nullable menaruhnya di ujung, jadi dipakai created_at sebagai jaring.
            ->orderByRaw('COALESCE(last_message_at, created_at) DESC')
            ->paginate(per_page_size())
            ->withQueryString();

        return [
            'percakapan' => $percakapan,
            'jumlah'     => $this->jumlahPerAntrean(),
            'pemilikOpsi' => User::assignable()->orderBy('name')->get(['id', 'name']),
            'dryRun'     => app(ChatManager::class)->isDryRun(),
            'dibatasiKeSaya' => $dibatasiKeSaya,
            /*
             * Jumlah yang BELUM dipegang siapa pun ditampilkan ke semua orang.
             * Tanpa angka ini, chat pelanggan baru (yang memang lahir tanpa
             * pemilik) tidak muncul di daftar bawaan agen dan bisa menganggur
             * berjam-jam tanpa ada yang tahu ia ada.
             */
            'belumDioper' => CrmConversation::query()
                ->where('status', CrmConversation::STATUS_AKTIF)
                ->whereNull('owner_user_id')
                ->count(),
            'terpilih' => $terpilih,
            'pesan'    => $terpilih
                ? $terpilih->messages()->with('attachments')->orderBy('sent_at')->orderBy('id')->get()
                : collect(),
            'snippets' => $this->snippets(),
            'isian'    => CrmSnippet::ISIAN,
        ];
    }

    /** Potongan balasan untuk rail kanan; yang paling sering dipakai di atas. */
    private function snippets()
    {
        return CrmSnippet::aktif()
            ->orderBy('sort_order')
            ->orderByDesc('used_count')
            ->orderBy('title')
            ->get();
    }

    public function show(Request $request, CrmConversation $conversation)
    {
        $conversation->load(['customer', 'owner']);

        /*
         * Membuka thread menandai TERBACA, tapi TIDAK memindahkan antrean.
         * Membaca bukan menjawab — kalau membuka saja sudah menggeser bola ke
         * pelanggan, pekerjaan yang belum dikerjakan akan hilang dari daftar.
         */
        if ($conversation->unread_count > 0) {
            $conversation->forceFill(['unread_count' => 0])->save();
        }

        return view('erp.crm.inbox.workspace', $this->ruangKerja($request, $conversation));
    }

    public function balas(Request $request, CrmConversation $conversation, CrmReplyService $balasan)
    {
        /*
         * Kotak ketik mengirim lewat FormData, dan input berkas yang KOSONG
         * tetap ikut terkirim sebagai entri hampa. Kalau dibiarkan, aturan
         * 'file|image' menolaknya dan balasan teks biasa gagal dengan pesan
         * "data tidak valid" yang tidak menjelaskan apa-apa.
         */
        if ($request->hasFile('gambar')) {
            $berkas = array_values(array_filter(
                (array) $request->file('gambar'),
                fn ($f) => $f !== null && $f->getSize() > 0
            ));

            $berkas ? $request->files->set('gambar', $berkas) : $request->files->remove('gambar');
        }

        $aturan = [
            // Teks boleh kosong ASAL ada gambar — admin sering menempel tangkapan
            // layar tanpa satu kata pun ("ini maksud saya"), dan menuntut teks
            // di situ cuma memaksa mengetik titik.
            'teks'     => 'required_without:gambar|nullable|string|max:4000',
            'reply_to' => 'nullable|string|max:120',
            'gambar'   => 'nullable|array|max:5',
            /*
             * Semua jenis berkas boleh — WhatsApp memang menerima gambar, video,
             * audio, dan dokumen. Batas ukurannya BERBEDA per jenis, jadi yang
             * diperiksa di sini cuma pagar tertingginya; jenis & batas
             * sesungguhnya diputuskan MediaKind di bawah.
             */
            'gambar.*' => 'file|max:' . (int) (\App\Modules\CRM\Support\MediaKind::batasTertinggi() / 1024),
        ];

        $pesanGalat = [
            'gambar.*.max'  => 'Berkas terlalu besar untuk WhatsApp.',
            'gambar.*.file' => 'Berkas gagal terunggah — coba lampirkan ulang.',
        ];

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), $aturan, $pesanGalat);

        /*
         * Batas per JENIS diperiksa di sini, bukan lewat aturan tunggal: gambar
         * 5 MB, video/audio 16 MB, dokumen 100 MB. Ditolak sekarang dengan
         * kalimat yang menyebut jenis & batasnya, bukan setelah berkasnya
         * terlanjur terunggah ke vendor lalu ditolak Meta tanpa keterangan.
         */
        $validator->after(function ($v) use ($request) {
            foreach ((array) $request->file('gambar') as $i => $berkas) {
                if (! $berkas) {
                    continue;
                }

                $jenis = \App\Modules\CRM\Support\MediaKind::for($berkas->getClientMimeType());

                if ($berkas->getSize() > $jenis['max_bytes']) {
                    $v->errors()->add(
                        "gambar.{$i}",
                        $berkas->getClientOriginalName() . ' melebihi batas '
                        . \App\Modules\CRM\Support\MediaKind::keterangan($jenis['type']) . '.'
                    );
                }
            }
        });

        if ($validator->fails()) {
            /*
             * Kegagalan unggah nyaris mustahil didiagnosis dari layar: pesannya
             * generik dan berkasnya sudah lenyap. Jadi keadaan berkasnya dicatat
             * apa adanya — nama, ukuran, mime, dan KODE GALAT PHP, yang biasanya
             * justru itu penyebabnya (melebihi batas ini, folder temp tak bisa
             * ditulis, unggahan terpotong).
             */
            Log::warning('[CRM] balasan ditolak validasi', [
                'errors' => $validator->errors()->toArray(),
                'berkas' => collect((array) $request->file('gambar'))->map(fn ($f) => $f ? [
                    'nama'  => $f->getClientOriginalName(),
                    'mime'  => $f->getClientMimeType(),
                    'ukuran' => $f->getSize(),
                    'valid' => $f->isValid(),
                    'kode'  => $f->getError(),
                    'pesan' => $f->getErrorMessage(),
                ] : null)->all(),
            ]);

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error'   => implode(' ', $validator->errors()->all()),
                    'errors'  => $validator->errors()->toArray(),
                ], 422);
            }

            return back()->withInput()->withErrors($validator);
        }

        $data = $validator->validated();

        $hasil = $balasan->balas(
            $conversation,
            (string) ($data['teks'] ?? ''),
            $request->user()?->id,
            $data['reply_to'] ?? null,
            $request->file('gambar', [])
        );

        /*
         * Permintaan dari kotak ketik dikirim lewat fetch, jadi jawabannya JSON
         * berisi gelembung siap tempel. Tanpa ini seluruh halaman dimuat ulang
         * tiap kali mengirim — posisi gulir hilang, thread berkedip, dan
         * mengetik cepat jadi menyiksa.
         */
        if ($request->wantsJson()) {
            if (! $hasil['success']) {
                return response()->json(['success' => false, 'error' => $hasil['error']], 422);
            }

            $baru = $conversation->messages()
                ->with('attachments')
                ->where('id', '>', (int) $request->input('after', 0))
                ->orderBy('sent_at')->orderBy('id')
                ->get();

            return response()->json([
                'success' => true,
                'html'    => view('erp.crm.inbox._bubbles', ['pesan' => $baru])->render(),
                'last_id' => (int) ($baru->max('id') ?: $request->input('after', 0)),
            ]);
        }

        return $hasil['success']
            ? back()->with('success', 'Balasan terkirim.')
            : back()->withInput()->with('error', $hasil['error']);
    }

    /**
     * Cari produk etalase untuk rail Produk.
     *
     * Hanya produk BERSTATUS TERBIT yang boleh muncul: mengirim tautan produk
     * yang masih draf berarti pelanggan membuka halaman 404 — kesalahan yang
     * tak bisa ditarik kembali setelah pesannya terkirim.
     */
    public function cariProduk(Request $request)
    {
        $cari = trim((string) $request->input('q', ''));
        $basis = rtrim((string) config('crm.storefront_url'), '/');

        $produk = \App\Models\StoreProduct::published()
            // Dicari lewat nama MAUPUN SKU: admin yang sedang membalas biasanya
            // sudah memegang SKU dari percakapan, bukan nama panjang produknya.
            ->when($cari !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$cari}%")
                ->orWhereHas('variants.product', fn ($v) => $v->where('sku', 'like', "%{$cari}%"))))
            ->with(['variants' => fn ($q) => $q->with('product:id,sku')->orderBy('sort_order')->limit(1)])
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(12)
            ->get(['id', 'name', 'slug', 'short_description']);

        return response()->json([
            'produk' => $produk->map(fn ($p) => [
                'nama'    => $p->name,
                'sku'     => optional($p->variants->first()?->product)->sku,
                'ringkas' => \Illuminate\Support\Str::limit((string) $p->short_description, 70),
                'url'     => $basis . '/produk/' . $p->slug,
            ])->all(),
        ]);
    }

    /**
     * Sajikan satu lampiran KELUAR lewat tautan bertanda tangan — TANPA login.
     *
     * Ada karena Meta harus mengambil sendiri berkas yang kita kirim; vendor
     * hanya menerima `media_url`, bukan berkas yang diunggah. Tiga pagarnya:
     *
     *  1. Tanda tangan (middleware `signed`) — alamatnya tak bisa ditebak
     *     maupun diubah, dan mati sendiri setelah beberapa menit.
     *  2. HANYA arah KELUAR. Berkas kiriman PELANGGAN tak pernah bisa diambil
     *     lewat rute ini, bahkan dengan tanda tangan yang sah — itu batas yang
     *     memisahkan "berkas yang memang sedang kami kirim" dari "seluruh isi
     *     lampiran pelanggan".
     *  3. Berkas yang sudah disapu masa simpan menjawab 410, bukan 404 kosong.
     */
    public function mediaPublik(CrmAttachment $attachment)
    {
        abort_unless($attachment->message?->direction === CrmMessage::KELUAR, 404);

        if ($attachment->sudahDisapu()) {
            abort(410, 'Lampiran sudah dihapus otomatis (lewat masa simpan).');
        }

        abort_unless($attachment->tersimpanAman(), 404);

        return Storage::disk($attachment->disk)->response(
            $attachment->path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime ?: 'application/octet-stream']
        );
    }

    /**
     * Unduh lampiran yang masih tertunda, sekarang juga.
     *
     * Pengunduhnya memang berjalan lewat penjadwal (sengaja: mengunduh di dalam
     * permintaan webhook membuat vendor menunggu, mengulang, lalu mematikan
     * endpoint). Tapi penjadwal bisa mati — di lokal ia bahkan tak pernah hidup —
     * dan tanpa tombol ini gejalanya adalah lampiran yang menggantung selamanya
     * dengan tulisan "menunggu diunduh" tanpa ada yang bisa dilakukan admin.
     */
    public function unduhLampiran(\App\Modules\CRM\Services\CrmMediaStore $media)
    {
        $tertunda = CrmAttachment::belumTerunduh()->limit(50)->get();

        if ($tertunda->isEmpty()) {
            return back()->with('success', 'Tidak ada lampiran yang menunggu diunduh.');
        }

        $berhasil = $tertunda->filter(fn (CrmAttachment $l) => $media->unduh($l))->count();
        $gagal    = $tertunda->count() - $berhasil;

        return back()->with(
            $gagal ? 'error' : 'success',
            "{$berhasil} lampiran tersimpan" . ($gagal ? ", {$gagal} gagal — alasannya tertulis di tiap lampiran." : '.')
        );
    }

    /** Simpan potongan balasan baru dari rail kanan. */
    public function simpanSnippet(Request $request)
    {
        $data = $request->validate([
            'title'    => 'required|string|max:120',
            'body'     => 'required|string|max:2000',
            'category' => 'nullable|string|max:60',
        ]);

        CrmSnippet::create($data + ['created_by' => $request->user()?->id]);

        return back()->with('success', 'Potongan balasan disimpan.');
    }

    /** Hapus potongan balasan. */
    public function hapusSnippet(CrmSnippet $snippet)
    {
        $snippet->delete();

        return back()->with('success', 'Potongan balasan dihapus.');
    }

    /**
     * Pesan yang lahir SESUDAH id tertentu — dipanggil berkala oleh thread.
     *
     * Dibuat sesederhana mungkin (kirim id terakhir, terima gelembung siap
     * tempel) karena inilah satu-satunya bagian yang jalan terus-menerus:
     * apa pun yang mahal di sini akan dikerjakan ratusan kali sejam.
     */
    public function pesanBaru(Request $request, CrmConversation $conversation)
    {
        $setelah = (int) $request->input('after', 0);

        $baru = $conversation->messages()
            ->with('attachments')
            ->where('id', '>', $setelah)
            ->orderBy('sent_at')->orderBy('id')
            ->get();

        // Pesan yang sudah tampil di layar admin sama saja dengan sudah dibaca;
        // membiarkan penghitung menumpuk membuat lencana "baru" berbohong.
        if ($baru->isNotEmpty() && $conversation->unread_count > 0) {
            $conversation->forceFill(['unread_count' => 0])->save();
        }

        return response()->json([
            'html'    => $baru->isEmpty() ? '' : view('erp.crm.inbox._bubbles', ['pesan' => $baru])->render(),
            'last_id' => (int) ($baru->max('id') ?: $setelah),
            'window_open' => $conversation->windowIsOpen(),
        ]);
    }

    /** Oper percakapan ke admin lain — pengganti rotator otomatis yang ditunda. */
    public function oper(Request $request, CrmConversation $conversation)
    {
        $data = $request->validate([
            'owner_user_id' => ['nullable', User::assignableExistsRule()],
        ]);

        $conversation->forceFill(['owner_user_id' => $data['owner_user_id'] ?: null])->save();

        return back()->with('success', $data['owner_user_id'] ? 'Percakapan dioper.' : 'Kepemilikan dilepas.');
    }

    public function antrean(Request $request, CrmConversation $conversation)
    {
        $data = $request->validate([
            'queue_state' => ['required', Rule::in(array_keys(CrmConversation::QUEUE_LABELS))],
        ]);

        $conversation->forceFill(['queue_state' => $data['queue_state']])->save();

        return back()->with('success', 'Antrean diperbarui: ' . CrmConversation::QUEUE_LABELS[$data['queue_state']] . '.');
    }

    public function arsip(CrmConversation $conversation)
    {
        $keArsip = $conversation->status === CrmConversation::STATUS_AKTIF;

        $conversation->forceFill([
            'status' => $keArsip ? CrmConversation::STATUS_ARSIP : CrmConversation::STATUS_AKTIF,
        ])->save();

        return back()->with('success', $keArsip ? 'Percakapan diarsipkan.' : 'Percakapan diaktifkan lagi.');
    }

    public function catatan(Request $request, CrmConversation $conversation)
    {
        $data = $request->validate(['notes' => 'nullable|string|max:5000']);

        $conversation->forceFill(['notes' => $data['notes']])->save();

        return back()->with('success', 'Catatan disimpan.');
    }

    /**
     * Sajikan lampiran. Berkasnya di disk privat, jadi HARUS lewat sini —
     * isinya milik pelanggan dan tidak boleh terbaca siapa pun yang menebak URL.
     */
    public function lampiran(CrmAttachment $attachment)
    {
        abort_if($attachment->sudahDisapu(), 410, 'Lampiran sudah dihapus otomatis karena lewat masa simpan.');
        abort_unless($attachment->tersimpanAman(), 404, 'Lampiran belum tersimpan di penyimpanan kita.');

        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        return $disk->response(
            $attachment->path,
            $attachment->original_name ?: basename($attachment->path),
            ['Content-Type' => $attachment->mime ?: 'application/octet-stream']
        );
    }

    /** Jumlah percakapan aktif per antrean — angka di tab. */
    private function jumlahPerAntrean(): array
    {
        $jumlah = CrmConversation::query()
            ->where('status', CrmConversation::STATUS_AKTIF)
            ->selectRaw('queue_state, COUNT(*) as total')
            ->groupBy('queue_state')
            ->pluck('total', 'queue_state')
            ->all();

        return collect(CrmConversation::QUEUE_LABELS)
            ->map(fn ($label, $key) => (int) ($jumlah[$key] ?? 0))
            ->all();
    }
}
