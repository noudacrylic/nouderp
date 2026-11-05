<?php

namespace App\Modules\CRM\Agents;

use App\Modules\Assistant\Services\ClaudeClient;
use App\Modules\CRM\Models\CrmAgent;
use App\Modules\CRM\Models\CrmAgentRun;
use App\Modules\CRM\Models\CrmAttachment;
use App\Modules\CRM\Models\CrmConversation;
use Illuminate\Support\Facades\Storage;

/**
 * Menjalankan satu giliran agen: pesan pelanggan masuk, balasan keluar.
 *
 * Bentuk putarannya meniru AiAccountantService::converse() yang sudah setahun
 * jalan di produksi — termasuk dua hal yang kelihatan sepele tapi mahal kalau
 * ditemukan sendiri: `input` tool kosong yang harus dipaksa jadi objek, dan
 * hasil tool yang dikembalikan sebagai teks biasa supaya model memperbaiki diri
 * alih-alih menggagalkan giliran.
 *
 * Yang berbeda dari sana, dan disengaja:
 *  - riwayat TIDAK disimpan di cache berkunci id chat, melainkan dibaca dari
 *    crm_messages. Riwayat chat pelanggan sudah ada di sana, dipakai bersama
 *    seluruh admin, dan tidak menguap setelah 20 menit.
 *  - fakta yang berubah tiap panggilan tidak masuk blok system (lihat AgenPrompt).
 *  - tiap jalan dicatat ke crm_agent_runs beserta token, biaya, dan jejak alat.
 *
 * Kelas ini TIDAK mengirim apa pun ke pelanggan. Ia mengembalikan teks; siapa
 * yang mengirimnya — dan apakah boleh dikirim sama sekali — diputuskan di
 * tempat lain. Ronde 1 hanya punya satu pemanggil: tombol Uji di layar.
 */
class AgenRunner
{
    /** Berapa banyak pesan terakhir yang ikut dibaca sebagai riwayat. */
    private const RIWAYAT = 12;

    /** Gambar pembeli yang ikut dibaca: yang terbaru saja, supaya biaya tiap saran tetap kecil. */
    private const MAKS_GAMBAR = 3;

    /** Batas API 5 MB untuk base64; 3,5 MB berkas asli masih di bawahnya. */
    private const MAKS_BYTE_GAMBAR = 3_500_000;

    private const MAKS_SISI_GAMBAR = 8000;

    private const MEDIA_GAMBAR = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(
        private ClaudeClient $claude,
        private AgenTools $tools,
    ) {
    }

