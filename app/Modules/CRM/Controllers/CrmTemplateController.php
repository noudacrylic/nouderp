<?php

namespace App\Modules\CRM\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Services\CrmReplyService;
use App\Modules\CRM\Support\TemplateResmi;
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
     * Daftarnya pindah ke Support\TemplateResmi karena kini dipakai dua jalur
     * (resmi mengirim namanya, WAHA merangkai teksnya); alias ini dipertahankan
     * supaya layar & tes lama tetap menyebut satu nama yang sama.
     */
    public const USULAN = TemplateResmi::USULAN;

    public function index(ChatManager $chat)
    {
        return view('erp.crm.template.index', [
            'daftar' => $chat->provider()->templates(),
            'usulan' => self::USULAN,
            'dryRun' => $chat->isDryRun(),
        ]);
    }

    /**
     * Ajukan satu bunyi yang sudah disepakati ke Meta lewat vendor.
     *
     * Yang dikirim diambil dari TemplateResmi berdasarkan NAMANYA, bukan dari
     * isian form. Bedanya penting: bunyi template adalah sesuatu yang sekali
     * disetujui akan dikirim ke ribuan pelanggan, dan mengizinkannya diketik di
     * layar berarti kalimat yang beredar bisa berbeda dari yang dipakai kode —
     * lalu jalur WAHA dan jalur resmi diam-diam mengucapkan hal yang berbeda.
     */
    public function ajukan(Request $request, ChatManager $chat)
    {
        $data = $request->validate(['nama' => 'required|string|max:120']);

        $usulan = TemplateResmi::usulan($data['nama']);

        if (! $usulan) {
            return back()->with('error', 'Template "' . $data['nama'] . '" tidak dikenal.');
        }

        /*
         * Yang khusus WAHA TIDAK boleh diajukan. Nadanya mengingatkan &
         * menawarkan, jadi Meta akan menggolongkannya MARKETING — dan
         * penggolongan itu menempel pada nomornya, bukan pada satu template.
         */
        if (! empty($usulan['hanya_waha'])) {
            return back()->with('error',
                'Template "' . $usulan['nama'] . '" khusus jalur WAHA dan tidak boleh diajukan ke Meta. '
                . 'Nadanya akan digolongkan MARKETING.');
        }

        $hasil = $chat->provider()->buatTemplate(
            $usulan['nama'],
            $usulan['kategori'],
            $usulan['body'],
            TemplateResmi::contoh($usulan['nama'])
        );

        if (! ($hasil['success'] ?? false)) {
            return back()->with('error', 'Gagal mengajukan "' . $usulan['nama'] . '": ' . ($hasil['error'] ?? 'tanpa keterangan'));
        }

        return back()->with('success',
            'Template "' . $usulan['nama'] . '" diajukan ke Meta. Statusnya '
            . ($hasil['status'] ?? 'PENDING') . ' — peninjauan bisa memakan waktu sampai 24 jam. '
            . 'Muat ulang halaman ini untuk melihat perkembangannya.');
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
