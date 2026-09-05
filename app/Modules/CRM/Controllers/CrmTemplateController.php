<?php

namespace App\Modules\CRM\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Services\CrmReplyService;
use Illuminate\Http\Request;

/**
 * Template pesan & memulai percakapan baru.
 *
 * Dua layar yang saling menempel, karena memang satu urusan: template hanya
 * berguna kalau bisa dipakai, dan Chat Baru mustahil tanpa template.
 *
 * ATURAN YANG MENDASARI SELURUH LAYAR INI: ke nomor yang belum menghubungi
 * kita dalam 24 jam terakhir, pesan bebas SELALU ditolak — tidak peduli
 * disusun manusia atau robot. Satu-satunya jalan sah adalah template yang
 * sudah disetujui Meta. Karena itu di sini tidak ada kotak ketik bebas sama
 * sekali; menyediakannya berarti menjanjikan sesuatu yang pasti gagal.
 */
class CrmTemplateController extends Controller
{
    /**
     * Bunyi template yang sudah disepakati, siap disalin ke dasbor vendor.
     * Ditaruh di kode (bukan sekadar catatan) supaya yang diajukan ke Meta
     * persis sama dengan yang diandalkan ERP saat mengirim.
     */
    public const USULAN = [
        [
            'nama'     => 'sapa_umum',
            'kategori' => 'UTILITY',
            'guna'     => 'Menyapa duluan nomor yang ditinggalkan pelanggan di toko. Tanpa variabel — tidak mungkin salah isi.',
            'body'     => 'Selamat siang, kami dari Noud Acrylic. Kami ingin melanjutkan pembahasan pesanan Anda. Mohon balas pesan ini ya.',
        ],
        [
            'nama'     => 'konfirmasi_desain',
            'kategori' => 'UTILITY',
            'guna'     => 'Menyapa terkait pesanan yang sudah ada. Menempel pada transaksi berjalan, jadi peluang masuk UTILITY lebih besar.',
            'body'     => 'Halo {{1}}, kami dari Noud Acrylic ingin mengonfirmasi desain untuk pesanan {{2}}. Mohon balas pesan ini agar kami kirimkan pratinjaunya.',
        ],
        [
            'nama'     => 'pembayaran_diterima',
            'kategori' => 'UTILITY',
            'guna'     => 'Satu template melayani DP dan pelunasan — {{4}} sengaja teks bebas.',
            'body'     => 'Halo {{1}}, pembayaran sebesar Rp{{2}} untuk pesanan {{3}} sudah kami terima. Status pembayaran saat ini: {{4}}. Terima kasih atas kepercayaan Anda.',
        ],
        [
            'nama'     => 'pesanan_siap_diambil',
            'kategori' => 'UTILITY',
            'guna'     => 'Jam toko sengaja jadi variabel — jam berubah saat Lebaran, dan mengubah body template berarti ajukan ulang ke Meta.',
            'body'     => 'Halo {{1}}, pesanan {{2}} sudah selesai dan siap diambil di toko kami. Tunjukkan kode pengambilan {{3}} kepada petugas. Kami buka {{4}}. Di luar jam tersebut, silakan balas pesan ini untuk membuat janji pengambilan.',
        ],
        [
            'nama'     => 'pesanan_dikirim',
            'kategori' => 'UTILITY',
            'guna'     => 'Tombol URL dinamis → halaman lacak pesanan.',
            'body'     => 'Halo {{1}}, pesanan {{2}} sudah kami serahkan ke {{3}} dengan nomor resi {{4}}. Silakan pantau perjalanan paket Anda lewat tombol di bawah ini.',
        ],
    ];

    public function index(ChatManager $chat)
    {
        return view('erp.crm.template.index', [
            'daftar' => $chat->provider()->templates(),
            'usulan' => self::USULAN,
            'dryRun' => $chat->isDryRun(),
        ]);
    }

    /** Form Chat Baru — hanya template APPROVED yang boleh dipilih. */
    public function formBaru(ChatManager $chat)
    {
        $hasil = $chat->provider()->templates();

        return view('erp.crm.template.baru', [
            'templates' => collect($hasil['templates'] ?? [])
                ->where('status', 'APPROVED')
                ->values(),
            'error'  => $hasil['error'] ?? null,
            'dryRun' => $chat->isDryRun(),
        ]);
    }

    public function mulai(Request $request, ChatManager $chat, CrmReplyService $balasan)
    {
        $data = $request->validate([
            'nomor'      => 'required|string|max:32',
            'template'   => 'required|string|max:120',
            'variabel'   => 'nullable|array|max:10',
            'variabel.*' => 'nullable|string|max:500',
        ]);

        /*
         * Bunyi & bahasa diambil dari daftar vendor, BUKAN dari form. Kalau
         * dikirim lewat form, siapa pun yang mengubah HTML bisa menyimpan bunyi
         * palsu ke riwayat — thread akan menampilkan kalimat yang tidak pernah
         * dikirim, dan itu jenis kekeliruan yang tak akan pernah ketahuan.
         */
        $pilihan = collect($chat->provider()->templates()['templates'] ?? [])
            ->firstWhere('name', $data['template']);

        if (! $pilihan || $pilihan['status'] !== 'APPROVED') {
            return back()->withInput()->with('error', 'Template tidak ditemukan atau belum disetujui Meta.');
        }

        $hasil = $balasan->mulaiPercakapan(
            $data['nomor'],
            $pilihan['name'],
            array_values($data['variabel'] ?? []),
            $request->user()?->id,
            $pilihan['body'] ?? null,
            $pilihan['language'] ?? 'id',
        );

        if (! $hasil['success']) {
            return back()->withInput()->with('error', $hasil['error']);
        }

        return redirect()
            ->route('crm.inbox.show', $hasil['conversation'])
            ->with('success', 'Percakapan dimulai. Jendela 24 jam terbuka setelah pelanggan membalas.');
    }
}
