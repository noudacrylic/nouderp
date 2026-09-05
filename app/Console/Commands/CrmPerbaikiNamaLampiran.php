<?php

namespace App\Console\Commands;

use App\Modules\CRM\Models\CrmAttachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Kembalikan nama & ekstensi lampiran lama yang tersimpan tanpa keduanya.
 *
 * Sebelum alias `raw.<jenis>.filename` dikenali, lampiran kiriman pelanggan
 * tersimpan sebagai berkas TANPA EKSTENSI ('9', '3'). Isinya utuh — yang hilang
 * cuma keterangan jenisnya, dan itu bisa diambil kembali dari dua tempat:
 * payload yang masih tersimpan di kolom `raw` pesannya, atau dari isi berkasnya
 * sendiri (magic bytes) bila payload tak menyebutkan apa-apa.
 */
class CrmPerbaikiNamaLampiran extends Command
{
    protected $signature = 'crm:perbaiki-nama-lampiran {--dry-run : Tampilkan saja, jangan ubah apa pun}';

    protected $description = 'Kembalikan nama & ekstensi lampiran lama yang tersimpan tanpa ekstensi';

    public function handle(): int
    {
        $kering = (bool) $this->option('dry-run');
        $ubah   = 0;

        foreach (CrmAttachment::whereNotNull('path')->with('message')->get() as $l) {
            $disk = Storage::disk($l->disk);

            if (! $disk->exists($l->path)) {
                continue;
            }

            $nama = $l->original_name ?: $this->namaDariPayload($l);
            $mime = $this->mimeNyata($disk->path($l->path)) ?: $l->mime;
            $ext  = $nama ? Str::lower(pathinfo($nama, PATHINFO_EXTENSION)) : '';

            if ($ext === '') {
                $ext = $this->ekstensiDariMime($mime);
            }

            $pathBaru = $ext && ! Str::endsWith($l->path, '.' . $ext)
                ? preg_replace('~\.[^./]*$~', '', $l->path) . '.' . $ext
                : $l->path;

            if ($pathBaru === $l->path && $l->original_name && $l->mime === $mime) {
                continue;
            }

            $this->line(sprintf(
                '#%d %s → %s  (%s)',
                $l->id,
                $l->path,
                $pathBaru,
                $nama ?: 'nama tidak diketahui'
            ));

            if ($kering) {
                $ubah++;
                continue;
            }

            if ($pathBaru !== $l->path) {
                $disk->move($l->path, $pathBaru);
            }

            $l->forceFill([
                'path'          => $pathBaru,
                'original_name' => $nama ?: basename($pathBaru),
                'mime'          => $mime,
            ])->save();

            $ubah++;
        }

        $this->info($kering
            ? "{$ubah} lampiran akan diperbaiki (uji coba, tak ada yang diubah)."
            : "{$ubah} lampiran diperbaiki.");

        return self::SUCCESS;
    }

    /** Nama asli masih tersimpan di payload pesannya — itu sumber paling tepercaya. */
    private function namaDariPayload(CrmAttachment $l): ?string
    {
        $raw = (array) ($l->message?->raw ?? []);

        $jenis = (string) (data_get($raw, 'raw.type') ?? data_get($raw, 'message_type') ?? '');

        return data_get($raw, "raw.{$jenis}.filename")
            ?? data_get($raw, 'file_name')
            ?? data_get($raw, 'filename');
    }

    private function mimeNyata(string $abs): ?string
    {
        $f = new \finfo(FILEINFO_MIME_TYPE);

        return $f->file($abs) ?: null;
    }

    /**
     * Peta seperlunya. Yang tak dikenal dibiarkan tanpa ekstensi — menebak
     * ekstensi yang salah lebih menyesatkan daripada tidak menebak sama sekali.
     */
    private function ekstensiDariMime(?string $mime): string
    {
        return match (Str::before((string) $mime, ';')) {
            'application/pdf'  => 'pdf',
            'image/jpeg'       => 'jpg',
            'image/png'        => 'png',
            'image/webp'       => 'webp',
            'video/mp4'        => 'mp4',
            'audio/ogg'        => 'ogg',
            'audio/mpeg'       => 'mp3',
            'text/plain'       => 'txt',
            'application/zip'  => 'zip',
            default            => '',
        };
    }
}