    /**
     * @return array{teks: string, status: string, jejak: array, run: ?CrmAgentRun, galat: ?string}
     */
    public function jalankan(
        CrmAgent $agen,
        string $pesanPelanggan,
        ?CrmConversation $percakapan = null,
        string $mode = CrmAgentRun::MODE_UJI,
        ?int $userId = null
    ): array {
        $mulai       = microtime(true);
        $pengetahuan = $agen->pengetahuanAktif();
        $model       = $agen->modelDipakai();

        if (! $this->claude->enabled()) {
            return $this->gagal($agen, $pengetahuan, $percakapan, $mode, $userId, $pesanPelanggan,
                'Kunci Anthropic belum diatur (Settings → Integrasi → Claude AI).', 0, []);
        }

        $messages = $this->riwayat($percakapan, (bool) config('crm.agen.baca_gambar.' . $agen->kode, false));
        $messages[] = [
            'role'    => 'user',
            'content' => AgenPrompt::keterangan($percakapan) . "\n\n" . $pesanPelanggan,
        ];

        $jejak  = [];
        $lempar = false;
        $selesai = false;
        $token  = ['masuk' => 0, 'cache' => 0, 'keluar' => 0];

        for ($putaran = 0; $putaran < max(1, (int) $agen->maks_giliran); $putaran++) {
            $resp = $this->claude->messages([
                'model'      => $model,
                'max_tokens' => (int) config('crm.agen.maks_token_per.' . $agen->kode, config('crm.agen.maks_token_keluar', 1024)),
                /*
                 * Satu breakpoint cache di blok system. Urutan render API adalah
                 * tools → system → messages, jadi satu penanda di sini ikut
                 * meng-cache seluruh definisi alat — tidak perlu breakpoint kedua.
                 */
                'system' => [[
                    'type'          => 'text',
                    'text'          => AgenPrompt::system($agen, $pengetahuan),
                    'cache_control' => ['type' => 'ephemeral'],
                ]],
                'tools'    => $this->tools->skema($agen),
                'messages' => $messages,
            ] + $this->effort($agen));

            if (isset($resp['_error'])) {
                return $this->gagal($agen, $pengetahuan, $percakapan, $mode, $userId, $pesanPelanggan,
                    (string) $resp['_error'], (int) ((microtime(true) - $mulai) * 1000), $jejak, $model, $token);
            }

            $this->kumpulkanToken($token, (array) ($resp['usage'] ?? []));

            $isi = (array) ($resp['content'] ?? []);
            $messages[] = ['role' => 'assistant', 'content' => $this->rapikanInputTool($isi)];

            if (($resp['stop_reason'] ?? '') !== 'tool_use') {
                return $this->sukses($agen, $pengetahuan, $percakapan, $mode, $userId, $pesanPelanggan,
                    $this->kumpulkanTeks($isi), $lempar, $jejak,
                    (int) ((microtime(true) - $mulai) * 1000), $model, $token);
            }

            $hasil = [];

            foreach ($isi as $blok) {
                if (($blok['type'] ?? '') !== 'tool_use') {
                    continue;
                }

                $keluaran = $this->tools->jalankan(
                    (string) $blok['name'],
                    (array) ($blok['input'] ?? []),
                    $percakapan
                );

                $lempar = $lempar || ! empty($keluaran['_lempar']);
                $selesai = $selesai || ! empty($keluaran['_selesai']);

                $jejak[] = [
                    'alat'  => $blok['name'],
                    'input' => $blok['input'] ?? [],
                    'hasil' => $keluaran['content'],
                ];

                $hasil[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $blok['id'],
                    'content'     => $keluaran['content'],
                ];
            }

            /*
             * SEMUA tool_result dikirim dalam SATU pesan user. Memecahnya jadi
             * beberapa pesan diam-diam mengajari model berhenti memanggil alat
             * secara paralel.
             */
            $messages[] = ['role' => 'user', 'content' => $hasil];

            /*
             * Alat penutup (mis. `usulkan_balasan` CS Shopee) sudah memuat jawabannya —
             * putaran berikutnya hanya akan membayar model untuk berkata "sudah".
             */
            if ($selesai) {
                return $this->sukses($agen, $pengetahuan, $percakapan, $mode, $userId, $pesanPelanggan,
                    $this->kumpulkanTeks($isi) ?: '(saran tersimpan)', $lempar, $jejak,
                    (int) ((microtime(true) - $mulai) * 1000), $model, $token);
            }
        }

        /*
         * Batas putaran habis. Ini bukan galat teknis melainkan agen yang
         * berputar tanpa pernah menjawab — dan yang benar bagi pelanggan adalah
         * diserahkan ke manusia, bukan diberi kalimat penutup asal-asalan.
         */
        return $this->sukses($agen, $pengetahuan, $percakapan, $mode, $userId, $pesanPelanggan,
            'Bentar ya Kak, saya sambungkan ke tim.', true, $jejak,
            (int) ((microtime(true) - $mulai) * 1000), $model, $token);
    }

    /** output_config.effort bila diatur untuk agen ini (config crm.agen.effort). */
    private function effort(CrmAgent $agen): array
    {
        $effort = config('crm.agen.effort.' . $agen->kode);

        return $effort ? ['output_config' => ['effort' => (string) $effort]] : [];
    }

    /* ------------------------------------------------------------------ riwayat */

