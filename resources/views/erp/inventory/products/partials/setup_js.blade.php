<script>
    $(document).ready(function () {
        $('.product-search').select2({
            placeholder: "Cari produk...",
            width: '100%'
        });

        $('.account-search').select2({
            placeholder: "Cari kode / nama akun...",
            allowClear: true,
            width: '100%'
        });

        // Format .rupiah-input kini ditangani GLOBAL di layout erp.blade.php
        // (ketik→titik, submit→strip, window.cleanNumber). Tidak perlu lokal lagi.

        // ===== Peringatan perubahan belum disimpan =====
        // Tiap section punya tombol Simpan sendiri. Jika user mengedit lalu klik
        // "Kembali ke Daftar Produk" tanpa menyimpan, peringatkan dulu.
        const setupForms = $('form');
        const initialState = {};
        let allowLeave = false;

        function snapshotForms() {
            setupForms.each(function (i) {
                initialState[i] = $(this).serialize();
            });
        }

        function hasUnsavedChanges() {
            let dirty = false;
            setupForms.each(function (i) {
                if ($(this).serialize() !== initialState[i]) dirty = true;
            });
            return dirty;
        }

        // Ambil baseline setelah select2 & formatter on-load selesai.
        setTimeout(snapshotForms, 300);

        // Submit form mana pun = aksi simpan yang disengaja, jangan peringatkan.
        setupForms.on('submit', function () { allowLeave = true; });

        $('.back-to-index').on('click', function (e) {
            if (!allowLeave && hasUnsavedChanges()) {
                if (!confirm('Ada perubahan yang belum disimpan. Yakin ingin kembali tanpa menyimpan?')) {
                    e.preventDefault();
                }
            }
        });
    });

    function addUnitRow() {
        const wrapper = document.getElementById('units-wrapper');
        if (!wrapper) return;

        const index = wrapper.querySelectorAll('.unit-row').length;

        const row = document.createElement('div');
        row.className = "flex gap-2 items-center unit-row animate-fadeIn";

        row.innerHTML = `
                <input type="text" name="units[${index}][name]" placeholder="Unit"
                    class="border rounded-lg px-3 py-2 w-1/2 text-sm focus:ring-2 focus:ring-blue-500 outline-none transition">
                <input type="number" step="0.0001" name="units[${index}][conversion]" placeholder="Konversi"
                    class="border rounded-lg px-3 py-2 w-1/2 text-sm focus:ring-2 focus:ring-blue-500 outline-none transition">
                <button type="button" onclick="removeUnitRow(this)" class="text-red-500 text-xs font-bold hover:underline">Hapus</button>
            `;

        wrapper.appendChild(row);
    }

    function removeUnitRow(btn) {
        btn.closest('.unit-row').remove();
    }
</script>
<style>
    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(-5px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .animate-fadeIn {
        animation: fadeIn 0.2s ease-out forwards;
    }

    /* Custom Select2 Styles for better integration with Tailwind and current design */
    .select2-container--default .select2-selection--single {
        border-color: #e5e7eb;
        border-radius: 0.5rem;
        height: 38px;
        display: flex;
        align-items: center;
    }

    .select2-container--default .select2-selection--single .select2-selection__rendered {
        color: #374151;
        font-size: 0.875rem;
        line-height: normal;
    }

    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 36px;
    }
</style>