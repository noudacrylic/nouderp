<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\StoreHomepageSetting;
use App\Services\Store\HomepageMediaService;
use Illuminate\Http\Request;

/**
 * Store → Beranda. Menyunting isi beranda etalase tanpa deploy ulang.
 * Etalase membacanya lewat GET /api/storefront/homepage.
 */
class StoreHomepageController extends Controller
{
    public function __construct(private HomepageMediaService $media) {}

    public function edit()
    {
        $homepage = StoreHomepageSetting::singleton();

        return view('erp.store.homepage.edit', compact('homepage'));
    }

    public function update(Request $request)
    {
        $homepage = StoreHomepageSetting::singleton();

        $data = $request->validate([
            'meta_title'           => ['nullable', 'string', 'max:255'],
            'meta_description'     => ['nullable', 'string', 'max:300'],

            'hero_eyebrow'         => ['nullable', 'string', 'max:255'],
            'hero_heading'         => ['nullable', 'string', 'max:255'],
            'hero_subheading'      => ['nullable', 'string', 'max:1000'],
            'hero_primary_label'   => ['nullable', 'string', 'max:60'],
            'hero_primary_url'     => ['nullable', 'string', 'max:255'],
            'hero_secondary_label' => ['nullable', 'string', 'max:60'],
            'hero_secondary_wa'    => ['nullable', 'string', 'max:1000'],
            'hero_image_alt'       => ['nullable', 'string', 'max:255'],
            'hero_badges'          => ['nullable', 'array', 'max:4'],
            'hero_badges.*.icon'   => ['nullable', 'string', 'max:20'],
            'hero_badges.*.label'  => ['nullable', 'string', 'max:60'],

            'advantages_heading'   => ['nullable', 'string', 'max:255'],
            'advantages'           => ['nullable', 'array', 'max:6'],
            'advantages.*.icon'    => ['nullable', 'string', 'max:20'],
            'advantages.*.title'   => ['nullable', 'string', 'max:120'],
            'advantages.*.text'    => ['nullable', 'string', 'max:300'],

            'segments_heading'     => ['nullable', 'string', 'max:255'],
            'segments_subheading'  => ['nullable', 'string', 'max:255'],
            'segments'             => ['nullable', 'array', 'max:6'],
            'segments.*.icon'      => ['nullable', 'string', 'max:20'],
            'segments.*.title'     => ['nullable', 'string', 'max:120'],
            'segments.*.text'      => ['nullable', 'string', 'max:400'],
            'segments.*.url'       => ['nullable', 'string', 'max:255'],
            'segments.*.wa'        => ['nullable', 'string', 'max:500'],

            'categories_heading'   => ['nullable', 'string', 'max:255'],

            'featured_heading'     => ['nullable', 'string', 'max:255'],
            'featured_limit'       => ['nullable', 'integer', 'min:2', 'max:24'],

            'savings_heading'      => ['nullable', 'string', 'max:255'],
            'savings_price_title'  => ['nullable', 'string', 'max:255'],
            'savings_price_text'   => ['nullable', 'string', 'max:1000'],
            'savings_ship_title'   => ['nullable', 'string', 'max:255'],
            'savings_ship_text'    => ['nullable', 'string', 'max:1000'],
            'savings_link_label'   => ['nullable', 'string', 'max:120'],

            'spotlights'             => ['nullable', 'array', 'max:6'],
            'spotlights.*.slug'      => ['nullable', 'string', 'max:255'],
            'spotlights.*.eyebrow'   => ['nullable', 'string', 'max:255'],
            'spotlights.*.heading'   => ['nullable', 'string', 'max:255'],
            'spotlights.*.body'      => ['nullable', 'string', 'max:600'],
            'spotlights.*.bullets'   => ['nullable', 'string', 'max:1000'],
            'spotlights.*.cta_label' => ['nullable', 'string', 'max:60'],

            'gallery_heading'      => ['nullable', 'string', 'max:255'],
            'gallery_note'         => ['nullable', 'string', 'max:255'],
            'gallery_link_label'   => ['nullable', 'string', 'max:60'],
            'gallery_url'          => ['nullable', 'string', 'max:255'],
            'gallery_limit'        => ['nullable', 'integer', 'min:3', 'max:16'],

            'institution_heading'   => ['nullable', 'string', 'max:255'],
            'institution_body'      => ['nullable', 'string', 'max:1500'],
            'institution_bullets'   => ['nullable', 'array', 'max:8'],
            'institution_bullets.*' => ['nullable', 'string', 'max:200'],
            'institution_cta_label' => ['nullable', 'string', 'max:60'],
            'institution_cta_wa'    => ['nullable', 'string', 'max:1000'],

            'custom_heading'       => ['nullable', 'string', 'max:255'],
            'custom_body'          => ['nullable', 'string', 'max:1500'],
            'custom_cta_label'     => ['nullable', 'string', 'max:60'],
            'custom_cta_wa'        => ['nullable', 'string', 'max:1000'],

            'workshop_heading'     => ['nullable', 'string', 'max:255'],
            'workshop_body'        => ['nullable', 'string', 'max:1000'],

            'trust_items'          => ['nullable', 'array', 'max:6'],
            'trust_items.*.icon'   => ['nullable', 'string', 'max:20'],
            'trust_items.*.title'  => ['nullable', 'string', 'max:60'],
            'trust_items.*.text'   => ['nullable', 'string', 'max:80'],

            'faq_heading'          => ['nullable', 'string', 'max:255'],
            'faqs'                 => ['nullable', 'array', 'max:15'],
            'faqs.*.q'             => ['nullable', 'string', 'max:255'],
            'faqs.*.a'             => ['nullable', 'string', 'max:1500'],

            'hero_image'           => ['nullable', 'image', 'max:' . config('store.image_max_kb', 5120)],
            'og_image'             => ['nullable', 'image', 'max:' . config('store.image_max_kb', 5120)],
        ]);

        // Saklar tampil: checkbox yang tidak dicentang tidak dikirim browser sama sekali,
        // jadi harus dibaca eksplisit — bukan diambil dari $data.
        foreach ([
            'hero_image_blend',
            'show_segments', 'show_advantages', 'show_categories', 'show_savings',
            'show_spotlight', 'show_gallery', 'show_institution', 'show_custom',
            'show_workshop', 'show_map', 'show_trust', 'show_faq',
        ] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }

        // Baris repeater yang seluruh kolomnya dikosongkan = dihapus, bukan disimpan
        // sebagai kartu kosong yang lalu tampil sebagai lubang di beranda.
        $data['hero_badges']         = $this->compactRows($data['hero_badges'] ?? [], ['label']);
        $data['advantages']          = $this->compactRows($data['advantages'] ?? [], ['title', 'text']);
        $data['segments']            = $this->compactRows($data['segments'] ?? [], ['title', 'text', 'url', 'wa']);
        $data['trust_items']         = $this->compactRows($data['trust_items'] ?? [], ['title', 'text']);
        $data['spotlights']          = $this->compactRows($data['spotlights'] ?? [], ['slug']);
        $data['faqs']                = $this->compactRows($data['faqs'] ?? [], ['q', 'a']);

        $data['institution_bullets'] = array_values(array_filter(
            array_map('trim', $data['institution_bullets'] ?? []),
            fn ($b) => $b !== ''
        ));

        // Gambar: unggah baru menggantikan yang lama (berkas lama dihapus), atau
        // tombol hapus mengosongkan tanpa mengunggah apa pun.
        foreach ([
            'hero_image'        => ['hero_image_url',        'hero_image_key',        'beranda-hero'],
            'og_image'          => ['og_image_url',          'og_image_key',          'beranda-bagikan'],
        ] as $field => [$urlCol, $keyCol, $hint]) {
            if ($request->hasFile($field)) {
                $up = $this->media->upload($request->file($field), $hint);
                $this->media->delete($homepage->{$keyCol});
                $data[$urlCol] = $up['url'];
                $data[$keyCol] = $up['key'];
            } elseif ($request->boolean("remove_{$field}")) {
                $this->media->delete($homepage->{$keyCol});
                $data[$urlCol] = null;
                $data[$keyCol] = null;
            }
        }

        unset($data['hero_image'], $data['og_image']);

        $homepage->update($data);

        return back()->with(
            'success',
            'Beranda disimpan. Perubahan tampil di website dalam beberapa menit (etalase menyegarkan isinya secara berkala).'
        );
    }

    /** Kembalikan isi beranda ke teks bawaan — gambar yang sudah diunggah dipertahankan. */
    public function reset()
    {
        $homepage = StoreHomepageSetting::singleton();
        $homepage->update(StoreHomepageSetting::defaults());

        return back()->with('success', 'Isi beranda dikembalikan ke teks bawaan.');
    }

    /**
     * Buang baris repeater yang semua kolom pentingnya kosong, lalu susun ulang indeksnya
     * (JSON dengan indeks berlubang akan terbaca sebagai objek, bukan array, di etalase).
     */
    private function compactRows(array $rows, array $required): array
    {
        return array_values(array_filter(
            array_map(fn ($r) => array_map(fn ($v) => is_string($v) ? trim($v) : $v, $r), $rows),
            fn ($r) => collect($required)->contains(fn ($k) => filled($r[$k] ?? null))
        ));
    }
}
