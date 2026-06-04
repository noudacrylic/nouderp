<h4 class="text-xl font-bold text-gray-800">
    Sales Order {{ $so->order_number }}
</h4>

<div class="mb-2 text-sm text-gray-600">
    Status : <b class="text-gray-900">{{ strtoupper($so->status) }}</b>
    &nbsp; | &nbsp;
    Invoice : 
    @if($invoiceStatus == 'not_invoiced')
        <b class="text-red-600 uppercase">NOT INVOICED</b>
    @elseif($invoiceStatus == 'partial')
        <b class="text-yellow-600 uppercase">PARTIAL</b>
    @else
        <b class="text-green-600 uppercase">INVOICED FULL</b>
    @endif
</div>

<div class="mb-3 text-sm text-gray-700">
    Customer : <b class="text-gray-900">{{ $so->customer->name ?? '-' }}</b>
</div>
