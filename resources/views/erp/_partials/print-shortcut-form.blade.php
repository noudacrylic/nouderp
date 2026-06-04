{{--
    Ctrl+P / Cmd+P di halaman create/edit dokumen yang menggunakan
    `<x-transaction-form>` (Sales Quotation, Sales Order, dst).

    Pola: simulasikan klik tombol "Print" yang sudah ada di transaction-form
    (button[name="_after_save"][value="print"]) — server akan menyimpan
    dokumen lebih dulu, lalu redirect ke halaman /print.

    Pemakaian: cukup @include partial ini di view create/edit. Tidak butuh
    parameter — selektor tombol sudah baku.
--}}
@push('scripts')
<script>
(function () {
    document.addEventListener('keydown', function (e) {
        if (!(e.ctrlKey || e.metaKey)) return;
        if (e.shiftKey || e.altKey) return;
        if ((e.key || '').toLowerCase() !== 'p') return;

        const btn = document.querySelector('button[name="_after_save"][value="print"]');
        if (!btn) return;

        e.preventDefault();
        btn.click();
    });
})();
</script>
@endpush
