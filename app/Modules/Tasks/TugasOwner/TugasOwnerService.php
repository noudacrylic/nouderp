<?php

namespace App\Modules\Tasks\TugasOwner;

use App\Models\User;
use App\Modules\Galeri\Services\GaleriException;
use App\Modules\Galeri\Services\GaleriService;
use App\Modules\Notifications\Services\TelegramNotifier;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use App\Modules\Tasks\TugasOwner\Models\TugasOwner as Tugas;
use App\Modules\Tasks\TugasOwner\Models\TugasOwnerLampiran as Lampiran;
use App\Modules\Tasks\TugasOwner\Models\TugasOwnerPesan as Pesan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Alur Tugas Owner. Yang dijaga: maksud owner benar-benar tersampaikan, jadi admin menulis ulang
 * yang ia tangkap sebelum mulai, dan tugas selesai karena owner menyetujui hasilnya.
 *
 *   draf → (brief) ringkasan → (ringkasan) cek_ringkasan → (setujui_ringkasan) dikerjakan
 *        ↑ koreksi kembali ke ringkasan
 *   dikerjakan → (hasil) periksa → (setujui_hasil) selesai; revisi kembali ke dikerjakan
 */
class TugasOwnerService
{
    /**
     * aksi => [tahap asal, tahap tujuan, pelaku, isi: 'teks' | 'bebas' (teks/lampiran) | null].
     * Tujuan null = tetap di tahap yang sama. Komentar berlaku di semua tahap yang masih berjalan.
     */
    private const ALUR = [
        'brief'             => [[Tugas::DRAF], Tugas::RINGKASAN, 'owner', 'bebas'],
        'ringkasan'         => [[Tugas::RINGKASAN], Tugas::CEK_RINGKASAN, 'pengerja', 'teks'],
        'koreksi'           => [[Tugas::CEK_RINGKASAN], Tugas::RINGKASAN, 'owner', 'bebas'],
        'setujui_ringkasan' => [[Tugas::CEK_RINGKASAN], Tugas::DIKERJAKAN, 'owner', null],
        'hasil'             => [[Tugas::DIKERJAKAN], Tugas::PERIKSA, 'pengerja', 'bebas'],
        'revisi'            => [[Tugas::PERIKSA], Tugas::DIKERJAKAN, 'owner', 'bebas'],
        'setujui_hasil'     => [[Tugas::PERIKSA], Tugas::SELESAI, 'owner', null],
        'komentar'          => [[Tugas::RINGKASAN, Tugas::CEK_RINGKASAN, Tugas::DIKERJAKAN, Tugas::PERIKSA], null, 'keduanya', 'bebas'],
        'batal'             => [[Tugas::DRAF, Tugas::RINGKASAN, Tugas::CEK_RINGKASAN, Tugas::DIKERJAKAN, Tugas::PERIKSA], Tugas::BATAL, 'owner', null],
    ];

    /** Yang ditunggu dari siapa di tiap tahap: penentu tombol & lencana di Meja Kerja. */
    private const GILIRAN_PENGERJA = [Tugas::RINGKASAN, Tugas::DIKERJAKAN];
    private const GILIRAN_OWNER = [Tugas::DRAF, Tugas::CEK_RINGKASAN, Tugas::PERIKSA];

    public function __construct(private TelegramNotifier $telegram) {}

    public function penyimpanan(): GaleriService
    {
        return new GaleriService((string) config('tugas_owner.root'), ['suara' => config('tugas_owner.ekstensi_suara')]);
    }

    public function owner(User $u): bool
    {
        return $u->role === 'super_admin' || MejaKerjaPeran::where('user_id', $u->id)->where('peran', 'owner')->exists();
    }

    public function bolehLihat(User $u, Tugas $t): bool
    {
        if ($this->owner($u)) return true;

        return (int) $t->pengerja_id === (int) $u->id && $t->tahap !== Tugas::DRAF;
    }

    /** Tugas yang boleh dilihat: owner semua, admin miliknya (draf belum terkirim tidak). */
    public function untuk(User $u): Builder
    {
        return Tugas::query()->when(!$this->owner($u), fn ($q) => $q->where('pengerja_id', $u->id)->where('tahap', '!=', Tugas::DRAF));
    }

    /** Lencana tombol di Meja Kerja: yang sedang menunggu orang ini. */
    public function jumlahMenunggu(User $u): int
    {
        return $this->owner($u)
            ? Tugas::whereIn('tahap', self::GILIRAN_OWNER)->count()
            : Tugas::where('pengerja_id', $u->id)->whereIn('tahap', self::GILIRAN_PENGERJA)->count();
    }

    public function giliran(User $u, Tugas $t): bool
    {
        return (in_array($t->tahap, self::GILIRAN_OWNER, true) && $this->owner($u))
            || (in_array($t->tahap, self::GILIRAN_PENGERJA, true) && (int) $t->pengerja_id === (int) $u->id);
    }

    /** Aksi yang boleh dilakukan orang ini pada tahap sekarang. @return string[] */
    public function aksiTersedia(User $u, Tugas $t): array
    {
        return array_keys(array_filter(self::ALUR, fn ($a) => in_array($t->tahap, $a[0], true) && $this->pelakuCocok($u, $t, $a[2])));
    }

