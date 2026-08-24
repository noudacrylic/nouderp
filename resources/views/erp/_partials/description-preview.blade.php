{{-- Pratinjau tampilan web untuk kolom deskripsi (produk & kategori).

     Deskripsi disimpan sebagai TEKS POLOS, bukan HTML — yang membuatnya rapi di
     web adalah konvensi penulisan, bukan tombol. Tanpa pratinjau, satu-satunya
     cara mengetahui hasilnya adalah menyimpan lalu membuka situsnya.

     ⚠️ Aturan di bawah kembar dengan components/ProductDescription.tsx di repo
     storefront (noudacrylic/web). Bila salah satu diubah, ubah keduanya —
     kalau tidak, yang terlihat di sini berbeda dengan yang terbit.

     Pemakaian:  @include('erp._partials.description-preview', ['target' => 'nama-id-textarea'])
--}}
@once
<style>
    .desc-preview { color: #334155; line-height: 1.65; }
    .desc-preview > * + * { margin-top: .5rem; }
    .desc-preview h3 { font-size: 1rem; font-weight: 700; color: #0f172a; margin-top: .85rem; }
    .desc-preview ul, .desc-preview ol { padding-left: 1.35rem; }
    .desc-preview ul { list-style: disc; }
    .desc-preview ol { list-style: decimal; }
    .desc-preview li { margin: .15rem 0; }
    .desc-preview strong { font-weight: 600; color: #0f172a; }
    .desc-preview:empty::before { content: 'Belum ada isi.'; color: #9ca3af; font-style: italic; }
</style>
<script>
(function () {
    const BULLET   = /^([•\-*+])\s+(.*)$/;
    const NUMBERED = /^\d+[.)]\s+(.*)$/;
    const HASH     = /^#{1,6}\s+(.*)$/;
    const KEYCAP   = /^[0-9#*]️?⃣/;

    // Lima karakter yang sama persis dengan yang di-escape React di sisi storefront,
    // supaya keluaran kedua parser bisa dibandingkan huruf per huruf.
    const esc = (s) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#x27;' }[c]));
    const inline = (s) => esc(s).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');

    // Baris diawali simbol/emoji = sub-judul. "•", "-", "*", "+" tidak sampai ke
    // sini karena sudah ditangkap BULLET lebih dulu.
    const isEmojiHeading = (line) => KEYCAP.test(line) || (line.codePointAt(0) || 0) >= 0x2190;

    window.renderDescriptionPreview = function (text) {
        const out = [];
        let items = [], ordered = false;

        const flush = () => {
            if (!items.length) return;
            const tag = ordered ? 'ol' : 'ul';
            out.push(`<${tag}>` + items.map((i) => `<li>${inline(i)}</li>`).join('') + `</${tag}>`);
            items = [];
        };
        const pushList = (content, isOrdered) => {
            if (items.length && ordered !== isOrdered) flush();
            ordered = isOrdered;
            items.push(content);
        };

        for (const raw of String(text || '').split(/\r?\n/)) {
            const line = raw.trim();
            if (!line) { flush(); continue; }

            const hash = HASH.exec(line);
            if (hash) { flush(); out.push(`<h3>${inline(hash[1])}</h3>`); continue; }

            const bullet = BULLET.exec(line);
            if (bullet) { pushList(bullet[2], false); continue; }

            const numbered = NUMBERED.exec(line);
            if (numbered) { pushList(numbered[1], true); continue; }

            if (isEmojiHeading(line)) { flush(); out.push(`<h3>${inline(line)}</h3>`); continue; }

            flush();
            out.push(`<p>${inline(line)}</p>`);
        }
        flush();
        return out.join('');
    };

    // Satu pemasang untuk semua kolom di halaman — juga menangkap kolom yang
    // baru muncul (tab yang belum pernah dibuka) lewat pemanggilan ulang.
    window.bindDescriptionPreview = function () {
        document.querySelectorAll('[data-desc-preview]:not([data-desc-bound])').forEach((box) => {
            const field = document.getElementById(box.dataset.descPreview);
            if (!field) return;
            box.setAttribute('data-desc-bound', '1');
            const paint = () => { box.innerHTML = window.renderDescriptionPreview(field.value); };
            field.addEventListener('input', paint);
            paint();
        });
    };
    document.addEventListener('DOMContentLoaded', window.bindDescriptionPreview);
})();
</script>
@endonce

<details class="mt-2 rounded border border-gray-200 bg-gray-50">
    <summary class="cursor-pointer select-none px-3 py-1.5 text-xs text-gray-600 hover:text-gray-900">
        Pratinjau tampilan web
    </summary>
    <div class="border-t border-gray-200 bg-white px-3 py-2.5">
        <div class="desc-preview text-sm" data-desc-preview="{{ $target }}"></div>
        <p class="mt-2.5 border-t border-gray-100 pt-2 text-[11px] leading-relaxed text-gray-400">
            Sub-judul: awali baris dengan emoji (<span class="font-mono">🎨 Panduan warna</span>) atau
            <span class="font-mono">## Panduan warna</span> &nbsp;·&nbsp;
            Butir: <span class="font-mono">•</span> atau <span class="font-mono">-</span> &nbsp;·&nbsp;
            Bernomor: <span class="font-mono">1.</span> &nbsp;·&nbsp;
            Tebal: <span class="font-mono">**tebal**</span>
        </p>
    </div>
</details>
