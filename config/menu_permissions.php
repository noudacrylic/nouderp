<?php

/*
|--------------------------------------------------------------------------
| Menu Permission Registry
|--------------------------------------------------------------------------
|
| Tree definisi semua menu/sub-menu di sidebar. Dipakai untuk:
| 1. Render daftar checkbox di halaman Settings → User & Akses
| 2. Resolve route name → menu_key di middleware EnsureMenuAccess
| 3. Render top-tabs partial generic
| 4. Wrap sidebar @if(user_can_access('xxx'))
|
| Field per leaf:
|   'label'          → display name
|   'url'            → URL (string langsung) atau dipakai oleh top-tabs/sidebar landing
|   'route_patterns' → pattern Str::is untuk match route name → menu_key
|
| Field per group:
|   'icon'           → emoji icon di sidebar/header
|   'always_visible' → true → semua user yang login bisa akses
|   'role_gate'      → [...]  → hanya role tertentu yang lihat
|
*/

return [

    'dashboard' => [
        'label' => 'Dashboard',
        'icon' => '🏠',
        // Semua user yang login boleh buka Dashboard. Bagian sensitif (keuangan/penjualan)
        // hanya dirender untuk super_admin & admin; role 'user' hanya lihat kartu operasional,
        // Stok Menipis, & Proses Produksi per Divisi (lihat erp/dashboard.blade.php $isAdmin).
        'always_visible' => true,
        'url' => '/erp/dashboard',
        'route_patterns' => ['dashboard', 'dashboard.*'],
    ],

    'tasks' => [
        'label' => 'Task Manager',
        'icon' => '✅',
        'children' => [
            'tasks.board'      => ['label' => 'Board',           'url' => '/erp/tasks',           'route_patterns' => ['tasks.index', 'tasks.move', 'tasks.status', 'tasks.create', 'tasks.store', 'tasks.show', 'tasks.edit', 'tasks.update', 'tasks.destroy', 'tasks.subtasks.*', 'tasks.visible_categories.*', 'tasks.assignee', 'tasks.priority', 'tasks.description', 'tasks.json', 'tasks.links.*', 'tasks.categories.store', 'tasks.categories.create', 'tasks.categories.edit', 'tasks.categories.update']],
            'tasks.list'       => ['label' => 'Daftar',          'url' => '/erp/tasks/list',      'route_patterns' => ['tasks.list']],
            'tasks.automation' => ['label' => 'Otomasi',         'url' => '/erp/tasks/schedules', 'route_patterns' => ['tasks.schedules.*', 'tasks.automation.*']],
            'tasks.categories' => ['label' => 'Kategori',        'url' => '/erp/tasks/categories','route_patterns' => ['tasks.categories.*']],
        ],
    ],

    'inventory' => [
        'label' => 'Inventory',
        'icon' => '📦',
        'children' => [
            'inventory.warehouses'  => ['label' => 'Warehouse',         'url' => '/erp/inventory/warehouses', 'route_patterns' => ['inventory.warehouses.*', 'product-stock']],
            'inventory.products'    => ['label' => 'Produk',            'url' => '/erp/inventory/products',   'route_patterns' => ['inventory.products.*', 'products.*']],
            'inventory.stocks'      => ['label' => 'Stock',             'url' => '/erp/inventory/stocks',     'route_patterns' => ['inventory.stocks.*', 'inventory.product-stock']],
            'inventory.transfers'   => ['label' => 'Transfer Stock',    'url' => '/erp/inventory/transfers',  'route_patterns' => ['inventory.transfers.*']],
            'inventory.adjustments' => ['label' => 'Penyesuaian Stock', 'url' => '/erp/inventory/adjustments','route_patterns' => ['inventory.adjustments.*']],
        ],
    ],

    'sales' => [
        'label' => 'Sales',
        'icon' => '🛍️',
        'children' => [
            'sales.customers'   => ['label' => 'Customer',    'url' => '/erp/master/customers',     'route_patterns' => ['customers.*']],
            'sales.quotations'  => ['label' => 'Penawaran',   'url' => '/erp/sales/quotations',     'route_patterns' => ['sales.quotations.*', 'quotations.*']],
            'sales.orders'      => ['label' => 'Sales Order', 'url' => '/erp/sales/orders',         'route_patterns' => ['sales.orders.*']],
            'sales.deliveries'  => ['label' => 'Pengiriman',  'url' => '/erp/sales/deliveries',     'route_patterns' => ['sales.deliveries.*']],
            'sales.invoices'    => ['label' => 'Invoice',     'url' => '/erp/sales/invoices',       'route_patterns' => ['sales.invoices.*']],
            'sales.billing'     => ['label' => 'Billing',     'url' => '/erp/sales/billing',        'route_patterns' => ['sales.billing.*']],
            'sales.payment'     => ['label' => 'Payment',     'url' => '/erp/sales/payment',        'route_patterns' => ['sales.payment.*', 'sales.payments.*']],
            'sales.returns'     => ['label' => 'Retur',       'url' => '/erp/sales/returns',        'route_patterns' => ['sales.returns.*']],
            'sales.warranty'    => ['label' => 'Garansi',     'url' => '/erp/sales/warranty',       'route_patterns' => ['sales.warranty.*']],
            'sales.cek-ongkir'  => ['label' => 'Cek Ongkir',  'url' => '/erp/sales/cek-ongkir',     'route_patterns' => ['sales.cek-ongkir*']],
            'sales.promosi'     => ['label' => 'Promosi',     'url' => '/erp/sales/promosi',        'route_patterns' => ['sales.promosi.*']],
        ],
    ],

    'pos' => [
        'label' => 'POS',
        'icon' => '🧾',
        'children' => [
            // Kasir — layar buat transaksi langsung (Invoice + Bayar, tanpa Sales Order).
            'pos.kasir' => [
                'label' => 'Kasir',
                'url'   => '/erp/pos/kasir',
                'route_patterns' => ['pos.kasir*'],
            ],
            // Pemrosesan Pesanan — dashboard fulfillment tim packing (umbrella + 3 sub-tab gaya Absensi).
            'pos.fulfillment' => [
                'label' => 'Pemrosesan Pesanan',
                'url'   => '/erp/pos/fulfillment/belum-siap',
                'route_patterns' => ['pos.fulfillment.*'],
                'subtabs' => [
                    ['label' => 'Belum Siap',     'url' => '/erp/pos/fulfillment/belum-siap',     'route_patterns' => ['pos.fulfillment.belum-siap*']],
                    ['label' => 'Perlu Diproses', 'url' => '/erp/pos/fulfillment/perlu-diproses', 'route_patterns' => ['pos.fulfillment.perlu-diproses*'], 'badge' => 'perlu_diproses'],
                    ['label' => 'Telah Diproses', 'url' => '/erp/pos/fulfillment/telah-diproses', 'route_patterns' => ['pos.fulfillment.telah-diproses*'], 'badge' => 'telah_diproses'],
                    ['label' => 'Dikirim',        'url' => '/erp/pos/fulfillment/dikirim',        'route_patterns' => ['pos.fulfillment.dikirim*'], 'badge' => 'dikirim'],
                    ['label' => 'Selesai',        'url' => '/erp/pos/fulfillment/selesai',        'route_patterns' => ['pos.fulfillment.selesai*']],
                    ['label' => 'Pembatalan',     'url' => '/erp/pos/fulfillment/pembatalan',     'route_patterns' => ['pos.fulfillment.pembatalan*'], 'badge' => 'pembatalan', 'badge_style' => 'alert'],
                ],
            ],
            // FUTURE: 'pos.direct-invoice' => ['label' => 'Buat Order POS', 'url' => '/erp/pos/direct-invoice', 'route_patterns' => ['pos.direct-invoice.*']],
        ],
    ],

    'purchasing' => [
        'label' => 'Purchasing',
        'icon' => '🛒',
        'children' => [
            'purchasing.suppliers' => ['label' => 'Supplier',         'url' => '/erp/purchasing/suppliers', 'route_patterns' => ['purchasing.suppliers.*']],
            'purchasing.orders'    => ['label' => 'Purchase Order',   'url' => '/erp/purchasing/orders',    'route_patterns' => ['purchasing.orders.*']],
            'purchasing.invoices'  => ['label' => 'Purchase Invoice', 'url' => '/erp/purchasing/invoices',  'route_patterns' => ['purchasing.invoices.*']],
            'purchasing.payments'  => ['label' => 'Payment',          'url' => '/erp/purchasing/payments',  'route_patterns' => ['purchasing.payments.*']],
            'purchasing.returns'   => ['label' => 'Return',           'url' => '/erp/purchasing/returns',   'route_patterns' => ['purchasing.returns.*']],
        ],
    ],

    'fixed-assets' => [
        'label' => 'Aset Tetap',
        'icon' => '🏢',
        'children' => [
            'fixed-assets.categories' => ['label' => 'Kategori',    'url' => '/erp/fixed-assets/categories', 'route_patterns' => ['fixed-assets.categories.*']],
            'fixed-assets.assets'     => ['label' => 'Daftar Aset', 'url' => '/erp/fixed-assets',            'route_patterns' => ['fixed-assets.index', 'fixed-assets.create', 'fixed-assets.show', 'fixed-assets.edit', 'fixed-assets.store', 'fixed-assets.update', 'fixed-assets.destroy']],
            'fixed-assets.transfers'  => ['label' => 'Transfer',    'url' => '/erp/fixed-assets/transfers',  'route_patterns' => ['fixed-assets.transfers.*']],
            'fixed-assets.disposals'  => ['label' => 'Disposisi',   'url' => '/erp/fixed-assets/disposals',  'route_patterns' => ['fixed-assets.disposals.*']],
            'fixed-assets.settings'   => ['label' => 'Settings',    'url' => '/erp/fixed-assets/settings',   'route_patterns' => ['fixed-assets.settings.*']],
        ],
    ],

    'sdm' => [
        'label' => 'SDM',
        'icon' => '👥',
        'children' => [
            'sdm.fingerprint-machines' => ['label' => 'Mesin Fingerprint', 'url' => '/erp/sdm/mesin',                  'route_patterns' => ['sdm.mesin.*']],
            'sdm.departments'          => ['label' => 'Divisi',            'url' => '/erp/production/departments',     'route_patterns' => ['production.departments.*']],
            'sdm.karyawan'             => ['label' => 'Karyawan',          'url' => '/erp/sdm/karyawan',               'route_patterns' => ['sdm.karyawan.*']],
            'sdm.absensi'              => [
                'label' => 'Absensi',
                'url'   => '/erp/sdm/absensi',
                'route_patterns' => [
                    'sdm.absensi.*', 'sdm.periode-gaji.*', 'sdm.slip-gaji.*',
                    'sdm.attendance.*', 'sdm.izin.*', 'sdm.sp.*',
                    'sdm.kebijakan.*', 'sdm.libur.*',
                    'sdm.kasbon.*', 'sdm.kasbon-pembayaran.*',
                ],
            ],
            'sdm.pengajuan-izin' => [
                'label' => 'Penerimaan Izin',
                'url'   => '/erp/sdm/pengajuan-izin',
                'route_patterns' => ['sdm.pengajuan-izin.*'],
            ],
        ],
    ],

    'production' => [
        'label' => 'Produksi',
        'icon' => '🏭',
        'children' => [
            'production.executors'           => ['label' => 'Eksekutor',         'url' => '/erp/production/executors',          'route_patterns' => ['production.executors.*']],
            'production.boms'                => ['label' => 'Bill of Materials', 'url' => '/erp/production/boms',               'route_patterns' => ['production.boms.*']],
            'production.orders'              => ['label' => 'Order Produksi',    'url' => '/erp/production/orders',             'route_patterns' => ['production.orders.*']],
            'production.process'             => ['label' => 'Proses Produksi',   'url' => '/erp/production/process',            'route_patterns' => ['production.process.*'], 'dynamic_dept' => true],
            'production.material-additions'  => ['label' => 'Penambahan Bahan',  'url' => '/erp/production/material-additions', 'route_patterns' => ['production.material-additions.*']],
            'production.completed'           => ['label' => 'Finalisasi',        'url' => '/erp/production/completed',          'route_patterns' => ['production.completed.*']],
        ],
    ],

    'cash-bank' => [
        'label' => 'Kas & Bank',
        'icon' => '💵',
        'children' => [
            // Pengeluaran — umbrella (1 permission key) dengan sub-tab tier-2 (gaya Absensi).
            'cash-bank.disbursements' => [
                'label' => 'Pengeluaran',
                'url'   => '/erp/finance/cash-bank/disbursements/general',
                'route_patterns' => ['finance.cash-bank.disbursements.*', 'finance.cash-bank.salary-payments.*'],
                'subtabs' => [
                    ['label' => 'Pengeluaran Umum',    'url' => '/erp/finance/cash-bank/disbursements/general',  'route_patterns' => ['finance.cash-bank.disbursements.general*']],
                    ['label' => 'Bayar Ongkir',        'url' => '/erp/finance/cash-bank/disbursements/freight',  'route_patterns' => ['finance.cash-bank.disbursements.freight*']],
                    ['label' => 'Refund Customer',     'url' => '/erp/finance/cash-bank/disbursements/refund',   'route_patterns' => ['finance.cash-bank.disbursements.refund*']],
                    ['label' => 'Bayar Gaji',          'url' => '/erp/finance/cash-bank/salary-payments',        'route_patterns' => ['finance.cash-bank.salary-payments.*']],
                    ['label' => 'Biaya API Biteship',  'url' => '/erp/finance/cash-bank/disbursements/api-cost', 'route_patterns' => ['finance.cash-bank.disbursements.api-cost*']],
                ],
            ],
            // Pemasukan — umbrella dengan sub-tab tier-2.
            'cash-bank.receipts' => [
                'label' => 'Pemasukan',
                'url'   => '/erp/finance/cash-bank/receipts/general',
                'route_patterns' => ['finance.cash-bank.receipts.*'],
                'subtabs' => [
                    ['label' => 'Pemasukan Umum',  'url' => '/erp/finance/cash-bank/receipts/general', 'route_patterns' => ['finance.cash-bank.receipts.general*']],
                    ['label' => 'Refund Supplier', 'url' => '/erp/finance/cash-bank/receipts/refund',  'route_patterns' => ['finance.cash-bank.receipts.refund*']],
                ],
            ],
            'cash-bank.transfers'       => ['label' => 'Transfer Antar Bank',   'url' => '/erp/finance/cash-bank/transfers',       'route_patterns' => ['finance.cash-bank.transfers.*']],
            'cash-bank.reconciliations' => ['label' => 'Rekonsiliasi Bank',     'url' => '/erp/finance/cash-bank/reconciliations', 'route_patterns' => ['finance.cash-bank.reconciliations.*']],
            'cash-bank.settlements'     => ['label' => 'Settlement Marketplace', 'url' => '/erp/finance/cash-bank/settlements',     'route_patterns' => ['finance.cash-bank.settlements.*']],
        ],
    ],

    'accounting' => [
        'label' => 'Accounting',
        'icon' => '💰',
        'children' => [
            'accounting.coa'              => ['label' => 'Chart of Accounts', 'url' => '/erp/accounting/accounts',                  'route_patterns' => ['accounts.index', 'accounts.create', 'accounts.store', 'accounts.edit', 'accounts.update', 'accounts.destroy', 'accounts.show', 'accounts.archive', 'accounts.restore', 'accounts.next-code', 'accounts.search']],
            'accounting.opening-balance'  => ['label' => 'Opening Balance',   'url' => '/erp/accounting/accounts/opening-balance', 'route_patterns' => ['accounts.opening-balance.*']],
            'accounting.period'           => ['label' => 'Accounting Period', 'url' => '/erp/accounting/period/list',              'route_patterns' => ['accounting.period.*']],
        ],
    ],

    'reports' => [
        'label' => 'Laporan',
        'icon' => '📊',
        'children' => [
            'reports.balance-sheet'    => ['label' => 'Neraca',                'url' => '/erp/accounting/reports/balance-sheet',    'route_patterns' => ['accounting.reports.balance-sheet']],
            'reports.income-statement' => ['label' => 'Laba Rugi',             'url' => '/erp/accounting/reports/income-statement', 'route_patterns' => ['accounting.reports.income-statement']],
            'reports.product-sales'    => ['label' => 'Laporan Penjualan',     'url' => '/erp/sales/reports/product-sales',         'route_patterns' => ['sales.reports.product-sales', 'sales.reports.manual-sales*']],
        ],
    ],

    'panduan' => [
        'label' => 'Panduan',
        'icon' => '📖',
        'always_visible' => true,
        'url' => '/erp/panduan',
        'route_patterns' => ['panduan.*'],
    ],

    'settings' => [
        'label' => 'Settings',
        'icon' => '⚙️',
        'role_gate' => ['super_admin', 'admin'],
        'children' => [
            'settings.business-profile' => ['label' => 'Profil Bisnis',      'url' => '/erp/settings/business-profile', 'route_patterns' => ['settings.business-profile.*']],
            'settings.inventory'        => ['label' => 'Inventory Settings', 'url' => '/erp/settings/inventory',        'route_patterns' => ['settings.inventory', 'settings.inventory.update']],
            'settings.integrations'     => ['label' => 'Integrasi',          'url' => '/erp/settings/integrations',     'route_patterns' => ['settings.integrations.*', 'settings.midtrans.*', 'settings.shipping.biteship*', 'settings.jubelio.*', 'settings.marketplace.*']],
            'settings.payment-fee'      => ['label' => 'Payment Fee',        'url' => '/erp/settings/payment-fee',      'route_patterns' => ['settings.payment-fee.*']],
            'settings.freight'          => ['label' => 'Pengaturan Ongkir',  'url' => '/erp/settings/freight',          'route_patterns' => ['settings.freight.*']],
            'settings.shipping-couriers'=> ['label' => 'Jasa Kirim',         'url' => '/erp/settings/shipping-couriers','route_patterns' => ['settings.shipping-couriers.*']],
            'settings.production'       => ['label' => 'Pengaturan Produksi', 'url' => '/erp/production/settings',       'route_patterns' => ['production.settings', 'production.settings.update', 'production.settings.byproducts.*', 'production.settings.testing.*']],
            'settings.users'            => ['label' => 'User & Akses',       'url' => '/erp/settings/users',            'route_patterns' => ['settings.users.*'], 'role_gate' => ['super_admin', 'admin']],
        ],
    ],

];
