<?php

namespace App\Modules\CRM\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Models\CrmSaranAi;
use Illuminate\Http\Request;

/**
 * Pemeriksaan manual saran AI. Koreksi yang disimpan ikut terbaca agen pada saran berikutnya
 * (AgenPrompt::koreksi), jadi tidak perlu menunggu daftar tanya-jawab diperbarui.
 */
class CrmSaranPeriksaController extends Controller
{
    public function index(Request $request)
    {
        $periksa = $request->input('periksa', 'belum');
        $butir   = trim((string) $request->input('butir', ''));
        $lempar  = (string) $request->input('lempar', '');
        $oto     = (string) $request->input('otomatis', '');

        $dasar = CrmSaranAi::query()->where(fn ($q) => $q->whereNotNull('teks')->orWhereNotNull('lempar_ke'));

        $daftar = (clone $dasar)
            ->with(['conversation:id,display_name,contact_key,channel', 'pesan:id,conversation_id,content,sent_at', 'pesan.attachments:id,message_id', 'peninjau:id,name'])
            ->when($periksa === 'belum', fn ($q) => $q->whereNull('tinjauan'))
            ->when(in_array($periksa, [CrmSaranAi::TEPAT, CrmSaranAi::SALAH], true), fn ($q) => $q->where('tinjauan', $periksa))
            ->when($butir !== '', fn ($q) => $q->where('butir', 'like', $butir . '%'))
            ->when($lempar === 'tidak', fn ($q) => $q->whereNull('lempar_ke'))
            ->when(in_array($lempar, ['admin', 'desain', 'owner'], true), fn ($q) => $q->where('lempar_ke', $lempar))
            ->when(in_array($oto, [CrmSaranAi::OTO_TERKIRIM, CrmSaranAi::OTO_BAYANGAN, CrmSaranAi::OTO_GAGAL], true), fn ($q) => $q->where('otomatis', $oto))
            ->orderByDesc('id')
            ->paginate(per_page_size())
            ->withQueryString();

        $konteks = $daftar->getCollection()->mapWithKeys(fn (CrmSaranAi $s) => [$s->id => $s->pesan
            ? CrmMessage::where('conversation_id', $s->conversation_id)
                ->where('sent_at', '<', $s->pesan->sent_at)
                ->orderByDesc('sent_at')->limit(4)->get(['direction', 'content', 'source'])->reverse()->values()
            : collect()]);

        return view('erp.crm.agen.saran', [
            'daftar'  => $daftar,
            'konteks' => $konteks,
            'periksa' => $periksa,
            'jumlah'  => [
                'belum' => (clone $dasar)->whereNull('tinjauan')->count(),
                'tepat' => (clone $dasar)->where('tinjauan', CrmSaranAi::TEPAT)->count(),
                'salah' => (clone $dasar)->where('tinjauan', CrmSaranAi::SALAH)->count(),
            ],
        ]);
    }

    public function tinjau(Request $request, CrmSaranAi $saran)
    {
        $data = $request->validate([
            'tinjauan' => 'required|in:' . CrmSaranAi::TEPAT . ',' . CrmSaranAi::SALAH,
            'koreksi'  => 'required_if:tinjauan,' . CrmSaranAi::SALAH . '|nullable|string|max:1000',
        ], ['koreksi.required_if' => 'Tulis yang benar supaya AI bisa belajar dari koreksi ini.']);

        $saran->forceFill([
            'tinjauan'      => $data['tinjauan'],
            'koreksi'       => $data['tinjauan'] === CrmSaranAi::SALAH ? trim((string) $data['koreksi']) : null,
            'ditinjau_oleh' => $request->user()?->id,
            'ditinjau_at'   => now(),
        ])->save();

        return back()->with('success', $data['tinjauan'] === CrmSaranAi::SALAH
            ? 'Koreksi tersimpan dan dipakai AI mulai saran berikutnya.'
            : 'Ditandai tepat.');
    }
}