    /**
     * Riwayat percakapan dari crm_messages, bukan dari cache.
     *
     * Agen dengan saklar baca_gambar ikut menerima beberapa gambar terbaru dari pelanggan.
     * Lampiran lain diringkas jadi keterangan pendek, karena gelembung kosong tanpa penanda
     * membuat agen menjawab seolah tak ada yang dikirim.
     */
    private function riwayat(?CrmConversation $percakapan, bool $bacaGambar = false): array
    {
        if (! $percakapan) {
            return [];
        }

        $pesan = $percakapan->messages()
            ->with('attachments')
            ->latest('id')
            ->limit(self::RIWAYAT)
            ->get()
            ->reverse()
            ->values();

        $terbaca  = $bacaGambar ? $this->gambarTerbaca($pesan) : [];
        $messages = [];

        foreach ($pesan as $m) {
            $teks   = trim((string) $m->content);
            $gambar = $terbaca[$m->id] ?? [];

            if ($teks === '' && $m->attachments->isNotEmpty()) {
                $pengirim = $m->isInbound() ? 'pelanggan' : 'toko';
                $teks = count($gambar) === $m->attachments->count()
                    ? "[{$pengirim} mengirim gambar]"
                    : "[{$pengirim} mengirim " . $m->attachments->count() . ' lampiran]';
            }

            if ($teks === '') {
                continue;
            }

            $peran = $m->isInbound() ? 'user' : 'assistant';
            $isi   = $gambar ? [...$gambar, ['type' => 'text', 'text' => $teks]] : $teks;

            // API menolak dua giliran beruntun dengan peran sama pada sebagian
            // bentuk; digabung saja, isinya tetap utuh.
            $akhir = count($messages) - 1;

            if ($akhir >= 0 && $messages[$akhir]['role'] === $peran) {
                $messages[$akhir]['content'] = $this->gabungIsi($messages[$akhir]['content'], $isi);
                continue;
            }

            $messages[] = ['role' => $peran, 'content' => $isi];
        }

        // Riwayat WAJIB dimulai dari pelanggan.
        while ($messages && $messages[0]['role'] !== 'user') {
            array_shift($messages);
        }

        return $messages;
    }

    /**
     * Blok gambar per id pesan, untuk gambar terbaru dari PELANGGAN (peran assistant tidak boleh
     * memuat gambar). Jenis asli dibaca dari isi berkas: kolom mime lampiran Shopee selalu
     * image/jpeg, dan media_type yang tak cocok ditolak API sehingga sarannya ikut gagal.
     * Gambar yang tak terbaca dilewati; pesannya tetap tampil sebagai keterangan lampiran.
     *
     * @return array<int, array<int, array>>
     */
    private function gambarTerbaca($pesan): array
    {
        $hasil = [];
        $sisa  = self::MAKS_GAMBAR;

        foreach ($pesan->reverse() as $m) {
            if (! $m->isInbound()) {
                continue;
            }

            foreach ($m->attachments->reverse() as $a) {
                if ($sisa <= 0) {
                    return $hasil;
                }
                if (! $a->isGambar() || ! $a->tersimpanAman() || $a->sudahDisapu()) {
                    continue;
                }
                if ($blok = $this->blokGambar($a)) {
                    $hasil[$m->id] ??= [];
                    array_unshift($hasil[$m->id], $blok);
                    $sisa--;
                }
            }
        }

        return $hasil;
    }

    private function blokGambar(CrmAttachment $a): ?array
    {
        try {
            $disk = Storage::disk($a->disk);
            if (! $disk->exists($a->path) || $disk->size($a->path) > self::MAKS_BYTE_GAMBAR) {
                return null;
            }

            $isi    = (string) $disk->get($a->path);
            $ukuran = @getimagesizefromstring($isi);
        } catch (\Throwable) {
            return null;
        }

        if (! $ukuran || ! in_array($ukuran['mime'], self::MEDIA_GAMBAR, true)
            || max($ukuran[0], $ukuran[1]) > self::MAKS_SISI_GAMBAR) {
            return null;
        }

        return ['type' => 'image', 'source' => [
            'type' => 'base64', 'media_type' => $ukuran['mime'], 'data' => base64_encode($isi),
        ]];
    }

    private function gabungIsi(string|array $lama, string|array $baru): string|array
    {
        if (is_string($lama) && is_string($baru)) {
            return $lama . "\n" . $baru;
        }

        $blok = fn ($isi) => is_string($isi) ? [['type' => 'text', 'text' => $isi]] : $isi;

        return [...$blok($lama), ...$blok($baru)];
    }

