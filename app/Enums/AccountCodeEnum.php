<?php

namespace App\Enums;

class AccountCodeEnum
{
    const SALES_REVENUE = '4001';
    const SALES_DISCOUNT = '4003';
    const INVENTORY = '1130';
    const INVENTORY_REPAIR = '1131';
    const WARRANTY_STOCK = '1132'; // Beban Stok Garansi (expense)
    const COGS = '5001';

    const CUSTOMER_OVERPAY = '2106'; 
    const SALES_ADVANCE = '2105';
    const INVENTORY_DAMAGED = '1131'; // Backward-compatible alias for repair inventory
    const SALES_LOSS = '6105'; // Beban Kerugian Retur
    const WARRANTY_EXPENSE = '6106'; // Beban Garansi
    const AR_RECEIVABLE = '1120';
    const CASH = '1101';
    const WIP = '1140'; // Barang Dalam Proses Produksi
    const SHIPPING_DEPOSIT = '1203'; // Titipan Pengiriman (ongkir dibayar customer lewat kita)
    const SERVICE_REVENUE = '4010'; // Pendapatan Jasa (jasa perbaikan garansi)
}
