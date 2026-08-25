{{-- Unggah gambar inline editor Trix — dipakai editor Blog & Tutorial.

     Dipanggil dengan:
       @include('erp._partials.trix-upload', [
           'trixUploadUrl' => route('...image', $x->id),
           'trixFormId'    => 'namaFormulir',
       ])

     Versi pertama helper ini menembakkan `fetch` lalu melupakannya. Akibatnya
     dua kehilangan yang sama-sama SUNYI — persis keluhan "gambarnya kadang
     hilang setelah disimpan":

       1. Selama unggahan masih jalan, lampiran di editor masih menunjuk
          `blob:` — alamat sementara milik tab itu saja. Menyimpan pada detik
          itu berarti menyimpan alamat yang mati begitu halaman ditutup.
          Delapan foto ponsel sekaligus butuh belasan detik; admin yang
          langsung menekan Terbitkan tidak diberi tanda apa pun.

       2. Unggahan gagal (paling sering: foto ponsel lebih besar dari batas)
          membuang lampirannya diam-diam lewat `attachment.remove()`, dengan
          satu `alert()` yang mudah terlewat di tengah unggahan lain.

     Yang dilakukan sekarang: hitung unggahan yang sedang jalan, matikan tombol
     simpan selama masih ada, tahan pengiriman formulir sebagai jaring terakhir,
     dan tampilkan alasan gagal apa adanya dari server (bukan "Gagal mengunggah
     gambar." yang tidak menjelaskan apa-apa).

     Batas ukuran diperiksa DI SINI juga, sebelum berkasnya masuk editor —
     supaya foto kebesaran ditolak sebelum admin melihatnya menempel lalu
     lenyap lagi. Angkanya satu sumber dengan validasi server
     (helper editor_image_max_kb(), yang juga menghormati batas PHP). --}}
@php
    $trixFormId  = $trixFormId ?? null;
    $trixMaxKb   = editor_image_max_kb();
@endphp
<script>
    (function () {
        const csrf      = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const uploadUrl = @json($trixUploadUrl);
        const maxKb     = {{ $trixMaxKb }};
        const form      = document.getElementById(@json($trixFormId));
        const editor    = document.querySelector('trix-editor');
        if (!editor) return;

        // Baris status ditempel sendiri di bawah editor: partial ini ikut tumpukan
        // <script>, jadi tak ada tempat menaruhnya lewat markup halaman.
        const status = document.createElement('p');
        status.className = 'text-xs mt-2';
        status.hidden = true;
        editor.insertAdjacentElement('afterend', status);

        const tombolSimpan = () => form ? form.querySelectorAll('button[name="action"]') : [];
        let jalan = 0;

        function kabari(pesan, jenis) {
            status.hidden = !pesan;
            status.textContent = pesan || '';
            status.className = 'text-xs mt-2 ' + (jenis === 'galat' ? 'text-red-600 font-semibold' : 'text-amber-600');
        }

        function segarkan() {
            tombolSimpan().forEach((b) => {
                b.disabled = jalan > 0;
                b.classList.toggle('opacity-50', jalan > 0);
                b.classList.toggle('cursor-not-allowed', jalan > 0);
            });
            if (jalan > 0) {
                kabari(jalan === 1 ? 'Mengunggah 1 gambar… tunggu sampai selesai sebelum menyimpan.'
                                   : `Mengunggah ${jalan} gambar… tunggu sampai selesai sebelum menyimpan.`);
            } else if (status.dataset.galat !== '1') {
                kabari('');
            }
        }

        const mb = (bytes) => (bytes / 1048576).toFixed(1).replace('.', ',');

        // Tolak berkas kebesaran SEBELUM masuk editor — kalau menunggu jawaban
        // server, admin sempat melihat gambarnya menempel lalu hilang lagi.
        addEventListener('trix-file-accept', function (event) {
            const berkas = event.file;
            if (berkas && berkas.size > maxKb * 1024) {
                event.preventDefault();
                status.dataset.galat = '1';
                kabari(`"${berkas.name}" berukuran ${mb(berkas.size)} MB — melebihi batas ${Math.round(maxKb / 1024)} MB. Perkecil dulu gambarnya, lalu sisipkan lagi.`, 'galat');
            }
        });

        addEventListener('trix-attachment-add', function (event) {
            const attachment = event.attachment;
            if (!attachment.file) return;   // lampiran lama dari naskah tersimpan

            status.dataset.galat = '0';
            jalan++;
            segarkan();

            const body = new FormData();
            body.append('file', attachment.file);

            fetch(uploadUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: body,
            })
                .then((r) => r.ok
                    ? r.json()
                    : r.json().catch(() => ({})).then((j) => Promise.reject(
                        j.message || j.errors?.file?.[0] || `Server menolak berkas (kode ${r.status}).`)))
                .then((data) => attachment.setAttributes({ url: data.url, href: data.url }))
                .catch((err) => {
                    attachment.remove();
                    status.dataset.galat = '1';
                    kabari('Gambar gagal diunggah: ' + (typeof err === 'string' ? err : 'sambungan terputus') + ' — sisipkan ulang setelah diperbaiki.', 'galat');
                })
                .finally(() => { jalan--; segarkan(); });
        });

        // Jaring terakhir: tombolnya sudah dimatikan, tapi Enter di dalam kolom
        // teks tetap bisa mengirim formulir.
        form?.addEventListener('submit', function (e) {
            if (jalan > 0) {
                e.preventDefault();
                status.dataset.galat = '1';
                kabari('Masih ada gambar yang sedang diunggah. Tunggu sebentar — menyimpan sekarang membuat gambarnya hilang.', 'galat');
                status.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        });
    })();
</script>
