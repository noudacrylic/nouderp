{{-- Opening text + Payment terms + Lampiran Gambar (modal-based add & edit) --}}
@php
    $existingAttachments = $quotation?->attachments ?? collect();

    // Saat create (belum ada $quotation), pakai teks terakhir dari quotation sebelumnya
    // sebagai default. Fallback ke BusinessProfile bila belum pernah dipakai.
    $lastQuot = $quotation ? null : \App\Models\SalesQuotation::orderByDesc('id')->first();

    $openingDefault = $lastQuot?->opening_text ?: ($profile->renderedOpeningText() ?? '');
    $openingValue = old('opening_text', $quotation?->opening_text ?: $openingDefault);

    $paymentDefault = $lastQuot?->payment_terms ?: ($profile->quotation_payment_terms ?? '');
    $paymentValue = old('payment_terms', $quotation?->payment_terms ?: $paymentDefault);

    // Batas karakter deskripsi: cukup mengisi kolom teks 76mm × 115mm di print A4.
    // 115mm ≈ 18 baris × ~52 char/baris ≈ 900 char teoritis; 500 aman dengan margin
    // untuk line breaks dan kata panjang.
    $descMaxLength = 500;
@endphp

<div class="card p-4 shadow-sm border border-gray-100 bg-white space-y-4">
    <h3 class="text-sm font-bold text-gray-700 uppercase tracking-widest">Teks Untuk Surat Penawaran</h3>

    <div>
        <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Pembukaan</label>
        <textarea name="opening_text" rows="3"
            class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"
            style="min-height: 0;"
            placeholder="Mis. Dengan Hormat, kami {name} bermaksud menawarkan...">{{ $openingValue }}</textarea>
        <p class="text-[11px] text-gray-400 mt-1">Pakai placeholder <code>{name}</code> untuk auto-isi nama bisnis.</p>
    </div>

    <div>
        <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Syarat Pembayaran</label>
        <textarea name="payment_terms" rows="2"
            class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"
            style="min-height: 0;"
            placeholder="Mis. Pembayaran dilakukan lunas di muka atau DP minimal 50%...">{{ $paymentValue }}</textarea>
        @php
            $showBankValue = old('show_bank_account', $quotation ? (bool) $quotation->show_bank_account : false);
        @endphp
        <label class="flex items-center gap-2 mt-2 text-xs text-gray-600 cursor-pointer">
            <input type="hidden" name="show_bank_account" value="0">
            <input type="checkbox" name="show_bank_account" value="1" class="w-4 h-4 accent-blue-600"
                   {{ $showBankValue ? 'checked' : '' }}>
            Tampilkan info rekening bank di cetak penawaran
            <span class="text-gray-400">(untuk customer yang butuh verifikasi rekening)</span>
        </label>
    </div>

    {{-- ========== LAMPIRAN GAMBAR ========== --}}
    <div class="border-t border-gray-200 pt-4">
        <div class="flex items-center justify-between mb-3">
            <div>
                <h4 class="text-sm font-bold text-gray-700 uppercase tracking-widest">Lampiran Gambar</h4>
                <p class="text-[11px] text-gray-400 mt-0.5">Akan dicetak di halaman terpisah, 2 gambar per halaman. Deskripsi maks {{ $descMaxLength }} karakter.</p>
            </div>
            <button type="button" id="attBtnAdd"
                class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-3 py-1.5 rounded">
                + Tambah Lampiran
            </button>
        </div>

        <div id="attCards" class="space-y-2">
            @foreach($existingAttachments as $att)
                @php
                    $titleVal = old('existing_attachments.'.$att->id.'.title', $att->title);
                    $descVal = old('existing_attachments.'.$att->id.'.description', $att->description);
                @endphp
                <div class="att-card att-existing border border-gray-200 rounded-lg p-3 flex gap-3 items-start"
                    data-att-id="{{ $att->id }}">
                    <img src="{{ $att->image_url }}" alt="" class="att-thumb w-20 h-20 object-cover rounded border flex-shrink-0">
                    <div class="flex-1 min-w-0">
                        <div class="att-title-display text-sm font-semibold text-gray-800 break-words">{{ $titleVal }}</div>
                        <div class="att-desc-display text-xs text-gray-600 mt-1 whitespace-pre-line break-words">{{ $descVal }}</div>
                        <input type="hidden" class="att-input-title" name="existing_attachments[{{ $att->id }}][title]" value="{{ $titleVal }}">
                        <input type="hidden" class="att-input-desc" name="existing_attachments[{{ $att->id }}][description]" value="{{ $descVal }}">
                        <input type="hidden" name="existing_attachments[{{ $att->id }}][sort_order]" value="{{ $att->sort_order }}">
                        <input type="hidden" name="existing_attachments[{{ $att->id }}][_delete]" value="0" class="att-delete-flag">
                    </div>
                    <div class="flex flex-col gap-1 flex-shrink-0">
                        <button type="button" class="att-btn-edit text-blue-600 hover:text-blue-800 text-xs font-semibold">Edit</button>
                        <button type="button" class="att-btn-remove text-red-600 hover:text-red-800 text-xs font-semibold">Hapus</button>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>

