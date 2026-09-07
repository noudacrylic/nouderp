<?php

namespace App\Modules\CRM\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmLabel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Pengaturan label percakapan.
 *
 * Sengaja satu layar tanpa halaman edit terpisah: label cuma punya tiga isian,
 * dan bolak-balik halaman untuk mengganti satu kata adalah cara tercepat
 * membuat fitur ini tidak pernah dipakai.
 */
class CrmLabelController extends Controller
{
    public function index()
    {
        return view('erp.crm.label.index', [
            'labels' => CrmLabel::semua(),
            'jumlah' => $this->jumlahPemakaian(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nama'  => ['required', 'string', 'max:60'],
            'warna' => ['required', Rule::in(array_keys(CrmLabel::WARNA))],
        ]);

        /*
         * Kode diturunkan dari nama SEKALI saat lahir, lalu dibekukan. Kalau ia
         * ikut berubah tiap kali nama disunting, percakapan yang sudah memakai
         * label itu langsung yatim — kodenya tidak cocok dengan apa pun.
         */
        $kode = Str::slug($data['nama'], '_') ?: 'label';
        $asal = $kode;
        $n    = 2;
        while (CrmLabel::where('kode', $kode)->exists()) {
            $kode = $asal . '_' . $n++;
        }

        CrmLabel::create([
            'kode'   => $kode,
            'nama'   => $data['nama'],
            'warna'  => $data['warna'],
            'urutan' => (int) (CrmLabel::max('urutan') + 1),
            'aktif'  => true,
        ]);

        return back()->with('success', 'Label "' . $data['nama'] . '" ditambahkan.');
    }

    public function update(Request $request, CrmLabel $label)
    {
        $data = $request->validate([
            'nama'   => ['required', 'string', 'max:60'],
            'warna'  => ['required', Rule::in(array_keys(CrmLabel::WARNA))],
            'urutan' => ['nullable', 'integer', 'min:0', 'max:999'],
            'aktif'  => ['nullable', 'boolean'],
        ]);

        $label->update([
            'nama'   => $data['nama'],
            'warna'  => $data['warna'],
            'urutan' => $data['urutan'] ?? $label->urutan,
            'aktif'  => (bool) ($data['aktif'] ?? false),
        ]);

        return back()->with('success', 'Label diperbarui.');
    }

    public function destroy(CrmLabel $label)
    {
        $dipakai = CrmConversation::where('queue_state', $label->kode)->count();

        if ($dipakai > 0) {
            return back()->with('error',
                'Label "' . $label->nama . '" masih dipakai ' . $dipakai . ' percakapan. ' .
                'Nonaktifkan saja — chat lama tetap punya nama, tapi label ini tidak lagi bisa dipilih.');
        }

        $label->delete();

        return back()->with('success', 'Label dihapus.');
    }

    /** Berapa percakapan aktif memakai tiap label — angka di kolom "Dipakai". */
    private function jumlahPemakaian(): array
    {
        return CrmConversation::query()
            ->where('status', CrmConversation::STATUS_AKTIF)
            ->selectRaw('queue_state, COUNT(*) as total')
            ->groupBy('queue_state')
            ->pluck('total', 'queue_state')
            ->all();
    }
}
