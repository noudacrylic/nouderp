<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\StoreProduct;
use App\Models\StoreTutorial;
use App\Services\Store\TutorialMediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreTutorialController extends Controller
{
    public function __construct(private TutorialMediaService $media) {}

    public function index(Request $request)
    {
        $q      = trim((string) $request->get('q'));
        $status = $request->get('status');

        $tutorials = StoreTutorial::query()
            ->withCount('products')
            ->when($q !== '', fn($qq) => $qq->where(fn($w) => $w
                ->where('title', 'like', "%$q%")
                ->orWhere('code', 'like', "%$q%")
                ->orWhere('slug', 'like', "%$q%")))
            ->when($status !== null && $status !== '', fn($qq) => $qq->where('status', $status))
            ->orderBy('sort_order')
            ->orderBy('code')
            ->paginate(per_page_size())
            ->withQueryString();

        return view('erp.store.tutorials.index', compact('tutorials', 'q', 'status'));
    }

    /** Create singkat: kode + judul → draft → lanjut ke edit (editor butuh id). */
    public function create()
    {
        return view('erp.store.tutorials.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code'  => ['required', 'string', 'max:16', 'alpha_dash', 'unique:store_tutorials,code'],
            'title' => ['required', 'string', 'max:255'],
        ], [], ['code' => 'kode']);

        $tutorial = StoreTutorial::create([
            'code'   => strtolower($data['code']),
            'title'  => $data['title'],
            'slug'   => StoreTutorial::uniqueSlug($data['title']),
            'status' => 'draft',
        ]);

        return redirect()->route('store.tutorials.edit', $tutorial->id)
            ->with('success', 'Draft tutorial dibuat. Lengkapi lalu terbitkan.');
    }

    public function edit($id)
    {
        $tutorial = StoreTutorial::with('products')->findOrFail($id);

        $products = StoreProduct::query()
            ->select('id', 'name', 'store_category_id')
            ->with('category:id,name')
            ->orderBy('name')
            ->get();

        return view('erp.store.tutorials.edit', compact('tutorial', 'products'));
    }

    public function update(Request $request, $id)
    {
        $tutorial = StoreTutorial::findOrFail($id);
        $publish  = $request->input('action') === 'publish';

        $data = $request->validate([
            'code'             => ['required', 'string', 'max:16', 'alpha_dash',
                                   Rule::unique('store_tutorials', 'code')->ignore($tutorial->id)],
            'title'            => ['required', 'string', 'max:255'],
            'slug'             => ['nullable', 'string', 'max:255', 'alpha_dash',
                                   Rule::unique('store_tutorials', 'slug')->ignore($tutorial->id)],
            'youtube'          => ['nullable', 'string', 'max:255'],
            'description'      => ['nullable', 'string', 'max:1000'],
            'content'          => ['nullable', 'string'],
            'sort_order'       => ['nullable', 'integer'],
            'meta_title'       => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:255'],
            'products'         => ['nullable', 'array'],
            'products.*'       => ['integer', 'exists:store_products,id'],
        ], [], ['code' => 'kode', 'youtube' => 'video YouTube']);

        $youtubeId = StoreTutorial::extractYoutubeId($request->input('youtube'));

        // Tautan YouTube ditempel apa adanya oleh admin; kalau tak terbaca, bilang
        // di kolomnya — jangan diam-diam menyimpan kosong lalu halaman tayang
        // tanpa video.
        if (filled($request->input('youtube')) && !$youtubeId) {
            return back()->withInput()
                ->withErrors(['youtube' => 'Tautan YouTube tidak dikenali. Tempel URL video atau ID-nya.']);
        }

        // Terbit tanpa video = halaman kosong yang di-scan orang sambil memegang
        // barang. Tahan di sini, bukan nanti di storefront.
        if ($publish && !$youtubeId) {
            return back()->withInput()
                ->withErrors(['youtube' => 'Isi video dulu sebelum diterbitkan.']);
        }

        $tutorial->update([
            'code'             => strtolower($data['code']),
            'title'            => $data['title'],
            'slug'             => !empty($data['slug'])
                                    ? Str::slug($data['slug'])
                                    : StoreTutorial::uniqueSlug($data['title'], $tutorial->id),
            'youtube_id'       => $youtubeId,
            'description'      => $data['description'] ?? null,
            'content'          => $data['content'] ?? null,
            'sort_order'       => $data['sort_order'] ?? 0,
            'meta_title'       => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'status'           => $publish ? 'published' : 'draft',
        ]);

        $tutorial->products()->sync($this->pivotPayload($data['products'] ?? []));

        return redirect(list_url('store.tutorials.index'))
            ->with('success', $publish ? 'Tutorial diterbitkan.' : 'Tutorial disimpan sebagai draft.');
    }

    public function destroy($id)
    {
        StoreTutorial::findOrFail($id)->delete();
        return back()->with('success', 'Tutorial dihapus.');
    }

    /** Upload gambar inline dari editor (Trix) → JSON {url}. */
    public function uploadImage(Request $request, $id)
    {
        $request->validate(['file' => ['required', 'image', 'max:' . config('store.image_max_kb', 5120)]]);
        $tutorial = StoreTutorial::findOrFail($id);
        $up = $this->media->upload($request->file('file'), $tutorial->slug);
        return response()->json(['url' => $up['url']]);
    }

    /** Halaman QR siap cetak — QR-nya dibangkitkan di browser (lihat view). */
    public function qr($id)
    {
        $tutorial = StoreTutorial::findOrFail($id);
        return view('erp.store.tutorials.qr', compact('tutorial'));
    }

    /** Urutan produk terkait mengikuti urutan pilihan admin di formulir. */
    private function pivotPayload(array $ids): array
    {
        $out = [];
        foreach (array_values($ids) as $i => $id) {
            $out[$id] = ['sort_order' => $i];
        }
        return $out;
    }
}
