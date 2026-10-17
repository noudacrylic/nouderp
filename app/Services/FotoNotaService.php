<?php

namespace App\Services;

use App\Models\DocumentAttachment;
use App\Modules\Finance\Models\CashDisbursement;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Services\Store\ImageDownscaler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Foto nota: bukti kertas (nota belanja, invoice supplier, bukti transfer)
 * yang difoto lalu ditempel ke dokumen ERP.
 *
 * Fotonya dimaksudkan menjadi PENGGANTI nota kertas yang boleh dibuang, maka:
 *  - disimpan di disk privat (storage/app/private/nota/…), dibuka lewat route
 *    yang dijaga hak menu dokumennya — bukan URL publik;
 *  - nama berkas = id baris, tidak pernah nama dari klien;
 *  - dihapus pun hanya diarsipkan (soft delete), berkasnya tetap di disk;
 *  - ikut backup harian karena seluruh /var/www diarsipkan Borg.
 *
 * Foto ponsel 3-5 MB diperkecil ke sisi 1600 px (±200-400 KB) — teks nota tetap
 * terbaca, dan sepuluh tahun nota tidak menghabiskan disk server.
 *
 * KOTAK NOTA: foto dari PWA HP (/cs/nota) masuk tanpa dokumen (doc_type
 * kosong) — nota sering difoto di toko, sebelum fakturnya diinput. Di PC
 * fotonya ditempel ke dokumen lewat tombol "Ambil dari HP" di kartu Foto Nota.
 */
class FotoNotaService
{
    /** jenis => [kelas model, prefix nama route dokumen, label, kolom nomor, kunci menu]. */
    public const JENIS = [
        'purchase_invoice'  => [PurchaseInvoice::class,  'purchasing.invoices',             'Faktur Pembelian',    'invoice_number', 'purchasing.invoices'],
        'supplier_payment'  => [SupplierPayment::class,  'purchasing.payments',             'Pembayaran Supplier', 'payment_number', 'purchasing.payments'],
        'cash_disbursement' => [CashDisbursement::class, 'finance.cash-bank.disbursements', 'Pengeluaran',         'number',         'cash-bank.disbursements'],
    ];

    /** Boleh memakai foto nota (menu Nota di PWA, Kotak Nota)? = punya akses salah satu dokumennya. */
    public static function bolehNota(): bool
    {
        foreach (self::JENIS as $j) {
            if (user_can_access($j[4])) return true;
        }
        return false;
    }

    /** Foto di Kotak Nota yang belum ditempel, terbaru dulu. */
    public static function kotak()
    {
        return DocumentAttachment::whereNull('doc_type')->latest('id');
    }

    /** "Faktur Pembelian FAK/2026/10/0012" untuk foto yang sudah ditempel, atau null. */
    public static function labelDokumen(DocumentAttachment $att): ?string
    {
        if (!$att->doc_type || !isset(self::JENIS[$att->doc_type])) return null;
        [$kelas, , $label, $kolom] = self::JENIS[$att->doc_type];
        $nomor = $kelas::whereKey($att->doc_id)->value($kolom);
        return trim($label . ' ' . ($nomor ?? '#' . $att->doc_id));
    }

    public const MAKS_BERKAS = 10;

    public function __construct(private ImageDownscaler $downscaler) {}

    /** Aturan validasi untuk input `nota[]` — dipakai form dokumen & kartu foto nota. */
    public static function rules(): array
    {
        return [
            'nota'   => 'nullable|array|max:' . self::MAKS_BERKAS,
            'nota.*' => 'file|mimes:jpg,jpeg,png,webp,heic,heif,pdf|max:20480',
        ];
    }

    public static function messages(): array
    {
        return [
            'nota.max'      => 'Maksimal ' . self::MAKS_BERKAS . ' foto nota sekali unggah.',
            'nota.*.mimes'  => 'Foto nota harus berupa gambar (JPG/PNG/WEBP/HEIC) atau PDF.',
            'nota.*.max'    => 'Ukuran satu foto nota maksimal 20 MB.',
        ];
    }

    public static function modelUntuk(string $jenis): string
    {
        abort_unless(isset(self::JENIS[$jenis]), 404);
        return self::JENIS[$jenis][0];
    }

    public static function routePrefix(string $jenis): string
    {
        return self::JENIS[$jenis][1];
    }

    /** Simpan semua `nota[]` dari request (dipanggil setelah dokumen tersimpan). */
    public function simpanDariRequest(string $jenis, Model $doc, Request $request): int
    {
        $files = array_filter((array) $request->file('nota', []), fn ($f) => $f instanceof UploadedFile && $f->isValid());
        return $this->simpan($jenis, $doc, $files, $request->user()?->id);
    }

    /**
     * @param  UploadedFile[]  $files
     * @return int jumlah berkas yang berhasil disimpan
     */
    public function simpan(string $jenis, Model $doc, array $files, ?int $userId = null): int
    {
        self::modelUntuk($jenis);
        return $this->simpanBerkas($jenis, $doc->getKey(), $files, $userId, null);
    }

    /** Foto dari PWA HP → Kotak Nota (belum menempel ke dokumen). */
    public function simpanKeKotak(array $files, ?int $userId, ?string $catatan = null): int
    {
        $catatan = $catatan !== null ? mb_substr(trim($catatan), 0, 190) : null;
        return $this->simpanBerkas(null, null, $files, $userId, $catatan ?: null);
    }

