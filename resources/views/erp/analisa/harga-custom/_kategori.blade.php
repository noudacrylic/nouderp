{{-- Pil kategori custom. Disembunyikan selama baru satu kategori yang punya resep. --}}
@if($categories->count() > 1)
    <div class="flex flex-wrap gap-2 mb-4">
        @foreach($categories as $c)
            <a href="{{ route($routeName, ['kategori' => $c->code]) }}"
               class="px-3 py-1.5 rounded-full text-xs font-bold border
                      {{ $c->id === $category->id ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' }}">
                {{ $c->name }}
            </a>
        @endforeach
    </div>
@endif
