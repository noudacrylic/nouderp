<script>
// Edit Catatan Penjual inline (event delegation untuk semua kartu di halaman ini).
(function () {
    if (window._sellerNotesWired) return; // jaga-jaga bila di-include lebih dari sekali
    window._sellerNotesWired = true;
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    document.addEventListener('click', function (e) {
        const editBtn = e.target.closest('.js-seller-edit');
        if (editBtn) {
            const box = editBtn.closest('.js-seller');
            box.querySelector('.js-seller-form').classList.remove('hidden');
            editBtn.classList.add('hidden');
            box.querySelector('.js-seller-input').focus();
            return;
        }

        const cancelBtn = e.target.closest('.js-seller-cancel');
        if (cancelBtn) {
            const box = cancelBtn.closest('.js-seller');
            box.querySelector('.js-seller-form').classList.add('hidden');
            box.querySelector('.js-seller-edit').classList.remove('hidden');
            return;
        }

        const saveBtn = e.target.closest('.js-seller-save');
        if (saveBtn) {
            const box = saveBtn.closest('.js-seller');
            const soId = box.getAttribute('data-so');
            const val = box.querySelector('.js-seller-input').value;
            saveBtn.disabled = true; saveBtn.textContent = 'Menyimpan…';
            fetch(`/erp/pos/fulfillment/so/${soId}/seller-notes`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                body: JSON.stringify({ seller_notes: val }),
            })
            .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
            .then(({ ok, data }) => {
                if (!ok) throw new Error(data.message || 'Gagal menyimpan.');
                box.querySelector('.js-seller-text').textContent = data.seller_notes || '—';
                box.querySelector('.js-seller-form').classList.add('hidden');
                box.querySelector('.js-seller-edit').classList.remove('hidden');
            })
            .catch(err => alert(err.message))
            .finally(() => { saveBtn.disabled = false; saveBtn.textContent = 'Simpan'; });
        }
    });
})();
</script>
