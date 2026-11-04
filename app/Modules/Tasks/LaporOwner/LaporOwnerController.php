<?php

namespace App\Modules\Tasks\LaporOwner;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Modules\Notifications\Services\PusatNotifikasi;
use App\Modules\Notifications\Services\TelegramNotifier;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use App\Modules\Tasks\MejaKerja\Skills\LaporOwnerSkill;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Lapor ke Owner: admin mengangkat apa pun yang perlu diketahui owner (saldo belum diisi,
 * transaksi belum tercatat, masalah lain). Kartunya dibuat skill Asisten Owner di Meja Owner;
 * balasan owner kembali ke pelapor lewat lonceng & Telegram.
 */
class LaporOwnerController extends Controller
{
    /** Anggota meja admin (Layanan, Desain). */
    public static function bolehLapor(User $u): bool
    {
        return MejaKerjaPeran::where('user_id', $u->id)->whereIn('peran', ['layanan', 'desain'])->exists();
    }

    public function store(Request $request, TelegramNotifier $telegram, KaryawanVirtualService $kv)
    {
        $u = $request->user();
        abort_unless(self::bolehLapor($u), 403, 'Lapor ke Owner hanya untuk admin.');

        $data = $request->validate([
            'isi'     => 'required|string|max:2000',
            'penting' => 'nullable|boolean',
            'foto'    => 'nullable|file|mimes:jpg,jpeg,png,webp|max:20480',
        ]);

        $lapor = LaporOwner::create([
            'pelapor_id' => $u->id,
            'isi'        => trim($data['isi']),
            'penting'    => $request->boolean('penting'),
            'foto_path'  => $request->file('foto')?->store('lapor-owner', 'local'),
        ]);

        // Kartu langsung muncul; sinkron 2 menitan tetap jadi cadangan bila ini gagal.
        try {
            $kv->jalankan(LaporOwnerSkill::KUNCI_KV, KaryawanVirtualService::PEMICU_PERISTIWA);
        } catch (\Throwable $e) {
            Log::warning('LaporOwner: kartu gagal dibuat langsung', ['lapor' => $lapor->id, 'error' => $e->getMessage()]);
        }

        $telegram->notifyOwners(($lapor->penting ? '⚠️ Laporan penting' : 'Laporan') . " dari {$u->name}:\n{$lapor->isi}\n" . route('tasks.meja.index'));

        return back()->with('success', 'Laporan terkirim ke owner.');
    }

    public function balas(Request $request, LaporOwner $lapor, PusatNotifikasi $pusat, TelegramNotifier $telegram)
    {
        $u = $request->user();
        abort_unless($this->owner($u), 403, 'Hanya owner yang bisa membalas laporan.');
        abort_if($lapor->dibalas_at, 422, 'Laporan ini sudah dibalas.');

        $data = $request->validate(['teks' => 'required|string|max:1000']);
        $lapor->update(['balasan' => trim($data['teks']), 'dibalas_oleh' => $u->id, 'dibalas_at' => now()]);

        // Kartunya tutup sekarang, tidak menunggu sinkron: owner baru saja menyelesaikannya.
        app(MejaKerjaService::class)->sinkronkanSkill(app(LaporOwnerSkill::class));

        $pusat->keUser($lapor->pelapor, 'lapor_owner', 'Balasan owner', $lapor->balasan, ['url' => route('tasks.meja.index')]);
        $telegram->notifyUser($lapor->pelapor, "Balasan owner atas laporan Anda:\n{$lapor->isi}\n\n{$lapor->balasan}");

        return back()->with('success', 'Balasan terkirim.');
    }

    public function foto(Request $request, LaporOwner $lapor)
    {
        $u = $request->user();
        abort_unless($this->owner($u) || (int) $lapor->pelapor_id === (int) $u->id, 403);
        abort_unless($lapor->foto_path && Storage::disk('local')->exists($lapor->foto_path), 404);

        return Storage::disk('local')->response($lapor->foto_path);
    }

    private function owner(User $u): bool
    {
        return $u->role === 'super_admin' || MejaKerjaPeran::where('user_id', $u->id)->where('peran', 'owner')->exists();
    }
}