{{-- ========== MODAL TAMBAH / EDIT LAMPIRAN ========== --}}
<div id="attModal" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-[9999]">
    <div class="bg-white rounded-xl shadow-2xl w-[480px] max-w-[92vw] p-5">
        <div class="flex items-center justify-between mb-4">
            <h3 id="attModalTitleHead" class="text-base font-bold text-gray-800">Tambah Lampiran Gambar</h3>
            <button type="button" id="attModalClose" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
        </div>

        <div class="space-y-3">
            <div id="attModalFileSection">
                <label class="block text-xs text-gray-600 mb-1">
                    <span id="attModalFileLabel">File Gambar</span>
                    <span id="attModalFileRequired" class="text-red-500">*</span>
                    <span id="attModalFileOptional" class="text-gray-400 hidden">(opsional &mdash; kosongkan jika tidak ingin ganti)</span>
                </label>
                <div id="attModalDropZone"
                    class="relative border-2 border-dashed border-gray-300 rounded-lg cursor-pointer select-none hover:border-blue-400 hover:bg-blue-50/40 transition flex items-center justify-center"
                    style="min-height: 180px;">
                    <div id="attModalPlaceholder" class="text-center px-4 py-6">
                        <div class="text-2xl leading-none mb-1">📋</div>
                        <div class="text-xs font-medium text-gray-600">Klik untuk pilih file, drop, atau <kbd class="px-1 py-0.5 bg-gray-100 rounded text-[10px] font-semibold border">Ctrl</kbd>+<kbd class="px-1 py-0.5 bg-gray-100 rounded text-[10px] font-semibold border">V</kbd> untuk paste screenshot</div>
                    </div>
                    <div id="attModalPreview" class="hidden w-full p-2">
                        <img class="block mx-auto rounded" style="max-height: 220px; max-width: 100%; object-fit: contain;" alt="">
                    </div>
                    <button type="button" id="attModalClearFile"
                        class="hidden absolute top-1.5 right-1.5 bg-white border border-gray-300 hover:bg-red-50 hover:border-red-400 text-gray-600 hover:text-red-600 rounded-full w-6 h-6 flex items-center justify-center text-xs font-bold shadow-sm"
                        title="Hapus gambar">&times;</button>
                </div>
                <div class="text-[11px] text-gray-500 mt-1 text-center hidden" id="attModalFileName"></div>
                <div id="attModalFileWrap" class="hidden">
                    <input type="file" id="attModalFile" accept="image/*">
                </div>
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Judul <span class="text-red-500">*</span></label>
                <input type="text" id="attModalTitleField" maxlength="200"
                    class="border rounded w-full px-2 py-1.5 text-sm"
                    placeholder="Mis. Foto Sample Akrilik">
            </div>
            <div>
                <div class="flex items-baseline justify-between mb-1">
                    <label class="block text-xs text-gray-600">Keterangan <span class="text-gray-400">(opsional)</span></label>
                    <span class="text-[11px] text-gray-400"><span id="attModalDescCount">0</span> / {{ $descMaxLength }}</span>
                </div>
                <textarea id="attModalDesc" rows="3" maxlength="{{ $descMaxLength }}"
                    class="border rounded w-full px-2 py-1.5 text-sm"
                    style="min-height:0;"
                    placeholder="Deskripsi singkat gambar"></textarea>
                <p class="text-[11px] text-gray-400 mt-1">Batas {{ $descMaxLength }} karakter supaya tidak terpotong di hasil print.</p>
            </div>
            <div id="attModalError" class="hidden text-xs text-red-600"></div>
        </div>

        <div class="flex justify-end gap-2 mt-5">
            <button type="button" id="attModalCancel"
                class="px-4 py-2 border rounded text-sm font-semibold text-gray-600 hover:bg-gray-50">Batal</button>
            <button type="button" id="attModalSave"
                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm font-semibold">Simpan</button>
        </div>
    </div>
</div>

