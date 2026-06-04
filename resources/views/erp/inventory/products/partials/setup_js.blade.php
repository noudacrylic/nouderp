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

        function formatRupiah(angka) {
            let number_string = angka.replace(/[^,\d]/g, '').toString(),
                split = number_string.split(','),
                sisa = split[0].length % 3,
                rupiah = split[0].substr(0, sisa),
                ribuan = split[0].substr(sisa).match(/\d{3}/gi);

            if (ribuan) {
                let separator = sisa ? '.' : '';
                rupiah += separator + ribuan.join('.');
            }

            return rupiah;
        }

        document.querySelectorAll('.rupiah-input').forEach(function (el) {
            el.addEventListener('keyup', function () {
                this.value = formatRupiah(this.value);
            });
            // Also format initially on load
            if (this.value) {
                this.value = formatRupiah(this.value);
            }
        });

        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function () {
                this.querySelectorAll('.rupiah-input').forEach(input => {
                    input.value = input.value.replace(/\./g, '');
                });
            });
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