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
 */
class FotoNotaService
{
    /** jenis => [kelas model, prefix nama route dokumen, label]. */
    public const JENIS = [
        'purchase_invoice'  => [PurchaseInvoice::class,  'purchasing.invoices',              'Faktur Pembelian'],
        'supplier_payment'  => [SupplierPayment::class,  'purchasing.payments',              'Pembayaran Supplier'],
        'cash_disbursement' => [CashDisbursement::class, 'finance.cash-bank.disbursements',  'Pengeluaran'],
    ];

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
                    'doc_id'        => $doc->getKey(),
                    'disk'          => 'local',
                    'original_name' => mb_substr($file->getClientOriginalName() ?: ('nota.' . $ext), 0, 190),
                    'mime'          => $mime,
                    'size_bytes'    => (int) $file->getSize(),
                    'uploaded_by'   => $userId,
                ]);

                $dir  = 'nota/' . now()->format('Y/m');
                $path = Storage::disk('local')->putFileAs($dir, $file, $att->id . '.' . $ext);
                if (!$path) throw new \RuntimeException('Gagal menulis berkas ke disk.');

                $att->update(['path' => $path]);
                $tersimpan++;
            } catch (\Throwable $e) {
                // Satu foto gagal tidak boleh menggagalkan dokumen yang sudah tersimpan.
                Log::warning('[foto-nota] gagal menyimpan', ['jenis' => $jenis, 'doc' => $doc->getKey(), 'error' => $e->getMessage()]);
                $att?->forceDelete();
            }
        }

        return $tersimpan;
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
