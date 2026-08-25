<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\StoreArticle;
use App\Models\StoreArticleCategory;
use App\Services\Store\ArticleMediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreArticleController extends Controller
{
    public function __construct(private ArticleMediaService $media) {}

    public function index(Request $request)
    {
        $q      = trim((string) $request->get('q'));
        $status = $request->get('status');

        $articles = StoreArticle::query()
            ->with('category')
            ->when($q !== '', fn($qq) => $qq->where(fn($w) => $w->where('title', 'like', "%$q%")->orWhere('slug', 'like', "%$q%")))
            ->when($status !== null && $status !== '', fn($qq) => $qq->where('status', $status))
            ->orderByDesc('id')
            ->paginate(per_page_size())
            ->withQueryString();

        return view('erp.store.articles.index', compact('articles', 'q', 'status'));
    }

    /** Create singkat: judul saja → draft → redirect ke edit (editor & cover butuh id). */
    public function create()
    {
        return view('erp.store.articles.create');
    }

    public function store(Request $request)
    {
        $request->validate(['title' => ['required', 'string', 'max:255']]);

        $article = StoreArticle::create([
            'title'  => $request->title,
            'slug'   => $this->uniqueSlug($request->title),
            'status' => 'draft',
            'author' => auth()->user()?->name,
        ]);

        return redirect()->route('store.articles.edit', $article->id)
            ->with('success', 'Draft artikel dibuat. Lengkapi lalu terbitkan.');
    }

    public function edit($id)
    {
        $article = StoreArticle::findOrFail($id);
        $categories = StoreArticleCategory::orderBy('name')->get();
        return view('erp.store.articles.edit', compact('article', 'categories'));
    }

    public function update(Request $request, $id)
    {
        $article = StoreArticle::findOrFail($id);
        $publish = $request->input('action') === 'publish';

        $data = $request->validate([
            'title'                     => ['required', 'string', 'max:255'],
            'slug'                      => ['nullable', 'string', 'max:255', 'alpha_dash',
                                            Rule::unique('store_articles', 'slug')->ignore($article->id)],
            'store_article_category_id' => ['nullable', 'integer', 'exists:store_article_categories,id'],
            'excerpt'                   => ['nullable', 'string', 'max:500'],
            'content'                   => [$publish ? 'required' : 'nullable', 'string'],
            'author'                    => ['nullable', 'string', 'max:255'],
            'published_at'              => ['nullable', 'date'],
            'is_featured'               => ['nullable', 'boolean'],
            'sort_order'                => ['nullable', 'integer'],
            'meta_title'                => ['nullable', 'string', 'max:255'],
            'meta_description'          => ['nullable', 'string', 'max:255'],
            'cover'                     => ['nullable', 'image', 'max:5120'],
        ]);

        // Lihat StoreTutorialController: naskah yang masih memuat lampiran
        // `blob:` berarti unggahannya belum selesai — menyimpannya sama dengan
        // membuang gambarnya.
        if (trix_has_pending_upload($request->input('content'))) {
            return back()->withInput()->withErrors([
                'content' => 'Ada gambar yang belum selesai diunggah. Tunggu sampai keterangan "Mengunggah…" di bawah editor hilang, lalu simpan lagi.',
            ]);
        }

        $data['slug'] = !empty($data['slug']) ? Str::slug($data['slug']) : $this->uniqueSlug($data['title'], $article->id);
        $data['is_featured'] = $request->boolean('is_featured');
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['status'] = $publish ? 'published' : 'draft';
        // Hanya bila kolomnya memang dikirim — menyetelnya tanpa syarat akan
        // MENIMPA naskah tersimpan dengan string kosong saat menyimpan draf.
        if (isset($data['content'])) {
            $data['content'] = trix_publish($data['content']);
        }

        // Terbit tanpa jadwal → tayang sekarang. Jadwal masa depan tetap dihormati (scope time-gated).
        if ($publish && empty($data['published_at'])) {
            $data['published_at'] = now();
        }

        // Cover baru → upload, hapus cover lama.
        if ($request->hasFile('cover')) {
            $up = $this->media->upload($request->file('cover'), $data['slug']);
            $this->media->delete($article->cover_key);
            $data['cover_url'] = $up['url'];
            $data['cover_key'] = $up['key'];
        }

        $article->update($data);

        return redirect(list_url('store.articles.index'))
            ->with('success', $publish ? 'Artikel diterbitkan.' : 'Artikel disimpan sebagai draft.');
    }

    public function destroy($id)
    {
        $article = StoreArticle::findOrFail($id);
        $this->media->delete($article->cover_key);
        $article->delete();
        return back()->with('success', 'Artikel dihapus.');
    }

    /** Upload gambar inline dari editor (Trix) → JSON {url}. */
    public function uploadImage(Request $request, $id)
    {
        $request->validate(['file' => ['required', 'image', 'max:' . editor_image_max_kb()]]);
        $article = StoreArticle::findOrFail($id);
        $up = $this->media->upload($request->file('file'), $article->slug);
        return response()->json(['url' => $up['url']]);
    }

    private function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'artikel';
        $slug = $base;
        $i = 2;
        while (StoreArticle::where('slug', $slug)
                ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
