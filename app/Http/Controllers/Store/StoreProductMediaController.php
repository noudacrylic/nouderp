<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\StoreProduct;
use App\Models\StoreProductMedia;
use App\Services\Store\StoreMediaService;
use Illuminate\Http\Request;

class StoreProductMediaController extends Controller
{
    public function __construct(private StoreMediaService $media) {}

    /**
     * Upload satu/lebih foto.
     * group=gallery (default) → galeri produk; group=showcase → foto instansi pemesan.
     */
    public function storeImages(Request $request, $id)
    {
        $product = StoreProduct::findOrFail($id);

        $data = $request->validate([
            'group'    => ['nullable', 'in:gallery,showcase'],
            'images'   => ['required', 'array', 'min:1'],
            'images.*' => ['file', 'mimes:' . implode(',', config('store.image_mimes')),
                           'max:' . config('store.image_max_kb')],
        ]);

        $group = $data['group'] ?? 'gallery';

        $created = [];
        foreach ($request->file('images') as $file) {
            $created[] = $this->present($this->media->uploadImage($product, $file, $group));
        }

        return response()->json(['ok' => true, 'media' => $created]);
    }

    /** Upload satu video (file). */
    public function storeVideo(Request $request, $id)
    {
        $product = StoreProduct::findOrFail($id);

        $request->validate([
            'video' => ['required', 'file', 'mimes:' . implode(',', config('store.video_mimes')),
                        'max:' . config('store.video_max_kb')],
        ]);

        return response()->json([
            'ok' => true,
            'media' => $this->present($this->media->uploadVideo($product, $request->file('video'))),
        ]);
    }

    /** Tambah video YouTube (link). */
    public function storeYoutube(Request $request, $id)
    {
        $product = StoreProduct::findOrFail($id);

        $data = $request->validate([
            'url' => ['required', 'string', 'url', 'max:255'],
        ]);

        return response()->json([
            'ok' => true,
            'media' => $this->present($this->media->addYoutube($product, $data['url'])),
        ]);
    }

    public function reorder(Request $request, $id)
    {
        $product = StoreProduct::findOrFail($id);
        $data = $request->validate(['order' => ['required', 'array'], 'order.*' => ['integer']]);
        $this->media->reorder($product, $data['order']);
        return response()->json(['ok' => true]);
    }

    public function setPrimary(Request $request, $id, $mediaId)
    {
        $product = StoreProduct::findOrFail($id);
        $this->media->setPrimary($product, (int) $mediaId);
        return response()->json(['ok' => true]);
    }

    public function destroy($id, $mediaId)
    {
        $product = StoreProduct::findOrFail($id);
        $media = StoreProductMedia::where('store_product_id', $product->id)->findOrFail($mediaId);
        $this->media->delete($media);
        return response()->json(['ok' => true]);
    }

    /** Simpan alt text (kata kunci SEO) sebuah media. */
    public function updateAlt(Request $request, $id, $mediaId)
    {
        $product = StoreProduct::findOrFail($id);
        $media = StoreProductMedia::where('store_product_id', $product->id)->findOrFail($mediaId);
        $data = $request->validate(['alt_text' => 'nullable|string|max:255']);
        $media->update(['alt_text' => $data['alt_text'] ?? null]);
        return response()->json(['ok' => true]);
    }

    /** Simpan caption (nama instansi) sebuah foto showcase. */
    public function updateCaption(Request $request, $id, $mediaId)
    {
        $product = StoreProduct::findOrFail($id);
        $media = StoreProductMedia::where('store_product_id', $product->id)->findOrFail($mediaId);
        $data = $request->validate(['caption' => 'nullable|string|max:255']);
        $media->update(['caption' => $data['caption'] ?? null]);
        return response()->json(['ok' => true]);
    }

    private function present(StoreProductMedia $m): array
    {
        return [
            'id'         => $m->id,
            'group'      => $m->group,
            'kind'       => $m->kind,
            'source'     => $m->source,
            'url'        => $m->url,
            'alt_text'   => $m->alt_text,
            'caption'    => $m->caption,
            'is_primary' => (bool) $m->is_primary,
            'sort_order' => $m->sort_order,
        ];
    }
}
