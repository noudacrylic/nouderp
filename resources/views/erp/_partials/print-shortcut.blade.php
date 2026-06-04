{{--
    Pintasan Ctrl+P / Cmd+P → buka halaman print dokumen di tab baru.
    Pemakaian:
        @include('erp._partials.print-shortcut', ['printUrl' => route('sales.quotations.print', $quotation->id)])
--}}
@push('scripts')
<script>
(function () {
    const __printUrl = @json($printUrl);
    document.addEventListener('keydown', function (e) {
        if (!(e.ctrlKey || e.metaKey)) return;
        if (e.shiftKey || e.altKey) return;
        if ((e.key || '').toLowerCase() !== 'p') return;
        e.preventDefault();
        window.location.href = __printUrl;
    });
})();
</script>
@endpush