    /* -------------------------------------------------------------------- utilitas */

    /**
     * `input` tool kosong dipaksa jadi objek sebelum dikirim ulang.
     *
     * PHP mendekode `{}` jadi `[]`, yang di-encode balik sebagai array JSON, dan
     * API menolaknya dengan "input should be an object". Ditemukan mahal sekali
     * di AiAccountantService; disalin ke sini supaya tidak ditemukan dua kali.
     */
    private function rapikanInputTool(array $content): array
    {
        foreach ($content as $i => $blok) {
            if (($blok['type'] ?? '') === 'tool_use' && (! isset($blok['input']) || $blok['input'] === [])) {
                $content[$i]['input'] = (object) [];
            }
        }

        return $content;
    }

    private function kumpulkanTeks(array $content): string
    {
        $bagian = [];

        foreach ($content as $b) {
            if (($b['type'] ?? '') === 'text' && ! empty($b['text'])) {
                $bagian[] = $b['text'];
            }
        }

        return trim(implode("\n", $bagian));
    }

    /** Token ditumpuk lintas putaran — satu pesan pelanggan bisa beberapa panggilan. */
    private function kumpulkanToken(array &$token, array $usage): void
    {
        $token['masuk']  += (int) ($usage['input_tokens'] ?? 0)
                          + (int) ($usage['cache_creation_input_tokens'] ?? 0);
        $token['cache']  += (int) ($usage['cache_read_input_tokens'] ?? 0);
        $token['keluar'] += (int) ($usage['output_tokens'] ?? 0);
    }

    /* --------------------------------------------------------------- pencatatan */

    private function sukses(
        CrmAgent $agen, $pengetahuan, ?CrmConversation $percakapan, string $mode, ?int $userId,
        string $masukan, string $teks, bool $lempar, array $jejak, int $durasi, string $model, array $token
    ): array {
        $teks = $teks ?: 'Bentar ya Kak, saya sambungkan ke tim.';

        $run = $this->catat($agen, $pengetahuan, $percakapan, $mode, $userId, $masukan, [
            'status'    => $lempar ? CrmAgentRun::STATUS_DILEMPAR : CrmAgentRun::STATUS_SUKSES,
            'keluaran'  => $teks,
            'jejak'     => $jejak,
            'durasi_ms' => $durasi,
        ], $model, $token);

        return [
            'teks'   => $teks,
            'status' => $run->status,
            'jejak'  => $jejak,
            'run'    => $run,
            'galat'  => null,
        ];
    }

    private function gagal(
        CrmAgent $agen, $pengetahuan, ?CrmConversation $percakapan, string $mode, ?int $userId,
        string $masukan, string $galat, int $durasi, array $jejak,
        string $model = '', array $token = ['masuk' => 0, 'cache' => 0, 'keluar' => 0]
    ): array {
        $run = $this->catat($agen, $pengetahuan, $percakapan, $mode, $userId, $masukan, [
            'status'    => CrmAgentRun::STATUS_GAGAL,
            'galat'     => $galat,
            'jejak'     => $jejak,
            'durasi_ms' => $durasi,
        ], $model, $token);

        return ['teks' => '', 'status' => CrmAgentRun::STATUS_GAGAL, 'jejak' => $jejak, 'run' => $run, 'galat' => $galat];
    }

    private function catat(
        CrmAgent $agen, $pengetahuan, ?CrmConversation $percakapan, string $mode, ?int $userId,
        string $masukan, array $tambahan, string $model, array $token
    ): CrmAgentRun {
        return CrmAgentRun::create($tambahan + [
            'agent_id'        => $agen->id,
            'conversation_id' => $percakapan?->id,
            'knowledge_id'    => $pengetahuan?->id,
            'mode'            => $mode,
            'masukan'         => $masukan,
            'token_masuk'     => $token['masuk'],
            'token_cache'     => $token['cache'],
            'token_keluar'    => $token['keluar'],
            'biaya_rp'        => CrmAgentRun::hitungBiaya($model, $token['masuk'], $token['cache'], $token['keluar']),
            'created_by'      => $userId,
        ]);
    }
}
