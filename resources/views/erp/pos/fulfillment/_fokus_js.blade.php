{{-- Sorot satu kartu setelah lompat dari tab "Semua" (?fokus=NOMOR). --}}
@if(request('fokus'))
<script>
(function () {
    const target = @json((string) request('fokus'));
    const card = Array.from(document.querySelectorAll('[data-so-number]'))
        .find(el => el.dataset.soNumber === target);
    if (!card) return;

    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    // Cincin sorot sementara — cukup untuk menuntun mata, lalu memudar sendiri.
    card.classList.add('ring-4', 'ring-indigo-400', 'ring-offset-2');
    setTimeout(() => card.classList.remove('ring-4', 'ring-indigo-400', 'ring-offset-2'), 3500);
})();
</script>
@endif
