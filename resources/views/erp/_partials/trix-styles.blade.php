{{-- Tampilan ISI editor Trix — dipakai editor Blog & Tutorial.

     Tanpa berkas ini tombol Heading, Bullet, Kutipan, dan Kode tampak MATI:
     diklik, HTML-nya berubah, tapi layarnya tidak. Sebabnya bukan Trix, tapi
     dua reset CSS di layouts/erp: Tailwind Play CDN meratakan h1-h6 jadi
     `font-size: inherit; font-weight: inherit` dan membuang `list-style`
     seluruh ul/ol. Terukur di tumpukan CSS ERP: <h1> = 16px/400, persis sama
     dengan <p>; <li> = list-style none, padding-left 0. Trix sendiri memang
     tidak menyertakan CSS untuk isi konten — hanya untuk toolbar & lampiran,
     dan menyerahkan penataan isinya ke aplikasi.

     Angkanya sengaja disamakan dengan `.article-body` di storefront
     (app/globals.css) supaya yang terlihat saat menulis = yang terbit.
     Bila salah satunya diubah, ubah keduanya. --}}
<link rel="stylesheet" href="https://unpkg.com/trix@2.1.15/dist/trix.css">
<style>
    trix-editor { min-height: 320px; background: #fff; color: #334155; line-height: 1.75; }
    trix-editor:empty:not(:focus)::before { color: #9ca3af; }
    trix-toolbar .trix-button-group { border-color: #e5e7eb; }

    /* h1 ikut ditata demi naskah lama; tombol Heading kini menerbitkan h2. */
    trix-editor h1,
    trix-editor h2 { font-size: 1.5rem; font-weight: 700; margin: 2rem 0 .75rem; color: #0f172a; }
    trix-editor > *:first-child { margin-top: 0; }
    trix-editor ul,
    trix-editor ol { margin: 0 0 1rem; padding-left: 1.5rem; }
    trix-editor ul { list-style: disc; }
    trix-editor ol { list-style: decimal; }
    trix-editor li { margin: .25rem 0; }
    trix-editor li ul { list-style: circle; margin-bottom: 0; }
    trix-editor blockquote { border-left: 3px solid #cbd5e1; padding-left: 1rem; margin: 1rem 0; color: #64748b; font-style: italic; }
    trix-editor pre { margin: 1rem 0; padding: .75rem 1rem; border-radius: .375rem; background: #f1f5f9; font-size: .875rem; overflow-x: auto; }
    trix-editor a { color: #1d4ed8; text-decoration: underline; }
    trix-editor strong { font-weight: 700; color: #0f172a; }
    trix-editor img { max-width: 100%; height: auto; margin: 1rem 0; border-radius: .5rem; }
</style>
