<?php

namespace App\Modules\Assistant\Services;

/**
 * Claude palsu dengan jawaban terskrip — untuk tes, dan untuk mencoba layar
 * tanpa membakar kuota.
 *
 * Letaknya di app/, bukan tests/, mengikuti FakeChatProvider: driver palsu di
 * proyek ini memang tinggal bersama yang asli supaya layar diagnostik bisa
 * memeriksa instance yang SAMA dengan yang dipakai kode yang diuji.
 *
 * Mewarisi ClaudeClient (bukan antarmuka baru) dengan sengaja. Antarmuka hanya
 * berguna kalau ada dua penyedia sungguhan; membuatnya sekarang cuma menambah
 * lapisan yang harus dibaca orang tanpa satu pun keputusan yang jadi lebih
 * mudah. Kalau kelak ada penyedia kedua, di situlah ia dipisah.
 */
class FakeClaudeClient extends ClaudeClient
{
    /** @var array<int, array> antrean jawaban, dipakai berurutan */
    public array $jawaban = [];

    /** @var array<int, array> muatan yang diterima — diperiksa tes */
    public array $diterima = [];

    public function __construct()
    {
        // Sengaja TIDAK memanggil parent: konstruktor asli membaca pengaturan
        // dan kunci API, dan yang palsu tak pernah butuh keduanya.
    }

    public function enabled(): bool
    {
        return true;
    }

    public function messages(array $payload): array
    {
        $this->diterima[] = $payload;

        return array_shift($this->jawaban) ?? self::teks('(tidak ada jawaban terskrip)');
    }

    public function reset(): self
    {
        $this->jawaban  = [];
        $this->diterima = [];

        return $this;
    }

    /** Antrekan satu jawaban apa adanya. */
    public function antrekan(array $jawaban): self
    {
        $this->jawaban[] = $jawaban;

        return $this;
    }

    /* ------------------------------------------------------------ pembantu bentuk */

    /** Jawaban teks biasa yang mengakhiri giliran. */
    public static function teks(string $teks, array $usage = []): array
    {
        return [
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => $teks]],
            'usage'       => $usage + [
                'input_tokens'                => 100,
                'cache_read_input_tokens'     => 0,
                'cache_creation_input_tokens' => 0,
                'output_tokens'               => 20,
            ],
        ];
    }

    /** Jawaban yang meminta satu alat dijalankan. */
    public static function panggilAlat(string $nama, array $input = [], string $id = 'toolu_uji'): array
    {
        return [
            'stop_reason' => 'tool_use',
            'content'     => [[
                'type'  => 'tool_use',
                'id'    => $id,
                'name'  => $nama,
                'input' => $input,
            ]],
            'usage' => [
                'input_tokens'                => 100,
                'cache_read_input_tokens'     => 0,
                'cache_creation_input_tokens' => 0,
                'output_tokens'               => 20,
            ],
        ];
    }

    /** Kegagalan dari sisi API. */
    public static function galat(string $pesan, ?int $status = 500): array
    {
        return ['_error' => $pesan, '_status' => $status];
    }
}