    /** @return \Illuminate\Support\Collection<int, User> admin yang bisa ditugasi: anggota meja Layanan & Desain */
    public function calonPengerja()
    {
        return User::whereIn('id', MejaKerjaPeran::whereIn('peran', ['layanan', 'desain'])->select('user_id'))
            ->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Satu langkah alur.
     *
     * @param  int[]  $lampiranId  lampiran yang baru diunggah orang ini ke tugas ini
     * @throws TugasOwnerException
     */
    public function jalankan(Tugas $t, string $aksi, User $u, ?string $isi = null, array $lampiranId = []): Tugas
    {
        $alur = self::ALUR[$aksi] ?? throw new TugasOwnerException('Aksi tidak dikenal.');
        [$dari, $ke, $pelaku, $syarat] = $alur;

        if (!$this->pelakuCocok($u, $t, $pelaku)) throw new TugasOwnerException('Langkah ini bukan bagian Anda.', 403);
        if (!in_array($t->tahap, $dari, true)) throw new TugasOwnerException('Tugas sudah di tahap ' . Tugas::LABEL_TAHAP[$t->tahap] . '. Muat ulang halaman.');

        $isi = trim((string) $isi);
        $lampiran = Lampiran::where('tugas_id', $t->id)->where('user_id', $u->id)->whereNull('pesan_id')->whereIn('id', $lampiranId)->get();
        if ($syarat === 'teks' && $isi === '') throw new TugasOwnerException('Tulis dulu apa yang Anda tangkap dari brief ini.');
        if ($syarat === 'bebas' && $isi === '' && $lampiran->isEmpty()) throw new TugasOwnerException('Isi pesan atau lampirkan rekaman dulu.');

        DB::transaction(function () use ($t, $aksi, $u, $isi, $lampiran, $ke) {
            $pesan = Pesan::create(['tugas_id' => $t->id, 'user_id' => $u->id, 'jenis' => $aksi, 'isi' => $isi !== '' ? $isi : null]);
            Lampiran::whereIn('id', $lampiran->pluck('id'))->update(['pesan_id' => $pesan->id]);

            if ($aksi === 'revisi') $t->jumlah_revisi++;
            if ($ke) $t->tahap = $aksi === 'hasil' && !$t->wajib_periksa ? Tugas::SELESAI : $ke;
            if ($t->tahap === Tugas::SELESAI) $t->selesai_at = now();
            $t->save();
        });

        $this->kabari($t, $aksi, $u);

        return $t;
    }

    /**
     * Satu potongan unggahan. Berkas utuh dicatat sebagai lampiran menggantung, baru menempel
     * ke pesan saat pesan dikirim. Penolakan saat NAS putus dari GaleriService.
     *
     * @return array{selesai: bool, diterima: int, lampiran?: array}
     * @throws GaleriException
     */
    public function terimaPotongan(Tugas $t, User $u, string $id, int $offset, int $total, string $nama, ?string $rekaman, UploadedFile $potongan): array
    {
        $simpan = $this->penyimpanan();
        $folder = $t->created_at->format('Y-m') . '/tugas-' . $t->id;
        if ($offset === 0 && $simpan->tersambung() && !is_dir($simpan->root() . '/' . $folder)) {
            @mkdir($simpan->root() . '/' . $folder, 0775, true);
        }

        $h = $simpan->terimaPotongan((int) $u->id, $id, $offset, $total, $nama, $folder, $potongan);
        if (!$h['selesai']) return $h;

        // Rekaman suara browser ber-ekstensi .webm, sama dengan video: diberi tahu perekamnya.
        $jenis = $rekaman === 'suara' ? 'suara' : $simpan->jenis(basename($h['path']));
        $lampiran = Lampiran::create(['tugas_id' => $t->id, 'user_id' => $u->id, 'path' => $h['path'], 'nama' => basename($h['path']), 'jenis' => $jenis, 'ukuran' => $total]);

        return $h + ['lampiran' => ['id' => $lampiran->id, 'nama' => $lampiran->nama, 'jenis' => $jenis]];
    }

    public function pathLampiran(Lampiran $l): string
    {
        return $this->penyimpanan()->berkas($l->path);
    }

    private function pelakuCocok(User $u, Tugas $t, string $pelaku): bool
    {
        $pengerja = (int) $t->pengerja_id === (int) $u->id;

        return match ($pelaku) {
            'owner'    => $this->owner($u),
            'pengerja' => $pengerja,
            default    => $pengerja || $this->owner($u),
        };
    }

    private function kabari(Tugas $t, string $aksi, User $u): void
    {
        $kalimat = match ($aksi) {
            'brief'             => ['pengerja', '📝 Tugas baru dari owner. Tonton briefnya, lalu tulis apa yang kamu tangkap'],
            'ringkasan'         => ['owner', '📝 Pemahaman admin menunggu dicek'],
            'koreksi'           => ['pengerja', '✏️ Owner mengoreksi pemahamanmu'],
            'setujui_ringkasan' => ['pengerja', '✅ Pemahaman disetujui, silakan dikerjakan'],
            'hasil'             => ['owner', $t->tahap === Tugas::SELESAI ? '✅ Tugas selesai dikerjakan' : '📤 Hasil tugas menunggu diperiksa'],
            'revisi'            => ['pengerja', '🔁 Owner minta revisi'],
            'setujui_hasil'     => ['pengerja', '🎉 Hasil disetujui, tugas selesai'],
            'batal'             => ['pengerja', '🚫 Tugas dibatalkan owner'],
            'komentar'          => [(int) $u->id === (int) $t->pengerja_id ? 'owner' : 'pengerja', '💬 Pesan baru di tugas'],
            default             => null,
        };
        if (!$kalimat) return;

        try {
            $penerima = $kalimat[0] === 'owner' ? $t->pembuat : $t->pengerja;
            $this->telegram->notifyUser($penerima, $kalimat[1] . ":\n" . $t->judul . "\n" . route('tasks.meja.tugas.show', $t->id));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
