<?php

/*
|--------------------------------------------------------------------------
| Task Manager Config
|--------------------------------------------------------------------------
| Daftar tipe dokumen yang bisa dilink ke Task via polymorphic relation
| `taskable_type` + `taskable_id`. Per tipe disimpan:
|   - label       : tampilan untuk user (mis. "Invoice Pembelian")
|   - route       : route name untuk show detail (mis. "purchasing.invoices.show")
|   - label_field : kolom di model yang dipakai sebagai display label
|   - search_route: API endpoint untuk autocomplete pencarian (optional)
|
| Tambahkan entry baru di sini bila ada dokumen lain yang ingin bisa
| dilink ke Task — tidak perlu ubah controller atau view.
*/

return [
    /*
    | Daftar tipe dokumen yang bisa dilink ke Task.
    |   - label       : tampilan untuk user
    |   - route       : route name untuk show detail
    |   - label_field : kolom yang dipakai sebagai display label di list link
    |   - match_field : kolom yang dipakai utk auto-detect saat user input nomor
    |                   dokumen. Fallback ke label_field bila tidak diset.
    |
    | Urutan entry = urutan pencarian saat input nomor dokumen di task.
    | Letakkan yang paling sering dilink di atas.
    */
    'taskable_types' => [
        \App\Models\SalesQuotation::class => [
            'label'       => 'Quotation',
            'route'       => 'sales.quotations.show',
            'label_field' => 'quotation_number',
        ],
        \App\Modules\Sales\Models\SalesOrder::class => [
            'label'       => 'Sales Order',
            'route'       => 'sales.orders.show',
            'label_field' => 'order_number',
        ],
        \App\Models\SalesInvoice::class => [
            'label'       => 'Sales Invoice',
            'route'       => 'sales.invoices.show',
            'label_field' => 'invoice_number',
        ],
        \App\Modules\Purchasing\Models\PurchaseOrder::class => [
            'label'       => 'Purchase Order',
            'route'       => 'purchasing.orders.show',
            'label_field' => 'order_number',
        ],
        \App\Modules\Purchasing\Models\PurchaseInvoice::class => [
            'label'       => 'Invoice Pembelian',
            'route'       => 'purchasing.invoices.show',
            'label_field' => 'invoice_number',
        ],
        \App\Modules\Production\Models\ProductionOrder::class => [
            'label'       => 'Production Order',
            'route'       => 'production.orders.show',
            'label_field' => 'order_number',
        ],
        \App\Core\Inventory\Product::class => [
            'label'       => 'Produk',
            'route'       => 'inventory.products.edit',
            'label_field' => 'name',
            'match_field' => 'sku',
        ],
    ],
];