    /**
     * Lampiran chat CRM (nota supplier yang dikirim lewat WA) → Kotak Nota, tanpa
     * unduh-lalu-unggah. Berkasnya DISALIN ke penyimpanan nota: masa simpan
     * lampiran chat berbeda (bisa disapu), sedangkan nota harus bertahan.
     *
     * @return string 'ok' | 'sudah' (sudah pernah dimasukkan) | 'tak_didukung' | 'gagal'
     */
    public function simpanDariChat(\App\Modules\CRM\Models\CrmAttachment $lampiran, ?int $userId, ?string $catatan): string
    {
        if (DocumentAttachment::where('crm_attachment_id', $lampiran->id)->exists()) {
            return 'sudah';
        }

        if (!self::lampiranChatBisaNota($lampiran)) {
            return 'tak_didukung';
        }

        $tmp = tempnam(sys_get_temp_dir(), 'nota');
        try {
            file_put_contents($tmp, Storage::disk($lampiran->disk)->get($lampiran->path));
            $nama = $lampiran->original_name ?: basename($lampiran->path);
            $file = new UploadedFile($tmp, $nama, $lampiran->mime, null, true);

            $catatan = $catatan !== null ? mb_substr(trim($catatan), 0, 190) : null;
            $n = $this->simpanBerkas(null, null, [$file], $userId, $catatan ?: null, ['crm_attachment_id' => $lampiran->id]);

            return $n ? 'ok' : 'gagal';
        } catch (\Throwable $e) {
            Log::warning('[foto-nota] gagal menyalin lampiran chat', ['lampiran' => $lampiran->id, 'error' => $e->getMessage()]);
            return 'gagal';
        } finally {
            @unlink($tmp);
        }
    }

    /** Lampiran chat yang bisa jadi nota: tersimpan di server kita, foto/PDF, ≤ 20 MB. */
    public static function lampiranChatBisaNota(\App\Modules\CRM\Models\CrmAttachment $lampiran): bool
    {
        $mime = (string) $lampiran->mime;

        return $lampiran->tersimpanAman()
            && !$lampiran->sudahDisapu()
            && (str_starts_with($mime, 'image/') || $mime === 'application/pdf')
            && (int) $lampiran->size_bytes <= 20480 * 1024;
    }

    /** Tempel foto dari Kotak Nota ke dokumen. */
    public function tempel(DocumentAttachment $att, string $jenis, Model $doc, ?int $userId = null): void
    {
        self::modelUntuk($jenis);
        if ($att->doc_type !== null) {
            throw new \DomainException('Foto ini sudah ditempel ke dokumen lain.');
        }
        $att->update([
            'doc_type'    => $jenis,
            'doc_id'      => $doc->getKey(),
            'ditempel_at' => now(),
            'ditempel_by' => $userId,
        ]);
    }

    private function simpanBerkas(?string $jenis, ?int $docId, array $files, ?int $userId, ?string $catatan, array $tambahan = []): int
    {
        $tersimpan = 0;

        foreach ($files as $file) {
            $att = null;
            try {
                $file = $this->perkecil($file);
                $mime = $file->getMimeType() ?: 'application/octet-stream';
                $ext  = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin');

                // Baris dulu → id dipakai sebagai nama berkas.
                $att = DocumentAttachment::create([
                    'doc_type'      => $jenis,
                    'doc_id'        => $docId,
                    'catatan'       => $catatan,
                    'disk'          => 'local',
                    'original_name' => mb_substr($file->getClientOriginalName() ?: ('nota.' . $ext), 0, 190),
                    'mime'          => $mime,
                    'size_bytes'    => (int) $file->getSize(),
                    'uploaded_by'   => $userId,
                ] + $tambahan);

                $dir  = 'nota/' . now()->format('Y/m');
                $path = Storage::disk('local')->putFileAs($dir, $file, $att->id . '.' . $ext);
                if (!$path) throw new \RuntimeException('Gagal menulis berkas ke disk.');

                $att->update(['path' => $path]);
                $tersimpan++;
            } catch (\Throwable $e) {
                // Satu foto gagal tidak boleh menggagalkan dokumen yang sudah tersimpan.
                Log::warning('[foto-nota] gagal menyimpan', ['jenis' => $jenis, 'doc' => $docId, 'error' => $e->getMessage()]);
                $att?->forceDelete();
            }
        }

        return $tersimpan;
    }

    /** Respons berkas (inline) — dipakai semua route penampil foto nota. */
    public static function respon(DocumentAttachment $att)
    {
        abort_unless($att->path && Storage::disk($att->disk)->exists($att->path), 404);
        $nama = $att->original_name ?: basename($att->path);
        return Storage::disk($att->disk)->response($att->path, $nama, [
            'Content-Type'  => $att->mime ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=86400',
        ], 'inline');
    }

    public function arsipkan(DocumentAttachment $att, ?int $userId = null): void
    {
        $att->update(['deleted_by' => $userId]);
        $att->delete();
    }

    private function perkecil(UploadedFile $file): UploadedFile
    {
        $mime = (string) $file->getMimeType();
        if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return $this->downscaler->process($file);
        }
        return $file; // PDF / HEIC disimpan apa adanya
    }
}
