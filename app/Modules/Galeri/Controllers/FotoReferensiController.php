<?php

namespace App\Modules\Galeri\Controllers;

use App\Modules\Galeri\Models\GaleriFoto;
use App\Modules\Galeri\Services\FotoProdukService;
use App\Modules\Galeri\Services\GaleriException;
use App\Modules\Galeri\Services\GaleriService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Tab Foto di panel Produk chat CRM: referensi foto produk custom yang tertaut pesanan. Dijaga
 * akses Inbox (bukan izin crm.galeri, CS belum tentu memegangnya), dan hanya melayani berkas
 * yang tertaut pesanan, bukan seluruh folder Galeri.
 */
class FotoReferensiController extends Controller
{
    public function __construct(private GaleriService $galeri, private FotoProdukService $foto) {}

    public function cari(Request $request)
    {
        return response()->json(['foto' => array_map(fn ($f) => $f + [
            'thumb' => route('crm.inbox.foto.thumb', $f['id']),
            'url'   => route('crm.inbox.foto.berkas', $f['id']),
        ], $this->foto->cari(trim((string) $request->query('q'))))]);
    }

    public function thumb(GaleriFoto $foto)
    {
        try {
            $thumb = $this->galeri->thumbnail($foto->path);
        } catch (GaleriException) {
            abort(404);
        }
        abort_unless($thumb, 404);

        return response()->file($thumb, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=2592000']);
    }

    public function berkas(GaleriFoto $foto)
    {
        try {
            $abs = $this->galeri->berkas($foto->path);
        } catch (GaleriException) {
            abort(404);
        }

        return response()->file($abs, ['Cache-Control' => 'private, max-age=86400']);
    }
}
