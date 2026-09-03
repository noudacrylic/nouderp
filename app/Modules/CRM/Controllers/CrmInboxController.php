<?php

namespace App\Modules\CRM\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmAttachment;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Services\CrmReplyService;
use Illuminate\Http\Request;
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
        $antrean = $request->string('antrean')->toString();
        $status  = $request->string('status')->toString() ?: CrmConversation::STATUS_AKTIF;

        $percakapan = CrmConversation::query()
            ->with(['customer:id,name', 'owner:id,name'])
            ->where('status', $status)
            ->when($antrean, fn ($q) => $q->where('queue_state', $antrean))
            ->when($request->filled('pemilik'), function ($q) use ($request) {
                $request->pemilik === 'belum'
                    ? $q->whereNull('owner_user_id')
                    : $q->where('owner_user_id', $request->pemilik);
            })
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

        return view('erp.crm.inbox.index', [
            'percakapan' => $percakapan,
            'jumlah'     => $this->jumlahPerAntrean(),
            'pemilikOpsi' => User::assignable()->orderBy('name')->get(['id', 'name']),
            'dryRun'     => app(ChatManager::class)->isDryRun(),
        ]);
    }

    public function show(CrmConversation $conversation)
    {
        $conversation->load(['customer', 'owner']);

        $pesan = $conversation->messages()
            ->with('attachments')
            ->orderBy('sent_at')
            ->orderBy('id')
            ->get();

        /*
         * Membuka thread menandai TERBACA, tapi TIDAK memindahkan antrean.
         * Membaca bukan menjawab — kalau membuka saja sudah menggeser bola ke
         * pelanggan, pekerjaan yang belum dikerjakan akan hilang dari daftar.
         */
        if ($conversation->unread_count > 0) {
            $conversation->forceFill(['unread_count' => 0])->save();
        }

        return view('erp.crm.inbox.show', [
            'percakapan'  => $conversation,
            'pesan'       => $pesan,
            'pemilikOpsi' => User::assignable()->orderBy('name')->get(['id', 'name']),
            'dryRun'      => app(ChatManager::class)->isDryRun(),
        ]);
    }

    public function balas(Request $request, CrmConversation $conversation, CrmReplyService $balasan)
    {
        $data = $request->validate([
            'teks'     => 'required|string|max:4000',
            'reply_to' => 'nullable|string|max:120',
        ]);

        $hasil = $balasan->balas($conversation, $data['teks'], $request->user()?->id, $data['reply_to'] ?? null);

        return $hasil['success']
            ? back()->with('success', 'Balasan terkirim.')
            : back()->withInput()->with('error', $hasil['error']);
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
