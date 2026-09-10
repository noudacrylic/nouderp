<?php

namespace App\Modules\CRM\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmTemplate;
use App\Modules\CRM\Services\CrmReplyService;
use Illuminate\Http\Request;

/**
 * Template pesan yang DIPILIH MANUSIA, dan layar memulai percakapan baru.
 *
 * Yang dikirim SISTEM sendiri tidak ada di sini — tempatnya layar Notifikasi
 * Pesanan, berikut tombol pengajuannya ke Meta. Pemisahannya bukan soal harga
 * melainkan soal siapa yang mengirim, dan dari situ mengalir segalanya:
 * bunyi notifikasi terkunci di kode (satu bunyi, dua jalur, wajib sama persis),
 * sedangkan yang di sini bebas dibuat & diubah dari layar.
 *
 * Satu daftar, dua macam, dibedakan `meta_name`:
 *
 *  - KOSONG: milik kita. Nomor rekening, alamat, jam buka. Gratis, bisa diubah
 *    kapan saja, TAPI hanya sah dikirim di dalam jendela 24 jam.
 *  - TERISI: diajukan ke Meta. Satu-satunya yang boleh MEMBUKA chat ke nomor
 *    yang belum menghubungi kita dalam 24 jam terakhir — dan bunyinya terkunci
 *    begitu diajukan, karena yang beredar sejak itu adalah salinan milik Meta.
 */
class CrmTemplateController extends Controller
{
    public function index(Request $request, ChatManager $chat)
    {
        $daftar = CrmTemplate::orderBy('sort_order')->orderBy('title')->get();

        $galat = $this->selaraskanStatusMeta($daftar, $chat);

        return view('erp.crm.template.index', [
            'daftar'    => $daftar,
            'kelompok'  => $daftar->groupBy(fn (CrmTemplate $t) => $t->category ?: 'Tanpa kelompok'),
            'isian'     => CrmTemplate::ISIAN,
            'galatMeta' => $galat,
            'dryRun'    => $chat->isDryRun(),
        ]);
    }

