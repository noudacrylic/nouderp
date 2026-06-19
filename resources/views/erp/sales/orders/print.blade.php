@extends('erp.print._shell', [
    'printTitle' => 'Pesanan Penjualan ' . $order->order_number,
    'pdfUrl'     => route('sales.orders.pdf', $order->id),
    'indexUrl'   => route('sales.orders.index'),
])

@section('papers')

@include('erp._partials.print-styles-accurate')

<style>
    /* Sembunyikan tanda tangan saat checkbox tidak dicentang — sisakan ruang
       (visibility) agar bisa tanda tangan basah secara manual. */
    body.no-sig .sig-img { visibility: hidden; }

    .sig-toggle {
        display: flex; align-items: center; gap: 8px;
        margin-top: 4px; padding: 10px 12px;
        background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 4px;
        font-size: 12px; font-weight: 600; color: #334155; cursor: pointer;
        user-select: none;
    }
    .sig-toggle input { width: 16px; height: 16px; cursor: pointer; margin: 0; }
</style>

@include('erp.sales.orders._paper', ['order' => $order, 'profile' => $profile])

@endsection

@section('toolbar-extra')
    <label class="sig-toggle">
        <input type="checkbox" id="toggle-sig" checked onchange="document.body.classList.toggle('no-sig', !this.checked)">
        Sertakan tanda tangan
    </label>
@endsection
