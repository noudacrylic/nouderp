<?php

namespace App\Modules\CRM\Controllers;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductLink;
use App\Core\Inventory\Warehouse;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmAttachment;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmLabel;
use App\Modules\CRM\Models\CrmMarketplaceLink;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Models\CrmTemplate;
use App\Modules\CRM\Models\CrmStockWatch;
use App\Modules\CRM\Services\CrmReplyService;
use App\Modules\CRM\Services\NotifikasiChatService;
use App\Modules\CRM\Services\PencarianProdukService;
use App\Modules\CRM\Support\PhoneNumber;
use App\Modules\CRM\Services\StockWatchService;
use App\Modules\CRM\Services\WahaHealthService;
use App\Modules\CRM\Services\WebhookHealthService;
use App\Models\ProductPrice;
use App\Modules\Marketplace\Jubelio\Services\JubelioProductSyncService;
use App\Modules\Marketplace\Jubelio\Services\JubelioStockSyncService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\PromotionService;
use App\Modules\Sales\Services\SalesOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
    /**
     * Relasi yang WAJIB ikut termuat setiap kali gelembung digambar.
     *
     * Dikumpulkan jadi satu tetapan karena gelembungnya disusun dari TIGA
     * tempat (muat halaman, jawaban kirim, polling pesan baru) memakai partial
     * yang sama. Kalau daftarnya ditulis ulang di masing-masing, satu yang
     * tertinggal menghasilkan N+1 diam-diam — kutipan tiap gelembung ditanya
     * satu per satu ke basis data, dan cuma terasa saat thread sudah panjang.
     */
    private const MUATAN_GELEMBUNG = [
        'attachments',
        'dikutipWamid.attachments',
        'dikutipVendor.attachments',
        'diteruskanDari.conversation.customer:id,name',
    ];

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
        /*
         * Saringannya sendiri hidup di dasarPercakapan() & saringPercakapan() —
         * dipisah supaya tombol Ekspor memakai saringan yang sama persis dengan
         * daftar yang sedang dilihat orang. Yang tinggal di sini hanya dua
         * penanda yang ikut dibaca layar.
         */
        $pengguna       = $request->user();
        $lihatSemua     = (bool) $pengguna?->isSuperAdmin();
        $dibatasiKeSaya = ! $lihatSemua && ! ($request->filled('pemilik') || $request->filled('search')) && $pengguna;

        $dasar = $this->dasarPercakapan($request);

        $percakapan = $this->saringPercakapan($request, $dasar)
            ->with(['customer:id,name', 'owner:id,name'])
            ->paginate(per_page_size())
            ->withQueryString();

        return [
            'percakapan' => $percakapan,
            'jumlah'     => $this->jumlahPerLabel($dasar),
            'labelOpsi'  => CrmLabel::terpakai(),
            'jumlahSemua'      => (clone $dasar())->count(),
            'jumlahBelumDibaca'=> (clone $dasar())->where('unread_count', '>', 0)->count(),
            'lihatSemua'       => $lihatSemua,
            'pemilikOpsi' => User::assignable()->orderBy('name')->get(['id', 'name']),
            'dryRun'     => app(ChatManager::class)->isDryRun(),
            'dibatasiKeSaya' => $dibatasiKeSaya,
            /*
             * Mode aman memang tidak memancing kabar balik apa pun, jadi
             * pemeriksanya dilewati — kalau tidak, pita peringatan menyala
             * terus dan berhenti dipercaya justru saat betulan rusak.
             */
            'webhookSepi' => app(ChatManager::class)->isDryRun()
                ? null
                : app(WebhookHealthService::class)->sepi(),
            /*
             * Status sesi WAHA dibaca dari hasil pemeriksaan TERAKHIR yang
             * disimpan penjadwal, bukan dengan menelepon WAHA di sini. Kalau
             * dipanggil langsung, layar inbox menggantung sampai timeout
             * justru pada saat WAHA-nya sedang mati — yaitu saat pita ini
             * paling dibutuhkan.
             */
            'wahaStatus' => app(WahaHealthService::class)->statusTersimpan(),
            /*
             * Gudang asal untuk panel ongkir di rail. Hanya yang aktif — gudang
             * mati tetap muncul di daftar cuma untuk ditolak saat dicek.
             */
            'gudang'         => Warehouse::where('is_active', 1)->orderBy('name')->get(['id', 'name']),
            'gudangTerpilih' => Warehouse::defaultId(),
            'pesananTerkait' => $terpilih ? $this->ringkasPesanan($terpilih) : [],
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
                ? $terpilih->messages()->with(self::MUATAN_GELEMBUNG)->orderBy('sent_at')->orderBy('id')->get()
                : collect(),
            'snippets' => $this->snippets(),
            /*
             * Template pembuka chat untuk popup "Mulai Chat". Hanya yang sudah
             * APPROVED di Meta: di luar jendela 24 jam tidak ada jalur lain,
             * dan template yang masih PENDING ditolak vendor saat dikirim —
             * gagalnya baru ketahuan sesudah admin mengetik isian.
             */
            'templateMeta' => CrmTemplate::aktif()
                ->keMeta()
                ->where('meta_status', CrmTemplate::META_APPROVED)
                ->orderBy('title')
                ->get(),
        ];
    }

    /**
     * Saringan PEMILIK + status, sebagai penutup karena dipakai dua kali:
     * sekali untuk daftarnya, sekali untuk angka di tiap tombol label. Kalau
     * angkanya dihitung dari seluruh percakapan sementara daftarnya cuma milik
     * satu agen, tombol bertuliskan "12" akan membuka daftar berisi dua — dan
     * angkanya berhenti dipercaya.
     *
     * Daftar BAWAAN untuk agen non-super-admin hanya berisi chat miliknya —
     * tapi ini penyaringan tampilan, BUKAN penguncian: begitu ia memilih
     * pemilik lain atau mencari, seluruh percakapan tetap terbuka, dan thread
     * mana pun tetap bisa dibuka lewat tautan langsung. Sengaja begitu: saat
     * satu orang berhalangan, chat pelanggannya tidak boleh jadi tak terlihat
     * siapa pun.
     */
    private function dasarPercakapan(Request $request): \Closure
    {
        $status         = $request->string('status')->toString() ?: CrmConversation::STATUS_AKTIF;
        $pengguna       = $request->user();
        $lihatSemua     = (bool) $pengguna?->isSuperAdmin();
        $memilihSendiri = $request->filled('pemilik') || $request->filled('search');
        $dibatasiKeSaya = ! $lihatSemua && ! $memilihSendiri && $pengguna;

        return function () use ($status, $request, $dibatasiKeSaya, $pengguna) {
            return CrmConversation::query()
                ->where('status', $status)
                // 'semua' = permintaan sadar untuk melepas pembatas bawaan, jadi ia
                // TIDAK menyaring apa pun. Tanpa cabang ini nilainya jatuh ke
                // where('owner_user_id', 'semua') dan daftarnya kosong melompong.
                ->when($request->filled('pemilik') && $request->pemilik !== 'semua', function ($q) use ($request) {
                    $request->pemilik === 'belum'
                        ? $q->whereNull('owner_user_id')
                        : $q->where('owner_user_id', $request->pemilik);
                })
                ->when($dibatasiKeSaya, fn ($q) => $q->where('owner_user_id', $pengguna->id));
        };
    }

    /**
     * Saringan yang terlihat di layar (label, belum dibaca, pencarian).
     *
     * Dipisah supaya Ekspor memakai saringan yang SAMA PERSIS dengan daftarnya.
     * Kalau disalin, tombol Ekspor cepat mengunduh kumpulan yang berbeda dari
     * yang sedang dilihat orang — dan itu baru ketahuan setelah berkasnya
     * dipakai menyusun pengetahuan agen.
     */
    private function saringPercakapan(Request $request, \Closure $dasar)
    {
        $antrean     = $request->string('antrean')->toString();
        $belumDibaca = $request->boolean('belum_dibaca');

        return $dasar()
            ->when($antrean, fn ($q) => $q->where('queue_state', $antrean))
            ->when($belumDibaca, fn ($q) => $q->where('unread_count', '>', 0))
            ->when($request->filled('search'), function ($q) use ($request) {
                $cari = trim((string) $request->search);
                $q->where(fn ($w) => $w
                    ->where('contact_key', 'like', "%{$cari}%")
                    ->orWhere('display_name', 'like', "%{$cari}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$cari}%"))
                    /*
                     * Nomor pesanan ikut dicari lewat pelanggannya — pertanyaan
                     * yang datang ke admin hampir selalu berbunyi "SO-xxxx itu
                     * chat yang mana?", dan tanpa jalur ini ia harus buka menu
                     * Penjualan dulu cuma untuk mendapatkan nomor teleponnya.
                     */
                    ->orWhereIn('customer_id', SalesOrder::query()
                        ->where('order_number', 'like', "%{$cari}%")
                        ->whereNotNull('customer_id')
                        ->select('customer_id')));
            })
            // Yang belum pernah ada pesannya pun harus muncul; ORDER BY kolom
            // nullable menaruhnya di ujung, jadi dipakai created_at sebagai jaring.
            ->orderByRaw('COALESCE(last_message_at, created_at) DESC');
    }

    /**
     * Unduh percakapan yang SEDANG DISARING sebagai satu berkas teks.
     *
     * Ini bahan mentah untuk menyusun pengetahuan agen: yang paling ampuh
     * membuat agen terdengar seperti kita bukan instruksi gaya, melainkan
     * puluhan balasan asli. Mengambilnya satu per satu dari layar terlalu
     * lambat untuk dikerjakan, jadi tidak akan pernah dikerjakan.
     *
     * Formatnya sengaja teks biasa, bukan CSV atau JSON: tujuannya dibaca dan
     * ditempel ke ruang diskusi, bukan diolah mesin.
     */
    public function ekspor(Request $request)
    {
        $batasChat  = 30;
        $batasPesan = 100;

        $percakapan = $this->saringPercakapan($request, $this->dasarPercakapan($request))
            ->with(['customer:id,name', 'owner:id,name'])
            ->limit($batasChat)
            ->get();

        $baris = [
            '# Ekspor percakapan CRM',
            '',
            'Diunduh: ' . now()->translatedFormat('l, d M Y H:i'),
            'Jumlah percakapan: ' . $percakapan->count() . ' (maksimal ' . $batasChat . ' per unduhan)',
        ];

        if ($request->filled('search')) {
            $baris[] = 'Pencarian: ' . $request->string('search');
        }

        if ($request->filled('antrean')) {
            $baris[] = 'Label: ' . CrmLabel::nama($request->string('antrean')->toString());
        }

        foreach ($percakapan as $p) {
            $pesan = $p->messages()
                ->with('attachments')
                ->latest('id')
                ->limit($batasPesan)
                ->get()
                ->reverse();

            $baris[] = '';
            $baris[] = '---';
            $baris[] = '';
            $baris[] = '## ' . $p->namaTampil() . ' (' . $p->contact_key . ')';
            $baris[] = '';
            $baris[] = '- Label: ' . CrmLabel::nama($p->queue_state);
            $baris[] = '- Pemilik: ' . ($p->owner?->name ?: 'belum dioper');
            $baris[] = '- Status: ' . ($p->customer_id ? 'pelanggan terdaftar' : 'lead');

            if (filled($p->notes)) {
                $baris[] = '- Catatan internal: ' . str_replace("\n", ' ', $p->notes);
            }

            $baris[] = '';

            if ($pesan->isEmpty()) {
                $baris[] = '_(belum ada pesan)_';
                continue;
            }

            foreach ($pesan as $m) {
                /*
                 * "Pelanggan" dan "Kami", bukan nama admin. Yang sedang disusun
                 * adalah contoh bagi agen — nama siapa yang kebetulan membalas
                 * hari itu cuma derau, dan menyertakannya mengundang agen
                 * memakai nama orang yang tidak ada di percakapan berikutnya.
                 */
                $siapa = $m->isInbound() ? 'Pelanggan' : 'Kami';
                $isi   = trim((string) $m->content);

                if ($isi === '' && $m->attachments->isNotEmpty()) {
                    $isi = '[' . $m->attachments->count() . ' lampiran]';
                }

                if ($isi === '') {
                    continue;
                }

                $baris[] = '**' . $siapa . '** (' . $m->sent_at?->format('d/m H:i') . '): '
                    . str_replace("\n", ' ', $isi);
            }
        }

        $nama = 'chat-crm-' . now()->format('Ymd-Hi') . '.md';

        return response(implode("\n", $baris), 200, [
            'Content-Type'        => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $nama . '"',
        ]);
    }

    /**
     * Template untuk pintasan "/" di kotak ketik; yang paling sering dipakai di atas.
     *
     * HANYA yang milik sendiri. Yang didaftarkan ke Meta bertebaran {{1}},
     * {{2}} di dalam bodynya — disisipkan mentah ke kotak ketik, yang sampai ke
     * pelanggan adalah kalimat berkurung kurawal. Tempatnya di layar Chat Baru,
     * yang memang meminta isian tiap variabel.
     */
    private function snippets()
    {
        return CrmTemplate::aktif()
            ->milikSendiri()
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
            // wamid Meta panjangnya ~80 karakter; batasnya dilonggarkan supaya
            // kutipan tidak ditolak validasi hanya karena bentuk id berubah.
            'reply_to' => 'nullable|string|max:255',
            // Penanda sekali-kirim dari kotak ketik; lihat penjaga di bawah.
            'kirim_key' => 'nullable|string|max:64',
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

        /*
         * Penjaga sekali-kirim.
         *
         * Gelembung yang gagal di layar punya tombol "Coba lagi" yang mengulang
         * muatan yang SAMA PERSIS, kuncinya ikut. Itu perlu karena kegagalan
         * yang paling sering justru bukan penolakan vendor, melainkan jawaban
         * yang hilang di jalan — pesannya sendiri sudah telanjur keluar. Tanpa
         * penjaga ini, satu klik "Coba lagi" berarti pelanggan menerima pesan
         * yang sama dua kali, dan itu tidak bisa ditarik kembali.
         *
         * Kuncinya dicatat SESUDAH berhasil, bukan sebelum: kalau dicatat di
         * depan, kegagalan yang sungguhan di sisi vendor ikut terkunci dan
         * "Coba lagi" tak akan pernah bisa berhasil.
         */
        $kunci = isset($data['kirim_key'])
            ? 'crm:kirim:' . $conversation->id . ':' . sha1($data['kirim_key'])
            : null;

        if ($kunci && Cache::has($kunci)) {
            $hasil = ['success' => true];
        } else {
            $hasil = $balasan->balas(
                $conversation,
                (string) ($data['teks'] ?? ''),
                $request->user()?->id,
                $data['reply_to'] ?? null,
                $request->file('gambar', [])
            );

            if ($kunci && $hasil['success']) {
                Cache::put($kunci, true, now()->addMinutes(10));
            }
        }

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
                ->with(self::MUATAN_GELEMBUNG)
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
     * Cari percakapan tujuan untuk "Teruskan".
     *
     * Jendela 24 jam tiap tujuan ikut dilaporkan supaya yang tertutup bisa
     * ditandai DI DAFTARNYA, bukan setelah admin memilih lalu ditolak. Yang
     * tertutup tetap ditampilkan (kadang justru itu yang dicari, untuk tahu
     * kenapa tidak bisa), tapi tombolnya mati.
     */
    public function cariPercakapan(Request $request)
    {
        $cari    = trim((string) $request->input('q', ''));
        $kecuali = (int) $request->input('kecuali', 0);

        $daftar = CrmConversation::query()
            ->with('customer:id,name')
            ->where('status', CrmConversation::STATUS_AKTIF)
            // Percakapan asalnya sendiri tidak boleh jadi tujuan — meneruskan
            // pesan ke chat yang sama cuma menggandakannya di tempat semula.
            ->when($kecuali, fn ($q) => $q->whereKeyNot($kecuali))
            ->when($cari !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('contact_key', 'like', "%{$cari}%")
                ->orWhere('display_name', 'like', "%{$cari}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$cari}%"))))
            ->orderByRaw('COALESCE(last_message_at, created_at) DESC')
            ->limit(20)
            ->get();

        return response()->json([
            'hasil' => $daftar->map(fn (CrmConversation $p) => [
                'id'      => $p->id,
                'nama'    => $p->namaTampil(),
                'nomor'   => $p->contact_key,
                'terbuka' => $p->windowIsOpen(),
            ])->all(),
        ]);
    }

    /**
     * Kontak untuk popup "Mulai Chat": percakapan yang sudah ada DAN pelanggan
     * ERP yang punya nomor.
     *
     * Dua sumber, bukan satu, karena keduanya memang dua keadaan berbeda:
     * pelanggan lama yang jendelanya sudah tutup hidup di crm_conversations,
     * sedangkan yang meninggalkan nomor di toko baru ada di master pelanggan.
     * Menyediakan yang pertama saja memaksa admin mengetik ulang nomor dari
     * layar lain — persis titik paling mudah salah kirim ke orang lain.
     *
     * Deduplikasi memakai nomor yang SUDAH dinormalkan: "0855…" di master dan
     * "62855…" di percakapan adalah orang yang sama, dan menampilkannya dua
     * kali membuat admin menebak mana yang benar.
     */
    public function cariKontak(Request $request)
    {
        $cari = trim((string) $request->input('q', ''));

        $percakapan = CrmConversation::query()
            ->with('customer:id,name')
            ->where('status', CrmConversation::STATUS_AKTIF)
            ->when($cari !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('contact_key', 'like', "%{$cari}%")
                ->orWhere('display_name', 'like', "%{$cari}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$cari}%"))))
            ->orderByRaw('COALESCE(last_message_at, created_at) DESC')
            ->limit(15)
            ->get();

        $hasil  = [];
        $sudah  = [];

        foreach ($percakapan as $p) {
            $sudah[$p->contact_key] = true;

            $hasil[] = [
                'nama'    => $p->namaTampil(),
                'nomor'   => $p->contact_key,
                'sumber'  => 'chat',
                'terbuka' => $p->windowIsOpen(),
            ];
        }

        $pelanggan = Customer::query()
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->when($cari !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$cari}%")
                ->orWhere('phone', 'like', "%{$cari}%")))
            ->orderBy('name')
            ->limit(15)
            ->get(['id', 'name', 'phone']);

        foreach ($pelanggan as $c) {
            $nomor = PhoneNumber::normalize($c->phone);

            if (! $nomor || isset($sudah[$nomor])) {
                continue;
            }

            $sudah[$nomor] = true;

            $hasil[] = [
                'nama'    => $c->name,
                'nomor'   => $nomor,
                'sumber'  => 'pelanggan',
                'terbuka' => false,
            ];
        }

        return response()->json(['hasil' => array_slice($hasil, 0, 20)]);
    }

    /**
     * Teruskan satu pesan ke percakapan lain.
     *
     * Tujuannya diterima sebagai id percakapan, BUKAN nomor telepon: nomor
     * bebas berarti layar ini diam-diam jadi alat kirim ke nomor mana pun,
     * lengkap dengan seluruh jebakan jendela 24 jam yang sudah dijaga di
     * tempat lain.
     */
    public function teruskan(Request $request, CrmMessage $message, CrmReplyService $balasan)
    {
        $data = $request->validate([
            'tujuan_id' => ['required', 'integer', Rule::exists('crm_conversations', 'id')],
        ], [
            'tujuan_id.required' => 'Pilih dulu percakapan tujuannya.',
        ]);

        $tujuan = CrmConversation::findOrFail($data['tujuan_id']);

        if ($tujuan->is($message->conversation)) {
            return $this->jawabTeruskan($request, false, 'Tidak bisa meneruskan pesan ke percakapan yang sama.');
        }

        $hasil = $balasan->teruskan($message, $tujuan, $request->user()?->id);

        return $this->jawabTeruskan(
            $request,
            (bool) $hasil['success'],
            $hasil['success']
                ? 'Pesan diteruskan ke ' . $tujuan->namaTampil() . '.'
                : (string) $hasil['error'],
            $tujuan
        );
    }

    private function jawabTeruskan(Request $request, bool $sukses, string $pesan, ?CrmConversation $tujuan = null)
    {
        if ($request->wantsJson()) {
            return response()->json(
                ['success' => $sukses, 'pesan' => $pesan, 'tujuan_url' => $tujuan && $sukses ? route('crm.inbox.show', $tujuan->id) : null],
                $sukses ? 200 : 422
            );
        }

        return back()->with($sukses ? 'success' : 'error', $pesan);
    }

    /**
     * Cari produk untuk panel Produk di rail kanan.
     *
     * Dasarnya SKU GUDANG, bukan produk etalase — pertanyaan yang datang di chat
     * hampir selalu "stoknya ada berapa" dan "harganya berapa", dan dua angka itu
     * hidup di SKU. Halaman etalase-nya ditemukan lewat varian yang menunjuk SKU
     * ini; kalau tidak ada, tautan webnya memang tidak ada dan lebih baik
     * dikatakan begitu daripada mengirim tautan ke halaman 404.
     *
     * `mode` memisahkan dua sub-tab yang memang punya pekerjaan berbeda:
     *  - `web`    : barang yang halaman etalasenya TERBIT — yang dikirim ke
     *               pembeli adalah stok, harga (setelah promo) dan tautannya.
     *  - `custom` : barang buatan (made_to_order). Tidak punya halaman etalase,
     *               harganya sering baru ditetapkan saat ditanya, dan yang
     *               dikirim adalah tautan lapaknya.
     * Tanpa pemisahan ini satu daftar panjang bercampur, dan sub-tab Custom
     * penuh barang ready yang tak akan pernah diedit harganya dari sini.
     */
    public function cariProduk(Request $request, PencarianProdukService $pencarian)
    {
        $cari   = trim((string) $request->input('q', ''));
        $mode   = $request->input('mode') === 'custom' ? 'custom' : 'web';
        $chatId = (int) $request->input('chat', 0);

        /*
         * Pencariannya sendiri pindah ke PencarianProdukService saat agen AI
         * lahir: alat `cari_produk` milik agen membaca sumber yang sama persis.
         * Kalau disalin, stok & diskon dihitung dua kali dengan aturan yang
         * cepat berbeda, dan bedanya baru ketahuan dari pembeli.
         */
        return response()->json($mode === 'custom'
            ? $pencarian->custom($cari, $chatId)
            : $pencarian->web($cari, $chatId));
    }

    /** Tambah satu tautan luar (Shopee dsb.) pada sebuah SKU. */
    public function tambahTautanProduk(Request $request, Product $product)
    {
        $data = $request->validate([
            'judul' => ['required', 'string', 'max:120'],
            'url'   => ['required', 'url', 'max:500'],
        ]);

        $tautan = $product->links()->create($data + [
            'urutan'     => (int) $product->links()->max('urutan') + 1,
            'created_by' => auth()->id(),
        ]);

        return response()->json([
            'tautan' => ['id' => $tautan->id, 'judul' => $tautan->judul, 'url' => $tautan->url],
        ]);
    }

    /** Ubah judul/alamat satu tautan luar — salah ketik baru ketahuan belakangan. */
    public function ubahTautanProduk(Request $request, ProductLink $link)
    {
        $data = $request->validate([
            'judul' => ['required', 'string', 'max:120'],
            'url'   => ['required', 'url', 'max:500'],
        ]);

        $link->update($data);

        return response()->json([
            'tautan' => ['id' => $link->id, 'judul' => $link->judul, 'url' => $link->url],
        ]);
    }

    /** Hapus satu tautan luar. */
    public function hapusTautanProduk(ProductLink $link)
    {
        $link->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Ubah harga & berat satu SKU custom dari layar chat, lalu (opsional)
     * dorong ke Jubelio.
     *
     * Kenapa boleh diedit dari sini: barang custom harganya baru lahir saat
     * ditanya. Memaksa admin membuka menu Produk di tab lain berarti ia
     * meninggalkan chat yang sedang berjalan, dan yang terjadi di lapangan
     * adalah harga itu tidak pernah dicatat sama sekali — cuma diketik di
     * WhatsApp lalu hilang.
     *
     * Beratnya ikut karena keduanya selalu berubah bersama: barang yang lebih
     * besar lebih mahal DAN lebih berat, dan berat yang tertinggal salah
     * membuat ongkirnya salah di setiap pesanan berikutnya.
     */
    public function simpanHargaProduk(Request $request, Product $product, JubelioProductSyncService $jubelio)
    {
        $data = $request->validate([
            'harga'         => ['required', 'numeric', 'min:0'],
            'berat'         => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'kirim_jubelio' => ['nullable', 'boolean'],
        ]);

        $harga = (float) $data['harga'];
        $berat = isset($data['berat']) ? (int) $data['berat'] : null;

        /*
         * Harga produk berstok hidup di baris product_prices, bukan di kolom
         * produk — dan observer harga yang menandai "perlu didorong ke Jubelio"
         * memantau baris itu. Menulis ke base_price untuk produk berstok berarti
         * angkanya tersimpan di tempat yang tidak pernah dibaca display_price.
         */
        if (in_array($product->type, ['service', 'non_stock'], true)) {
            $product->forceFill([
                ($product->type === 'service' ? 'base_price' : 'cost_price') => $harga,
            ])->save();
        } else {
            $baris = $product->prices()->orderBy('id')->first();

            $baris
                ? $baris->update(['price' => $harga])
                : ProductPrice::create([
                    'product_id' => $product->id,
                    'unit_name'  => $product->unit ?: 'pcs',
                    'price'      => $harga,
                ]);
        }

        if ($berat !== null) {
            $product->forceFill(['weight_gram' => $berat])->save();
        }

        $product->refresh();

        $catatan = ['Harga & berat tersimpan.'];

        if ($request->boolean('kirim_jubelio')) {
            $h = $jubelio->pushPrice($product);

            $catatan[] = match ($h) {
                'pushed'  => 'Harga terkirim ke Jubelio.',
                'skipped' => 'Harga TIDAK dikirim ke Jubelio — lihat Riwayat Sinkron.',
                default   => 'Harga GAGAL dikirim ke Jubelio — lihat Riwayat Sinkron.',
            };

            if ($h !== 'failed') {
                $product->forceFill(['jubelio_price_pending' => false])->save();
            }

            if ($berat !== null && $berat > 0) {
                $b = $jubelio->pushWeight($product, $berat);
                $catatan[] = $b['ok'] ? 'Berat terkirim ke Jubelio.' : ('Berat: ' . $b['message']);
            }
        }

        return response()->json([
            'ok'    => true,
            'pesan' => implode(' ', $catatan),
            'harga' => (float) $product->display_price,
            'berat' => (int) ($product->weight_gram ?? 0),
        ]);
    }

    /**
     * Setel stok sebuah produk DI JUBELIO dari layar chat.
     *
     * Stok ERP TIDAK disentuh, dan itu bukan kelalaian melainkan intinya.
     * Barang custom belum ada wujudnya sampai dipesan — SKU-nya cuma wadah —
     * jadi angka yang diketik di sini adalah "berapa yang sanggup dikerjakan",
     * bukan "berapa yang ada di rak". Menuliskannya ke stok ERP berarti
     * mengarang persediaan, dan HPP serta laporan ikut karangan.
     */
    public function simpanStokJubelio(Request $request, Product $product, JubelioStockSyncService $stok)
    {
        $data = $request->validate([
            'stok' => ['required', 'numeric', 'min:0', 'max:1000000'],
        ]);

        $hasil = $stok->setStokManual($product, (float) $data['stok']);

        return response()->json(
            ['ok' => $hasil['ok'], 'pesan' => $hasil['message']],
            $hasil['ok'] ? 200 : 422
        );
    }

    /**
     * Kirim PANCINGAN: template bertombol yang membuka kembali jendela 24 jam.
     *
     * Satu-satunya jalan keluar dari kebuntuan yang paling sering terjadi di
     * layar ini — pembahasan yang belum kelar bertemu hari libur, jendelanya
     * habis, dan Senin pagi tak satu kalimat pun bisa dikirim sampai pelanggan
     * kebetulan menyapa duluan. Berbayar, jadi tombolnya sengaja hanya muncul
     * di keadaan yang memang membutuhkannya (lihat kirimPancingan()).
     */
    public function pancingan(Request $request, CrmConversation $conversation, CrmReplyService $balasan)
    {
        $hasil = $balasan->kirimPancingan($conversation, $request->user()?->id);

        return back()->with(
            $hasil['success'] ? 'success' : 'error',
            $hasil['success']
                ? 'Pancingan terkirim. Begitu ' . $conversation->namaTampil()
                  . ' menekan tombolnya, jendela 24 jam terbuka lagi dan chat bisa dilanjutkan seperti biasa.'
                : $hasil['error']
        );
    }

    /**
     * Kirim FOTO produk berikut captionnya — bukan sekadar tautannya.
     *
     * WhatsApp tidak selalu memunculkan pratinjau untuk tautan yang dikirim;
     * kalau pengambil pratinjaunya gagal membuka halaman etalase, yang sampai
     * ke pembeli hanya sebaris URL telanjang. Mengirim fotonya sendiri membuat
     * pratinjau itu jadi urusan kita, bukan urusan pengambil halaman milik
     * orang lain.
     */
    public function kirimFotoProduk(Request $request, CrmConversation $conversation, CrmReplyService $balasan)
    {
        $data = $request->validate([
            'foto'    => ['required', 'url', 'max:1000'],
            'caption' => ['nullable', 'string', 'max:1024'],
        ]);

        $hasil = $balasan->kirimFoto(
            $conversation,
            $data['foto'],
            (string) ($data['caption'] ?? ''),
            $request->user()?->id
        );

        if (! $hasil['success']) {
            return response()->json(['success' => false, 'error' => $hasil['error']], 422);
        }

        $baru = $conversation->messages()
            ->with(self::MUATAN_GELEMBUNG)
            ->where('id', '>', (int) $request->input('after', 0))
            ->orderBy('sent_at')->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'html'    => view('erp.crm.inbox._bubbles', ['pesan' => $baru])->render(),
            'last_id' => (int) ($baru->max('id') ?: $request->input('after', 0)),
        ]);
    }

    /* ------------------------------------------------------ titipan stok */

    /**
     * Tandai sebuah SKU: "kabari pelanggan ini kalau stoknya sudah ada".
     *
     * Ditempel ke PERCAKAPAN, bukan ke pelanggan master: yang menitipkan
     * sering belum pernah jadi pelanggan — ia baru bertanya. Memaksa ada
     * record pelanggan lebih dulu berarti janjinya tidak bisa dicatat justru
     * pada saat paling sering diucapkan.
     */
    public function tandaiTitipanStok(Request $request, CrmConversation $conversation, StockWatchService $titipan)
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'qty'        => ['nullable', 'numeric', 'min:1', 'max:100000'],
        ]);

        $product = Product::findOrFail($data['product_id']);

        $watch = $titipan->tandai(
            $conversation,
            $product,
            (float) ($data['qty'] ?? 1),
            $request->user()?->id
        );

        /*
         * Diperiksa SEKARANG JUGA. Stoknya bisa saja sudah ada — barang masuk
         * kemarin, pelanggannya belum sempat dikabari. Menunggu perubahan stok
         * berikutnya berarti titipan itu menggantung sampai ada pergerakan yang
         * mungkin baru datang minggu depan.
         */
        $hasil = $titipan->periksa([$product->id]);

        return response()->json([
            'ok'       => true,
            'ditandai' => $hasil['dikabari'] === 0,
            'pesan'    => $hasil['dikabari'] > 0
                ? 'Stoknya ternyata sudah ada — kabar langsung diantrekan untuk pelanggan ini.'
                : 'Ditandai. Pelanggan akan dikabari begitu stoknya masuk.',
            'watch_id' => $watch->id,
        ]);
    }

    /** Batalkan titipan tanpa mengabari (salah tandai, atau pelanggan berubah pikiran). */
    public function hapusTitipanStok(CrmStockWatch $watch, StockWatchService $titipan)
    {
        $titipan->batalkan($watch);

        return response()->json(['ok' => true]);
    }

    /** Titipan yang masih aktif untuk satu percakapan — dipakai panel Produk. */
    public function daftarTitipanStok(CrmConversation $conversation, StockWatchService $titipan)
    {
        return response()->json([
            'titipan' => $titipan->untukPercakapan($conversation)->map(fn ($w) => [
                'id'         => $w->id,
                'product_id' => $w->product_id,
                'nama'       => $w->product?->name,
                'sku'        => $w->product?->sku,
                'qty'        => (float) $w->qty,
            ])->values(),
        ]);
    }

    /* --------------------------------------------------- tautan marketplace */

    /**
     * Daftar tautan marketplace yang berdiri sendiri (nama diketik manual).
     *
     * Sengaja TIDAK menempel ke SKU: yang dijual di lapak sering bukan satu SKU
     * gudang — paket bundling, listing lama yang namanya sudah dikenal pembeli,
     * barang titipan. Memaksanya punya SKU berarti mengarang produk ERP hanya
     * demi tempat menyimpan sebuah alamat, dan produk karangan itu ikut bocor ke
     * laporan stok.
     */
    public function daftarTautanPasar(Request $request)
    {
        $cari = trim((string) $request->input('q', ''));

        $daftar = CrmMarketplaceLink::query()
            ->when($cari !== '', fn ($q) => $q->where('nama', 'like', "%{$cari}%"))
            ->orderBy('urutan')->orderBy('id')
            ->limit(200)
            ->get(['id', 'nama', 'url']);

        return response()->json(['tautan' => $daftar]);
    }

    public function simpanTautanPasar(Request $request)
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:160'],
            'url'  => ['required', 'url', 'max:500'],
        ]);

        $tautan = CrmMarketplaceLink::create($data + [
            'urutan'     => (int) CrmMarketplaceLink::max('urutan') + 1,
            'created_by' => auth()->id(),
        ]);

        return response()->json(['tautan' => $tautan->only(['id', 'nama', 'url'])]);
    }

    public function ubahTautanPasar(Request $request, CrmMarketplaceLink $tautan)
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:160'],
            'url'  => ['required', 'url', 'max:500'],
        ]);

        $tautan->update($data);

        return response()->json(['tautan' => $tautan->only(['id', 'nama', 'url'])]);
    }

    public function hapusTautanPasar(CrmMarketplaceLink $tautan)
    {
        $tautan->delete();

        return response()->json(['ok' => true]);
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

    /** Simpan template baru lewat jalur pintas (CRUD penuhnya di layar Template Pesan). */
    public function simpanTemplateCepat(Request $request)
    {
        $data = $request->validate([
            'title'    => 'required|string|max:120',
            'body'     => 'required|string|max:2000',
            'category' => 'nullable|string|max:60',
        ]);

        CrmTemplate::create($data + ['created_by' => $request->user()?->id]);

        return back()->with('success', 'Template disimpan.');
    }

    /**
     * Hapus template lewat jalur pintas.
     *
     * Yang sudah didaftarkan ke Meta DITOLAK di sini: menghapus barisnya tidak
     * menghapus templatenya di Meta, dan namanya tetap terpakai di sana — yang
     * tersisa cuma template yang hidup tapi tak bisa lagi dipilih dari mana pun.
     */
    public function hapusTemplateCepat(CrmTemplate $template)
    {
        if ($template->keMeta()) {
            return back()->with('error',
                'Template "' . $template->title . '" sudah didaftarkan ke Meta — hapus lewat layar Template Pesan.');
        }

        $template->delete();

        return back()->with('success', 'Template dihapus.');
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
            ->with(self::MUATAN_GELEMBUNG)
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
            'centang' => $this->centangTerakhir($conversation),
        ]);
    }

    /**
     * Status centang pesan keluar terbaru, buat dinaikkan di layar tanpa
     * menggambar ulang gelembungnya.
     *
     * Ikut menumpang polling yang sudah ada, bukan permintaan sendiri: status
     * 'delivered'/'read' datang BERMENIT setelah pesannya dikirim, jadi tak
     * mungkin ikut jawaban kirim, dan menambah satu polling lagi berarti
     * menggandakan lalu lintas yang jalan terus-menerus.
     *
     * Dibatasi 30 terakhir — pesan lama statusnya sudah lama diam, dan yang
     * terlihat di layar toh cuma bagian bawah thread.
     */
    private function centangTerakhir(CrmConversation $conversation): array
    {
        return $conversation->messages()
            ->where('direction', CrmMessage::KELUAR)
            ->orderByDesc('id')
            ->limit(30)
            ->get(['id', 'direction', 'status'])
            ->mapWithKeys(fn (CrmMessage $m) => [$m->id => $m->centang()])
            ->reject(fn (string $c) => $c === 'tidak')
            ->all();
    }

    /**
     * Rincian pesanan siap kirim: daftar barang, ongkir, total, plus tautan bayar.
     *
     * Kalimat pembayarannya diambil dari PaymentLinkService — sumber yang sama
     * dengan modal "Link Bayar" di halaman SO. Menulis versi sendiri di sini
     * berarti ada dua tempat yang masing-masing berjanji kepada pelanggan soal
     * masa berlaku tautan, dan keduanya akan cepat berbeda isi.
     */
    public function rincianPesanan(
        CrmConversation $conversation,
        SalesOrder $order,
        \App\Modules\Payment\Services\PaymentLinkService $links
    ) {
        // Pesanan milik pelanggan LAIN tidak boleh bocor lewat chat ini.
        if (! $conversation->customer_id || $order->customer_id !== $conversation->customer_id) {
            abort(404);
        }

        $order->load('items.product');

        $baris = ['Berikut rincian pesanan ' . $order->order_number . ':', ''];

        foreach ($order->items as $item) {
            // description didahulukan: barang custom disepakati lewat kalimat di
            // chat, dan nama master produknya sering terlalu umum untuk dikenali
            // pembeli ("Akrilik 3mm" untuk sesuatu yang ia sebut "box mahar").
            $nama = $item->description ?: ($item->product?->name ?? 'Produk');

            $baris[] = '• ' . $nama . ' — ' . rtrim(rtrim(number_format((float) $item->qty, 2, ',', '.'), '0'), ',')
                . ' × ' . $this->rupiah($item->unit_price)
                . ' = ' . $this->rupiah($item->line_total);
        }

        $baris[] = '';

        if ((float) $order->discount_total > 0 || (float) $order->global_discount_amount > 0) {
            $baris[] = 'Diskon: −' . $this->rupiah((float) $order->discount_total + (float) $order->global_discount_amount);
        }

        if ((float) $order->shipping_cost > 0) {
            $baris[] = 'Pengiriman' . ($order->shipping_service_name ? ' (' . $order->shipping_service_name . ')' : '')
                . ': ' . $this->rupiah($order->shipping_cost);
        } elseif ($order->delivery_method === 'ambil_toko') {
            $baris[] = 'Pengiriman: diambil di toko';
        }

        $baris[] = 'Total: ' . $this->rupiah($order->grand_total);

        $sisa = (int) round((float) $order->grand_total - (float) $order->paid_amount);

        /*
         * Tautan bayar hanya dibuat kalau memang masih ada yang harus dibayar.
         * Mengirim tautan untuk pesanan lunas membuat pelanggan mengira ada
         * tagihan kedua — dan itu jenis kebingungan yang berujung telepon.
         */
        if ($sisa <= 0) {
            $baris[] = '';
            $baris[] = 'Pesanan ini sudah lunas. Terima kasih!';

            return response()->json(['teks' => implode("\n", $baris), 'url' => null]);
        }

        $trx = $links->getOrCreateForSalesOrder($order, auth()->id(), $order->minDpAmount());

        return response()->json([
            'teks' => $links->waTextSo(
                $trx,
                $order->customer?->name ?? 'Kak',
                $order->order_number,
                $sisa,
                $baris,
            ),
            'url' => $links->publicUrl($trx),
        ]);
    }

    private function rupiah(float|int|string $n): string
    {
        return 'Rp ' . number_format((float) $n, 0, ',', '.');
    }

    /**
     * Promo yang berlaku untuk keranjang yang sedang disusun.
     *
     * Menumpang PromotionService — mesin yang sama dengan Kasir, form SO, dan
     * etalase web. Promo yang dihitung ulang di sini akan jadi versi kedua yang
     * cepat berbeda aturannya, dan bedanya baru ketahuan dari pelanggan yang
     * membandingkan harga di web dengan yang ditawarkan lewat chat.
     *
     * Punya pintu sendiri (bukan memanggil sales.promosi.resolve) karena
     * EnsureMenuAccess mengikat izin ke menu pemilik route-nya: operator CRM
     * yang tidak punya menu Promosi akan kena 403 di tengah menyusun pesanan.
     */
    public function promoKeranjang(Request $request, CrmConversation $conversation, PromotionService $promosi)
    {
        $items = array_map(fn ($it) => [
            'product_id' => (int) ($it['product_id'] ?? 0),
            'qty'        => (float) ($it['qty'] ?? 1),
            'unit_price' => (float) ($it['unit_price'] ?? 0),
        ], (array) $request->input('items', []));

        return response()->json($promosi->resolve([
            'items'          => $items,
            'subtotal'       => (float) $request->input('subtotal', 0),
            'shipping_gross' => (float) $request->input('shipping_gross', 0),
        ]));
    }

    /** Daftar pesanan pelanggan ini — dipanggil ulang setelah SO baru dibuat. */
    public function daftarPesanan(CrmConversation $conversation)
    {
        return response()->json(['pesanan' => $this->ringkasPesanan($conversation)]);
    }

    /**
     * Pesanan pelanggan ini beserta tahap yang sedang berjalan.
     *
     * Statusnya diambil dari OrderProgressService — sumber yang sama dengan
     * halaman lacak pesanan yang dilihat pembeli. Menurunkan status sendiri di
     * sini berarti admin dan pembeli bisa membaca dua cerita berbeda tentang
     * pesanan yang sama, dan yang salah selalu ketahuan belakangan lewat
     * pertanyaan "katanya sudah dikirim?".
     *
     * Dibatasi 10 terbaru: tiap pesanan butuh beberapa kueri untuk diketahui
     * tahapnya, dan rail ini ikut dirender setiap kali chat dibuka.
     */
    private function ringkasPesanan(CrmConversation $conversation): array
    {
        if (! $conversation->customer_id) {
            return [];
        }

        $progress = app(\App\Modules\Sales\Services\OrderProgressService::class);

        return SalesOrder::query()
            ->where('customer_id', $conversation->customer_id)
            ->whereNotIn('status', ['void', 'cancelled'])
            ->latest('id')
            ->limit(10)
            ->with('items.product:id,name,sku')
            ->get()
            ->map(function (SalesOrder $so) use ($progress) {
                $p = $progress->for($so);

                $tahap = collect($p['steps'])->firstWhere('key', $p['current']);

                return [
                    /*
                     * Barangnya ikut ditampilkan, bukan cuma nomor & total —
                     * gaya kartu pesanan marketplace. Pertanyaan lewat chat
                     * hampir selalu menyebut BARANGNYA ("rak bolpoin saya
                     * gimana"), bukan nomor SO, jadi kartu tanpa nama barang
                     * memaksa operator membuka halaman SO untuk mencocokkan.
                     */
                    'items' => $so->items->take(3)->map(fn ($i) => [
                        'nama' => $i->description ?: ($i->product?->name ?? 'Produk'),
                        // SKU ikut karena nama produk sering mirip satu sama lain
                        // ("Kotak Saran 1 Kotak" vs "2 Kotak"); SKU-lah yang
                        // dipakai gudang & produksi untuk memastikan barangnya.
                        'sku'  => $i->product?->sku,
                        'qty'  => rtrim(rtrim(number_format((float) $i->qty, 2, ',', '.'), '0'), ','),
                        'total' => (float) $i->line_total,
                    ])->values()->all(),
                    'sisa_item' => max(0, $so->items->count() - 3),
                    'ongkir'    => (float) $so->shipping_cost,
                    'kurir'     => $so->shipping_service_name,
                    'ambil'     => $so->delivery_method === 'ambil_toko',
                    'id'      => $so->id,
                    'nomor'   => $so->order_number,
                    'tanggal' => optional($so->order_date)->format('d M Y'),
                    'total'   => (float) $so->grand_total,
                    'draft'   => $so->status === 'draft',
                    // Draft belum jadi pesanan bagi pelanggan; menandainya
                    // "menunggu pembayaran" membuat operator menagih sesuatu
                    // yang belum pernah dikirimkan.
                    'status'  => $so->status === 'draft' ? 'Draft' : ($tahap['label'] ?? '—'),
                    'catatan' => $so->status === 'draft' ? 'Belum dikonfirmasi' : ($tahap['note'] ?? ''),
                    'selesai' => $p['current'] === 'selesai',
                    // Halaman SO = tempat SEMUA perubahan dikerjakan: ubah item,
                    // ongkir, kesepakatan, sampai posting. Panel chat sengaja
                    // tidak menduplikasi satu pun dari itu — dua tempat yang
                    // sama-sama bisa mengubah pesanan akan berbeda aturannya.
                    'url'     => route('sales.orders.show', $so->id),
                ];
            })
            ->all();
    }

    /**
     * Simpan keranjang yang sedang disusun (alamat, ongkir, produk) ke percakapan.
     *
     * Isinya sengaja TIDAK divalidasi per medan: ini potret setengah jadi dari
     * layar, bukan pesanan. Memaksanya lolos aturan SO berarti draft yang baru
     * terisi separuh ditolak — padahal justru keadaan setengah jadi itulah yang
     * paling perlu diselamatkan dari muat ulang. Yang dijaga cuma ukurannya,
     * supaya kolomnya tidak bisa dipakai menitipkan data sembarangan.
     *
     * Pemeriksaan sesungguhnya tetap terjadi di buatSo(): apa pun isi draftnya,
     * SO baru lahir setelah lolos validasi di sana.
     */
    public function simpanDraftPesanan(Request $request, CrmConversation $conversation)
    {
        $bagian = (string) $request->input('bagian');

        if (! in_array($bagian, ['ongkir', 'pesanan'], true)) {
            return response()->json(['success' => false, 'error' => 'Bagian draft tidak dikenal.'], 422);
        }

        $data = $request->input('data');

        if ($data !== null && strlen((string) json_encode($data)) > 60000) {
            return response()->json(['success' => false, 'error' => 'Draft terlalu besar.'], 422);
        }

        /*
         * DIGABUNG per bagian, bukan ditimpa utuh: tab Ongkir dan tab Pesanan
         * menyimpan sendiri-sendiri, dan simpanan yang datang belakangan akan
         * menghapus pekerjaan tab sebelahnya kalau seluruh kolom ditulis ulang.
         */
        $draft = (array) ($conversation->order_draft ?? []);
        $draft[$bagian] = $data;

        $conversation->forceFill([
            'order_draft' => array_filter($draft, fn ($v) => ! empty($v)) ?: null,
        ])->save();

        return response()->json(['success' => true]);
    }

    /**
     * Buat Sales Order DRAFT dari layar chat.
     *
     * Draft, bukan langsung terkonfirmasi: pesanan yang lahir dari percakapan
     * hampir selalu masih berubah satu-dua kali sebelum disepakati, dan SO yang
     * terlanjur di-post harus dibatalkan lewat void — jejaknya menempel di buku
     * selamanya hanya karena pelanggan berubah pikiran soal warna.
     *
     * Perhitungannya dititipkan ke SalesOrderService, bukan dihitung ulang di
     * sini: aturan diskon nominal-per-unit, pembulatan rupiah, dan ongkir
     * net-vs-gross harus sama persis dengan SO yang dibuat lewat form biasa.
     */
    public function buatSo(Request $request, CrmConversation $conversation, SalesOrderService $salesOrder)
    {
        $data = $request->validate([
            // Salah satu wajib: pelanggan lama, atau nama untuk pelanggan baru.
            'customer_id'   => 'nullable|integer|exists:customers,id',
            'customer_name' => 'nullable|string|max:255',
            'warehouse_id'  => 'required|integer|exists:warehouses,id',

            'items'                   => 'required|array|min:1',
            'items.*.product_id'      => 'required|integer|exists:products,id',
            // Nama boleh ditimpa: pesanan custom disepakati lewat kalimat di
            // chat ("box mahar 30×30 tutup emas"), dan nama master produknya
            // terlalu umum untuk dikenali pembeli di nota.
            'items.*.description'     => 'nullable|string|max:255',
            'items.*.qty'             => 'required|numeric|min:0.0001',
            'items.*.unit_price'      => 'required|numeric|min:0',
            'items.*.discount_type'   => 'nullable|in:percent,nominal',
            'items.*.discount_value'  => 'nullable|numeric|min:0',

            'global_discount_type'  => 'nullable|in:percent,nominal',
            'global_discount_value' => 'nullable|numeric|min:0',

            'min_dp_percent'  => 'nullable|numeric|min:0|max:100',
            'allow_backorder' => 'nullable|boolean',
            'is_tempo'        => 'nullable|boolean',
            'tempo_days'      => 'nullable|integer|min:0|max:365',
            'delivery_method' => 'nullable|string|max:30',
            'notes'           => 'nullable|string|max:2000',

            // Ongkir dititipkan dari tab Ongkir — kode kurir & layanan ikut supaya
            // resinya masih bisa dipesan ke provider yang benar nanti.
            'shipping_gross'          => 'nullable|numeric|min:0',
            'shipping_discount_type'  => 'nullable|in:percent,nominal',
            'shipping_discount_value' => 'nullable|numeric|min:0',
            'courier_name'            => 'nullable|string|max:150',
            'shipping_provider'       => 'nullable|string|max:30',
            'shipping_courier_code'   => 'nullable|string|max:50',
            'shipping_service_code'   => 'nullable|string|max:80',
        ]);

        try {
            $customerId = $this->pelangganUntukSo($conversation, $data);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        try {
            $so = $salesOrder->createDraftFromData($data + [
                'customer_id' => $customerId,
                'order_date'  => now()->toDateString(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[CRM] gagal membuat SO dari chat', [
                'conversation' => $conversation->id,
                'error'        => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        /*
         * Kesepakatan dagang (batas DP, keep stock, tempo) disetel setelahnya:
         * createDraftFromData dipakai juga oleh jalur AI & marketplace yang tak
         * punya konsep ini, dan menyelipkannya ke sana akan memaksa mereka ikut
         * memikirkan medan yang tidak pernah mereka isi.
         */
        $kesepakatan = array_filter([
            'min_dp_percent'  => $data['min_dp_percent'] ?? null,
            'allow_backorder' => isset($data['allow_backorder']) ? (bool) $data['allow_backorder'] : null,
            'is_tempo'        => isset($data['is_tempo']) ? (bool) $data['is_tempo'] : null,
            // Termin boleh kosong: tempo tanpa batas waktu tetap sah, yang hilang
            // cuma peringatan jatuh temponya.
            'tempo_days'      => $data['tempo_days'] ?? null,
        ], fn ($v) => $v !== null);

        if ($kesepakatan) {
            $so->forceFill($kesepakatan)->save();
        }

        return response()->json([
            'success' => true,
            'nomor'   => $so->order_number,
            'total'   => (float) $so->grand_total,
            'url'     => url('/erp/sales/orders/' . $so->id),
        ]);
    }

    /**
     * Pelanggan untuk SO dari chat.
     *
     * Percakapan yang belum tertaut master (Lead) adalah keadaan NORMAL, bukan
     * kesalahan — kebanyakan pesanan lahir dari nomor asing. Jadi di sini
     * pelanggan baru boleh dibuat, TAPI hanya dari nama yang diketik operator:
     * membuatnya diam-diam dari tiap chat akan menyampahi master pelanggan
     * dengan nomor yang tidak pernah jadi pesanan.
     */
    private function pelangganUntukSo(CrmConversation $conversation, array $data): int
    {
        if (! empty($data['customer_id'])) {
            $id = (int) $data['customer_id'];
        } else {
            $nama = trim((string) ($data['customer_name'] ?? ''));

            if ($nama === '') {
                throw new \RuntimeException('Pilih pelanggan, atau isi nama untuk membuat pelanggan baru.');
            }

            $id = (int) \App\Models\Customer::create([
                'code'        => 'CUST-' . strtoupper(\Illuminate\Support\Str::random(6)),
                'name'        => $nama,
                'phone'       => $conversation->contact_key,
                'wa_opt_in'   => true,
                'is_active'   => true,
            ])->id;
        }

        // Percakapan ikut ditautkan: sekali pesan, chat berikutnya dari nomor itu
        // langsung mendarat pada pelanggan yang sama beserta riwayat pesanannya.
        if (! $conversation->customer_id) {
            $conversation->forceFill(['customer_id' => $id])->save();
        }

        return $id;
    }

    /** Oper percakapan ke admin lain — pengganti rotator otomatis yang ditunda. */
    /**
     * Oper percakapan ke agen lain — dan tandai BELUM DIBACA untuk yang menerima.
     *
     * Tanpa tanda itu, chat yang dioper mendarat di daftar orang lain tanpa
     * gejala apa pun: ia sudah "terbaca" karena yang mengoper baru saja
     * membukanya, jadi ia tidak muncul di penyaring "Belum dibaca" dan
     * tenggelam di antara chat lama. Penerimanya baru tahu ada yang dioper
     * kepadanya kalau kebetulan menggulir sampai bawah — atau saat pelanggan
     * menagih.
     *
     * Dua pengecualian, keduanya karena tandanya akan berbohong:
     *  - mengoper ke DIRI SENDIRI (mengambil alih) — orangnya sedang membaca
     *    chat itu sekarang juga;
     *  - pemiliknya tidak berubah — tak ada siapa pun yang perlu disadarkan.
     */
    public function oper(Request $request, CrmConversation $conversation)
    {
        $data = $request->validate([
            'owner_user_id' => ['nullable', User::assignableExistsRule()],
        ]);

        $pemilikBaru = $data['owner_user_id'] ? (int) $data['owner_user_id'] : null;
        $pemilikLama = $conversation->owner_user_id ? (int) $conversation->owner_user_id : null;

        $berpindah = $pemilikBaru !== $pemilikLama;
        $keOrangLain = $berpindah && $pemilikBaru !== (int) $request->user()?->id;

        $ubah = ['owner_user_id' => $pemilikBaru];

        if ($keOrangLain) {
            $ubah['unread_count'] = max(1, (int) $conversation->unread_count);
        }

        $conversation->forceFill($ubah)->save();

        $pesan = $pemilikBaru ? 'Percakapan dioper.' : 'Kepemilikan dilepas.';

        if ($keOrangLain) {
            $pesan .= ' Ditandai belum dibaca supaya terlihat sebagai pekerjaan baru.';

            /*
             * Dan penerimanya dikabari sungguhan. Tanda belum-dibaca saja hanya
             * terlihat kalau ia kebetulan membuka Inbox — sedangkan operan itu
             * pekerjaan yang baru saja berpindah ke tangannya, sering justru
             * karena yang mengoper tidak bisa melanjutkan.
             */
            app(NotifikasiChatService::class)->dioper(
                $conversation,
                User::find($pemilikBaru),
                $request->user()
            );

            /*
             * Kalau chat yang dioper adalah yang SEDANG terbuka — dan itu
             * hampir selalu — back() memuat ulang layarnya dan show() langsung
             * menghapus tanda yang baru saja dipasang. Jadi kita lempar ke
             * daftar, dengan penyaring yang sedang dipakai tetap utuh. Lagi
             * pula pekerjaannya memang sudah pindah tangan.
             */
            $kueri = (string) $request->getQueryString();

            return redirect()->to(route('crm.inbox.index') . ($kueri ? '?' . $kueri : ''))
                ->with('success', $pesan);
        }

        return back()->with('success', $pesan);
    }

    public function antrean(Request $request, CrmConversation $conversation)
    {
        $data = $request->validate([
            'queue_state' => ['required', Rule::in(array_keys(CrmLabel::peta()))],
        ]);

        $conversation->forceFill(['queue_state' => $data['queue_state']])->save();

        return back()->with('success', 'Label diperbarui: ' . CrmLabel::nama($data['queue_state']) . '.');
    }

    public function arsip(CrmConversation $conversation)
    {
        $keArsip = $conversation->status === CrmConversation::STATUS_AKTIF;

        $conversation->forceFill([
            'status' => $keArsip ? CrmConversation::STATUS_ARSIP : CrmConversation::STATUS_AKTIF,
        ])->save();

        return back()->with('success', $keArsip ? 'Percakapan diarsipkan.' : 'Percakapan diaktifkan lagi.');
    }

    /**
     * Kembalikan tanda "belum dibaca".
     *
     * Membuka chat menandainya terbaca seketika, padahal admin sering cuma
     * mengintip lalu menundanya. Tanpa jalan pulang, pekerjaan itu lenyap dari
     * penyaring "Belum dibaca" dan baru teringat saat pelanggan menagih.
     */
    public function belumDibaca(Request $request, CrmConversation $conversation)
    {
        $conversation->forceFill([
            'unread_count' => max(1, (int) $conversation->unread_count),
        ])->save();

        /*
         * Kalau yang ditandai justru chat yang SEDANG terbuka, back() akan
         * memuat ulang layarnya dan show() langsung menghapus tandanya lagi.
         * Jadi khusus kasus itu kita lempar ke daftar — dengan penyaring yang
         * sedang dipakai tetap utuh.
         */
        if ((int) $request->input('terbuka') === $conversation->id) {
            $kueri = (string) $request->getQueryString();

            return redirect()->to(route('crm.inbox.index') . ($kueri ? '?' . $kueri : ''))
                ->with('success', 'Ditandai belum dibaca.');
        }

        return back()->with('success', 'Ditandai belum dibaca.');
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

    /**
     * Jumlah percakapan per label, MENGIKUTI saringan pemilik & status yang
     * sedang aktif — angka di tombol harus sama dengan isi yang dibukanya.
     */
    private function jumlahPerLabel(\Closure $dasar): array
    {
        $jumlah = $dasar()
            ->selectRaw('queue_state, COUNT(*) as total')
            ->groupBy('queue_state')
            ->pluck('total', 'queue_state')
            ->all();

        return CrmLabel::terpakai()
            ->mapWithKeys(fn ($l) => [$l->kode => (int) ($jumlah[$l->kode] ?? 0)])
            ->all();
    }
}
