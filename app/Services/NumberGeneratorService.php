<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Standard Number Generator Service for ERP-grade Document Sequencing
 * HandlesSQ, SO, SI, DO, ADV prefixing and period-based increments.
 */
class NumberGeneratorService
{
    /**
     * Untuk customer marketplace (is_marketplace = true), nomor dokumen
     * mengikuti No. Pesanan marketplace ($marketplaceRef). Untuk customer
     * biasa, jatuh ke generator standar.
     */
    public static function forCustomer(string $type, ?int $customerId, ?string $marketplaceRef = null): string
    {
        $ref = trim((string) $marketplaceRef);

        if ($customerId && $ref !== '') {
            $customer = \App\Models\Customer::find($customerId);
            if ($customer && $customer->is_marketplace) {
                return $ref;
            }
        }

        return self::generate($type);
    }

    /**
     * Generate next document number for given type
     *
     * @param string $type SQ|SO|SI|DO|ADV
     * @return string
     */
    public static function generate(string $type): string
    {
        $date = now();
        $year = $date->format('Y');
        $month = $date->format('m');
        
        // Structure: PREFIX/YEAR/MONTH/NUMBER
        $prefix = $type . '/' . $year . '/' . $month;
        
        $config = self::getConfig($type);
        $table  = $config['table'];
        $column = $config['column'];
        $length = $config['length'] ?? 5;

        // Find last number in current month
        $last = DB::table($table)
            ->where($column, 'like', $prefix . '%')
            ->orderByDesc($column)
            ->first();

        if ($last) {
            // Extract numeric part from the end
            $lastFullNumber = $last->{$column};
            $lastNumStr = substr($lastFullNumber, -$length);
            $next = (int)$lastNumStr + 1;
        } else {
            // Reset to 1 for new month
            $next = 1;
        }

        return $prefix . '/' . str_pad($next, $length, '0', STR_PAD_LEFT);
    }

    /**
     * Centralized configuration for all document types
     */
    private static function getConfig(string $type): array
    {
        $type = strtoupper($type);

        if (str_starts_with($type, 'DO')) {
            return ['table' => 'sales_deliveries', 'column' => 'delivery_number', 'length' => 5];
        }

        return match ($type) {
            'SQ'        => ['table' => 'sales_quotations', 'column' => 'quotation_number', 'length' => 5],
            'SO'        => ['table' => 'sales_orders',      'column' => 'order_number',     'length' => 5],
            'SI', 'INV' => ['table' => 'sales_invoices',    'column' => 'invoice_number',   'length' => 5],
            'ADV'       => ['table' => 'sales_advances',    'column' => 'advance_number',   'length' => 5],
            'SR'        => ['table' => 'sales_returns',     'column' => 'return_number',    'length' => 5],
            'GR'        => ['table' => 'warranty_orders',  'column' => 'warranty_number',  'length' => 5],
            'BOM'       => ['table' => 'boms',              'column' => 'bom_number',       'length' => 5],
            'PO', 'OP'  => ['table' => 'production_orders',             'column' => 'order_number',    'length' => 5],
            'MAT'       => ['table' => 'production_material_additions', 'column' => 'addition_number', 'length' => 5],
            default => throw new \Exception("Unsupported document type: $type"),
        };
    }
}