<script>
(function () {
    const modal = document.getElementById('attModal');
    if (!modal) return;

    const btnAdd = document.getElementById('attBtnAdd');
    const btnClose = document.getElementById('attModalClose');
    const btnCancel = document.getElementById('attModalCancel');
    const btnSave = document.getElementById('attModalSave');

    const fileSection = document.getElementById('attModalFileSection');
    const fileRequiredMark = document.getElementById('attModalFileRequired');
    const fileOptionalNote = document.getElementById('attModalFileOptional');
    const fileWrap = document.getElementById('attModalFileWrap');
    const fileInput = () => fileWrap.querySelector('input[type="file"]');
    const titleInput = document.getElementById('attModalTitleField');
    const descInput = document.getElementById('attModalDesc');
    const descCount = document.getElementById('attModalDescCount');
    const modalTitleHead = document.getElementById('attModalTitleHead');
    const btnSaveLabel = document.getElementById('attModalSave');
    const placeholder = document.getElementById('attModalPlaceholder');
    const preview = document.getElementById('attModalPreview');
    const previewImg = preview.querySelector('img');
    const fileNameEl = document.getElementById('attModalFileName');
    const errorEl = document.getElementById('attModalError');
    const cards = document.getElementById('attCards');
    const dropZone = document.getElementById('attModalDropZone');
    const btnClear = document.getElementById('attModalClearFile');

    let nextIdx = 0;
    let modalOpen = false;
    // mode: 'add' (lampiran baru) | 'edit' (edit card existing/new yang sudah ada)
    let mode = 'add';
    let editingCard = null;

    function openAddModal() {
        mode = 'add';
        editingCard = null;
        modalTitleHead.textContent = 'Tambah Lampiran Gambar';
        btnSaveLabel.textContent = 'Tambah';
        fileSection.classList.remove('hidden');
        // File wajib (bintang merah, sembunyikan note opsional)
        fileRequiredMark.classList.remove('hidden');
        fileOptionalNote.classList.add('hidden');
        resetModal();
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        modalOpen = true;
    }
    function openEditModal(card) {
        mode = 'edit';
        editingCard = card;
        modalTitleHead.textContent = 'Edit Lampiran';
        btnSaveLabel.textContent = 'Simpan';
        // File section tetap muncul tapi jadi opsional — user bisa ganti gambar bila salah upload.
        fileSection.classList.remove('hidden');
        fileRequiredMark.classList.add('hidden');
        fileOptionalNote.classList.remove('hidden');
        resetModal();
        // Tampilkan preview gambar yg sekarang aktif (dari thumbnail card)
        const currentThumb = card.querySelector('.att-thumb');
        if (currentThumb && currentThumb.src) {
            previewImg.src = currentThumb.src;
            preview.classList.remove('hidden');
            placeholder.classList.add('hidden');
        }
        // Pre-fill title & description dari hidden inputs
        const titleEl = card.querySelector('.att-input-title');
        const descEl = card.querySelector('.att-input-desc');
        titleInput.value = titleEl ? titleEl.value : '';
        descInput.value = descEl ? descEl.value : '';
        updateDescCount();
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        modalOpen = true;
    }
    function closeModal() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        modalOpen = false;
        editingCard = null;
    }
    function resetModal() {
        // Replace the file input with a fresh one (so previous selection is cleared)
        fileWrap.innerHTML = '<input type="file" id="attModalFile" accept="image/*">';
        bindFileChange();
        titleInput.value = '';
        descInput.value = '';
        clearPreview();
        errorEl.classList.add('hidden');
        errorEl.textContent = '';
        updateDescCount();
    }
    function clearPreview() {
        preview.classList.add('hidden');
        previewImg.src = '';
        placeholder.classList.remove('hidden');
        btnClear.classList.add('hidden');
        fileNameEl.classList.add('hidden');
        fileNameEl.textContent = '';
    }
    function showError(msg) {
        errorEl.textContent = msg;
        errorEl.classList.remove('hidden');
    }
    function showPreview(file) {
        const reader = new FileReader();
        reader.onload = e => {
            previewImg.src = e.target.result;
            preview.classList.remove('hidden');
            placeholder.classList.add('hidden');
            btnClear.classList.remove('hidden');
        };
        reader.readAsDataURL(file);
        fileNameEl.textContent = file.name || `pasted-${Date.now()}.png`;
        fileNameEl.classList.remove('hidden');
        errorEl.classList.add('hidden');
    }
    function setFile(file) {
        if (!file || !file.type.startsWith('image/')) return;
        const inp = fileInput();
        const dt = new DataTransfer();
        dt.items.add(file);
        inp.files = dt.files;
        showPreview(file);
    }
    function bindFileChange() {
        const inp = fileInput();
        inp.addEventListener('change', function () {
            const f = inp.files && inp.files[0];
            if (!f) { preview.classList.add('hidden'); return; }
            showPreview(f);
        });
    }
    function updateDescCount() {
        descCount.textContent = (descInput.value || '').length;
    }

    btnAdd?.addEventListener('click', openAddModal);
    btnClose.addEventListener('click', closeModal);
    btnCancel.addEventListener('click', closeModal);
    modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
    descInput.addEventListener('input', updateDescCount);

    // === Drop zone: click → open file picker ===
    dropZone.addEventListener('click', function (e) {
        if (e.target.closest('#attModalClearFile')) return;
        fileInput().click();
    });

    // === Clear button: hapus gambar dari preview & input ===
    btnClear.addEventListener('click', function (e) {
        e.stopPropagation();
        const inp = fileInput();
        inp.value = '';
        try { inp.files = new DataTransfer().files; } catch (_) {}
        clearPreview();
    });

    // === Drag & drop ===
    ['dragenter', 'dragover'].forEach(evt => {
        dropZone.addEventListener(evt, function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.add('border-blue-500', 'bg-blue-50/60');
        });
    });
    ['dragleave', 'drop'].forEach(evt => {
        dropZone.addEventListener(evt, function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.remove('border-blue-500', 'bg-blue-50/60');
        });
    });
    dropZone.addEventListener('drop', function (e) {
        const files = e.dataTransfer?.files;
        if (files && files.length) setFile(files[0]);
    });

    // === Paste from clipboard (saat modal terbuka, baik add maupun edit) ===
    document.addEventListener('paste', function (e) {
        if (!modalOpen) return;
        const tag = document.activeElement?.tagName;
        const id = document.activeElement?.id;
        if ((tag === 'INPUT' || tag === 'TEXTAREA') && id !== 'attModalFile') return;

        const items = e.clipboardData?.items;
        if (!items) return;
        for (const item of items) {
            if (item.type && item.type.startsWith('image/')) {
                const file = item.getAsFile();
                if (file) {
                    e.preventDefault();
                    setFile(file);
                    break;
                }
            }
        }
    });

    btnSave.addEventListener('click', function () {
        const title = titleInput.value.trim();
        const desc = descInput.value;

        if (!title) { showError('Judul wajib diisi.'); return; }

        if (mode === 'edit' && editingCard) {
            // Update display & hidden inputs di card
            editingCard.querySelector('.att-title-display').textContent = title;
            const descDisplay = editingCard.querySelector('.att-desc-display');
            if (descDisplay) descDisplay.textContent = desc;
            const tHidden = editingCard.querySelector('.att-input-title');
            const dHidden = editingCard.querySelector('.att-input-desc');
            if (tHidden) tHidden.value = title;
            if (dHidden) dHidden.value = desc;

            // Cek apakah user pilih file pengganti
            const inpEdit = fileInput();
            const newFile = inpEdit && inpEdit.files && inpEdit.files[0];
            if (newFile) {
                const isExisting = editingCard.classList.contains('att-existing');
                const isNew = editingCard.classList.contains('att-new');

                if (isExisting) {
                    // Untuk card yg sudah di DB: pasang/ganti hidden file input
                    // dengan nama existing_attachment_replacement_files[ID]
                    const attId = editingCard.dataset.attId;
                    let replaceWrap = editingCard.querySelector('.att-replace-wrap');
                    if (!replaceWrap) {
                        replaceWrap = document.createElement('div');
                        replaceWrap.className = 'att-replace-wrap';
                        editingCard.querySelector('.flex-1').appendChild(replaceWrap);
                    }
                    replaceWrap.innerHTML = '';
                    inpEdit.removeAttribute('id');
                    inpEdit.name = `existing_attachment_replacement_files[${attId}]`;
                    inpEdit.classList.add('hidden');
                    replaceWrap.appendChild(inpEdit);
                } else if (isNew) {
                    // Untuk card baru (belum disimpan): replace file input lama di card dgn yg baru
                    const hiddenWrap = editingCard.querySelector('.att-hidden-fields');
                    const oldFileInput = hiddenWrap?.querySelector('input[type="file"]');
                    if (oldFileInput && hiddenWrap) {
                        inpEdit.removeAttribute('id');
                        inpEdit.name = oldFileInput.name;
                        inpEdit.classList.add('hidden');
                        hiddenWrap.replaceChild(inpEdit, oldFileInput);
                    }
                }

                // Update thumbnail di card supaya user lihat gambar baru
                const reader = new FileReader();
                reader.onload = e => {
                    const thumb = editingCard.querySelector('.att-thumb');
                    if (thumb) thumb.src = e.target.result;
                };
                reader.readAsDataURL(newFile);
            }

            closeModal();
            return;
        }

        // mode === 'add' — wajib pilih file
        const inp = fileInput();
        const file = inp.files && inp.files[0];
        if (!file) { showError('Pilih file gambar terlebih dahulu.'); return; }

        const idx = nextIdx++;

        const card = document.createElement('div');
        card.className = 'att-card att-new border border-blue-200 bg-blue-50/30 rounded-lg p-3 flex gap-3 items-start';
        card.innerHTML = `
            <img class="att-thumb w-20 h-20 object-cover rounded border flex-shrink-0" alt="">
            <div class="flex-1 min-w-0">
                <div class="att-title-display text-sm font-semibold text-gray-800 break-words"></div>
                <div class="att-desc-display text-xs text-gray-600 mt-1 whitespace-pre-line break-words"></div>
                <div class="att-hidden-fields"></div>
            </div>
            <div class="flex flex-col gap-1 flex-shrink-0">
                <button type="button" class="att-btn-edit text-blue-600 hover:text-blue-800 text-xs font-semibold">Edit</button>
                <button type="button" class="att-btn-remove-new text-red-600 hover:text-red-800 text-xs font-semibold">Hapus</button>
            </div>
        `;

        card.querySelector('.att-title-display').textContent = title;
        card.querySelector('.att-desc-display').textContent = desc;

        // Move file input from modal into card hidden area, give it correct name.
        // Hapus id supaya tidak duplikat dengan input file di modal saat user tambah lampiran berikutnya.
        const hiddenWrap = card.querySelector('.att-hidden-fields');
        inp.removeAttribute('id');
        inp.name = `attachment_files[${idx}]`;
        inp.classList.add('hidden');
        hiddenWrap.appendChild(inp);

        // Title + description sebagai hidden input (untuk submission) DAN sebagai sumber data edit
        const tHidden = document.createElement('input');
        tHidden.type = 'hidden';
        tHidden.className = 'att-input-title';
        tHidden.name = `attachment_titles[${idx}]`;
        tHidden.value = title;
        hiddenWrap.appendChild(tHidden);

        const dHidden = document.createElement('input');
        dHidden.type = 'hidden';
        dHidden.className = 'att-input-desc';
        dHidden.name = `attachment_descriptions[${idx}]`;
        dHidden.value = desc;
        hiddenWrap.appendChild(dHidden);

        // Thumbnail
        const reader = new FileReader();
        reader.onload = e => { card.querySelector('.att-thumb').src = e.target.result; };
        reader.readAsDataURL(file);

        cards.appendChild(card);

        closeModal();
    });

    // === Click handler di list card: Edit, Hapus, Hapus-new ===
    cards?.addEventListener('click', function (e) {
        const editBtn = e.target.closest('.att-btn-edit');
        if (editBtn) {
            const card = editBtn.closest('.att-card');
            if (card) openEditModal(card);
            return;
        }

        // New card (belum disimpan): remove entirely
        if (e.target.classList.contains('att-btn-remove-new')) {
            e.target.closest('.att-card')?.remove();
            return;
        }
        // Existing card (sudah di DB): toggle delete flag (allow undo)
        if (e.target.classList.contains('att-btn-remove')) {
            const card = e.target.closest('.att-existing');
            if (!card) return;
            const flag = card.querySelector('.att-delete-flag');
            if (!flag) return;
            if (flag.value === '1') {
                flag.value = '0';
                card.classList.remove('opacity-50');
                card.style.textDecoration = '';
                e.target.textContent = 'Hapus';
                // Re-enable edit button
                const editBtn = card.querySelector('.att-btn-edit');
                if (editBtn) editBtn.removeAttribute('disabled');
            } else {
                flag.value = '1';
                card.classList.add('opacity-50');
                card.style.textDecoration = 'line-through';
                e.target.textContent = 'Batal hapus';
                // Disable edit button saat ditandai dihapus
                const editBtn = card.querySelector('.att-btn-edit');
                if (editBtn) editBtn.setAttribute('disabled', 'disabled');
            }
        }
    });

    // Initial bind for the modal's file input
    bindFileChange();
    updateDescCount();
})();
</script>
