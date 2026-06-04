@props(['label' => 'Save'])

<div class="mt-8 flex justify-end">
    <button type="submit"
        class="bg-blue-600 text-white px-6 py-2 rounded font-semibold shadow-sm hover:bg-blue-700 transition">
        {{ $label }}
    </button>
</div>