    /**
     * Samakan status Meta di tabel kita dengan daftar milik vendor.
     *
     * Statusnya berubah TANPA memberi tahu kita — peninjauan Meta bisa memakan
     * waktu sampai 24 jam, dan hasilnya tidak dikirim ke mana-mana. Kalau tidak
     * diselaraskan di sini, sebuah template bisa berstatus PENDING selamanya di
     * layar padahal sudah lama disetujui, dan tombol "Chat Baru" menolak
     * memilihnya tanpa alasan yang bisa dimengerti siapa pun.
     *
     * Dilakukan di index() dan hanya di sini: satu panggilan untuk seluruh
     * daftar, bukan satu per baris.
     *
     * @return ?string keterangan bila vendor tak bisa dihubungi
     */
    private function selaraskanStatusMeta($daftar, ChatManager $chat): ?string
    {
        $bermeta = $daftar->filter(fn (CrmTemplate $t) => $t->keMeta());

        if ($bermeta->isEmpty()) {
            return null;
        }

        $hasil = $chat->provider()->templates();

        if (! ($hasil['success'] ?? false)) {
            return (string) ($hasil['error'] ?? 'Daftar template vendor tidak bisa dibaca.');
        }

        $vendor = collect($hasil['templates'] ?? [])->keyBy('name');

        foreach ($bermeta as $template) {
            $baris = $vendor->get($template->meta_name);

            /*
             * Yang TIDAK ADA di vendor dibiarkan apa adanya, tidak dinolkan.
             * Daftar vendor bisa saja terpotong (limit, filter, nomor WABA
             * lain), dan menghapus status hanya karena sebuah baris tak
             * kebetulan muncul berarti tombol "Ajukan" hidup lagi untuk
             * template yang namanya sudah terpakai — percobaan kedua pasti
             * ditolak, dengan alasan yang membingungkan.
             */
            if (! $baris) {
                continue;
            }

            if ($template->meta_status !== ($baris['status'] ?? null)) {
                $template->forceFill(['meta_status' => $baris['status'] ?? null])->save();
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------ CRUD */

    public function store(Request $request)
    {
        $data = $this->validasi($request);

        $template = new CrmTemplate();
        $template->fill($this->isiDasar($data));
        $template->created_by = $request->user()?->id;

        if ($galat = $this->siapkanMeta($template, $data)) {
            return back()->withInput()->with('error', $galat);
        }

        $template->save();

        return redirect()->route('crm.template.index')->with(
            'success',
            $template->keMeta()
                ? 'Template "' . $template->title . '" tersimpan. Belum diajukan ke Meta — tekan "Ajukan ke Meta" bila bunyinya sudah final.'
                : 'Template "' . $template->title . '" tersimpan dan langsung bisa dipakai dari rail chat.'
        );
    }

    /**
     * Ubah satu template.
     *
     * Bunyinya TERKUNCI begitu template diajukan ke Meta, dan penguncian itu
     * bukan pilihan kita: sejak diajukan, yang benar-benar dikirim ke pelanggan
     * adalah salinan milik Meta. Membiarkan bodynya disunting di sini
     * menghasilkan layar yang menampilkan satu kalimat sementara pelanggan
     * menerima kalimat lain — jenis kekeliruan yang tak akan pernah ketahuan
     * sampai ada yang mengeluh.
     */
    public function update(Request $request, CrmTemplate $template)
    {
        $data = $this->validasi($request, $template);

        if ($template->keMeta()) {
            $template->fill([
                'title'      => $data['title'],
                'category'   => $data['category'] ?? null,
                'is_active'  => (bool) ($data['is_active'] ?? false),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
            ]);
        } else {
            $template->fill($this->isiDasar($data));

            // Baru sekarang boleh naik ke Meta — selama belum diajukan, ia masih
            // sepenuhnya milik kita.
            if ($galat = $this->siapkanMeta($template, $data)) {
                return back()->withInput()->with('error', $galat);
            }
        }

        $template->save();

        return redirect()->route('crm.template.index')
            ->with('success', 'Template "' . $template->title . '" diperbarui.');
    }

    /**
     * Hapus.
     *
     * Yang sudah diajukan ke Meta ditolak. Menghapus barisnya tidak menghapus
     * templatenya di sana, dan namanya tetap terpakai — yang tersisa cuma
     * template yang hidup di Meta tapi tak bisa lagi dipilih dari mana pun,
     * dan tak bisa pula dibuat ulang dengan nama yang sama. Matikan saja.
     */
    public function destroy(CrmTemplate $template)
    {
        if ($template->keMeta()) {
            return back()->with('error',
                'Template "' . $template->title . '" sudah didaftarkan ke Meta dan tidak bisa dihapus — '
                . 'namanya tetap terpakai di sana. Matikan saja lewat tombol Nonaktifkan.');
        }

        $template->delete();

        return back()->with('success', 'Template dihapus.');
    }

    /* ------------------------------------------------------------ ke Meta */

    /**
     * Ajukan satu template ke Meta lewat vendor (buat + submit sekaligus).
     *
     * Yang dikirim diambil dari BARIS DI BASIS DATA, bukan dari form. Bedanya
     * penting: bunyi yang sekali disetujui akan dikirim ke ribuan pelanggan,
     * dan menerimanya langsung dari HTML berarti siapa pun yang menyunting
     * halaman bisa mengajukan kalimat yang tak pernah dilihat siapa-siapa.
     */
    public function ajukan(CrmTemplate $template, ChatManager $chat)
    {
        if (! $template->keMeta()) {
            return back()->with('error',
                'Template "' . $template->title . '" tidak ditandai untuk Meta. '
                . 'Ubah dulu templatenya dan isi nama Meta-nya.');
        }

        if ($template->meta_status) {
            return back()->with('error',
                'Template "' . $template->title . '" sudah pernah diajukan (status ' . $template->meta_status . '). '
                . 'Nama template unik per bahasa — percobaan kedua akan ditolak vendor.');
        }

        $hasil = $chat->provider()->buatTemplate(
            $template->meta_name,
            'UTILITY',
            $template->body,
            array_map('strval', (array) $template->meta_variables),
            'id'
        );

        if (! ($hasil['success'] ?? false)) {
            /*
             * Kegagalan DICATAT ke barisnya, bukan cuma dikedipkan sebagai
             * pesan flash. Pengajuan yang gagal separuh jalan (template
             * tersimpan di vendor tapi belum di-submit) meninggalkan nama yang
             * sudah terpakai, dan alasannya harus masih bisa dibaca besok pagi.
             */
            $template->forceFill([
                'meta_id'    => $hasil['id'] ?? null,
                'meta_error' => (string) ($hasil['error'] ?? 'tanpa keterangan'),
            ])->save();

            return back()->with('error', 'Gagal mengajukan "' . $template->title . '": ' . $template->meta_error);
        }

        $template->forceFill([
            'meta_id'           => $hasil['id'] ?? null,
            'meta_status'       => $hasil['status'] ?? CrmTemplate::META_PENDING,
            'meta_error'        => null,
            'meta_submitted_at' => now(),
        ])->save();

        return back()->with('success',
            'Template "' . $template->title . '" diajukan ke Meta. Statusnya '
            . $template->meta_status . ' — peninjauan bisa memakan waktu sampai 24 jam. '
            . 'Muat ulang halaman ini untuk melihat perkembangannya.');
    }

    /* -------------------------------------------------------------- Chat Baru */

    /**
     * Form Chat Baru — hanya yang sudah APPROVED di Meta yang boleh dipilih.
     *
     * Nomornya boleh datang lewat ?nomor=. Itu jalan masuk dari layar chat yang
     * jendelanya sudah tutup: di situ satu-satunya cara bicara lagi memang
     * template berbayar, dan menyuruh admin menyalin nomornya sendiri adalah
     * cara paling mudah salah kirim ke orang lain.
     */
    public function formBaru(Request $request, ChatManager $chat)
    {
        return view('erp.crm.template.baru', [
            'templates' => CrmTemplate::aktif()
                ->keMeta()
                ->where('meta_status', CrmTemplate::META_APPROVED)
                ->orderBy('title')
                ->get(),
            'dryRun'    => $chat->isDryRun(),
            'nomorAwal' => $request->string('nomor')->toString(),
        ]);
    }

    public function mulai(Request $request, CrmReplyService $balasan)
    {
        $data = $request->validate([
            'nomor'      => 'required|string|max:32',
            'template'   => 'required|integer',
            'variabel'   => 'nullable|array|max:10',
            'variabel.*' => 'nullable|string|max:500',
        ]);

        /*
         * Bunyinya diambil dari baris di basis data, BUKAN dari form — sama
         * alasannya dengan ajukan(). Yang dikirim lewat form cuma id-nya, dan
         * id yang dipalsukan paling jauh cuma menunjuk template lain yang juga
         * sah, bukan kalimat karangan sendiri.
         */
        $pilihan = CrmTemplate::find($data['template']);

        if (! $pilihan || ! $pilihan->bisaBukaChat()) {
            return back()->withInput()->with('error', 'Template tidak ditemukan atau belum disetujui Meta.');
        }

        $hasil = $balasan->mulaiPercakapan(
            $data['nomor'],
            $pilihan->meta_name,
            array_values($data['variabel'] ?? []),
            $request->user()?->id,
            $pilihan->body,
        );

        if (! $hasil['success']) {
            return back()->withInput()->with('error', $hasil['error']);
        }

        return redirect()
            ->route('crm.inbox.show', $hasil['conversation'])
            ->with('success', 'Percakapan dimulai. Jendela 24 jam terbuka setelah pelanggan membalas.');
    }

    /* ---------------------------------------------------------------- bantuan */

    private function validasi(Request $request, ?CrmTemplate $abaikan = null): array
    {
        return $request->validate([
            'title'      => 'required|string|max:120',
            'body'       => 'required|string|max:2000',
            'category'   => 'nullable|string|max:60',
            'sort_order' => 'nullable|integer|min:0|max:9999',
            'is_active'  => 'nullable|boolean',
            'ke_meta'    => 'nullable|boolean',
            /*
             * Aturan nama Meta ditegakkan DI SINI, bukan diserahkan ke vendor:
             * huruf kecil, angka, garis bawah. Ditolak sekarang dengan kalimat
             * yang menyebut aturannya jauh lebih berguna daripada ditolak
             * vendor setelah barisnya terlanjur tersimpan.
             */
            'meta_name'  => [
                'nullable', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/',
                'unique:crm_templates,meta_name' . ($abaikan ? ',' . $abaikan->id : ''),
            ],
        ], [
            'meta_name.regex'  => 'Nama Meta hanya boleh huruf kecil, angka, dan garis bawah.',
            'meta_name.unique' => 'Nama Meta itu sudah dipakai template lain.',
        ]);
    }

    /** @return array<string, mixed> */
    private function isiDasar(array $data): array
    {
        return [
            'title'      => $data['title'],
            'body'       => $data['body'],
            'category'   => $data['category'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active'  => (bool) ($data['is_active'] ?? false),
        ];
    }

    /**
     * Pasang penanda Meta, dan tolak yang pasti gagal di sana.
     *
     * Dua hal yang ditolak lebih awal, keduanya karena vendor menolaknya dengan
     * alasan yang tak menyebut sebabnya:
     *
     *  1. Isian {nama}, {nomor} dsb. Itu isian MILIK KITA, diganti saat pesan
     *     dikirim dari rail chat. Meta tidak mengenalnya — yang sampai ke
     *     pelanggan adalah kurung kurawal apa adanya.
     *  2. Jumlah contoh yang tak sama dengan jumlah {{n}}. Vendor menuntut
     *     keduanya sama persis.
     *
     * @return ?string keterangan galat, atau null bila beres
     */
    private function siapkanMeta(CrmTemplate $template, array $data): ?string
    {
        if (! ($data['ke_meta'] ?? false)) {
            $template->meta_name      = null;
            $template->meta_variables = null;

            return null;
        }

        if (blank($data['meta_name'] ?? null)) {
            return 'Template yang didaftarkan ke Meta wajib punya nama Meta (huruf kecil, angka, garis bawah).';
        }

        foreach (array_keys(CrmTemplate::ISIAN) as $isian) {
            if (str_contains($template->body, $isian)) {
                return 'Isian ' . $isian . ' tidak dikenal Meta — yang sampai ke pelanggan adalah '
                     . $isian . ' apa adanya. Pakai {{1}}, {{2}}, … untuk template Meta.';
            }
        }

        $template->meta_name = $data['meta_name'];

        /*
         * Contoh nilai tiap {{n}} dibuatkan otomatis bila belum ada. Isinya
         * memang cuma pengisi tempat, tapi vendor MENOLAK pengajuan tanpa
         * mereka — dan meminta admin mengetik "contoh" satu per satu untuk
         * sesuatu yang tak pernah dibaca pelanggan cuma menambah langkah yang
         * pasti dilewati.
         */
        $jumlah = $template->jumlahVariabel();

        $template->meta_variables = $jumlah
            ? array_map(fn ($i) => 'contoh ' . $i, range(1, $jumlah))
            : [];

        return null;
    }
}
