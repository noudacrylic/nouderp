<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\ProductSalesManual;
use App\Core\Inventory\Product;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ProductSalesManualController extends Controller
{
    public function index()
    {
        $records = ProductSalesManual::with('product')
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderBy('product_id')
            ->get();

        $products = Product::where('is_active', true)->orderBy('name')->get(['id', 'sku', 'name']);

        $currentYear  = (int) now()->format('Y');
        $currentMonth = (int) now()->format('n');

        return view('erp.sales.reports.manual_sales', compact('records', 'products', 'currentYear', 'currentMonth'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'year'       => 'required|integer|min:2020|max:2099',
            'month'      => 'required|integer|min:1|max:12',
            'qty'        => 'required|numeric|min:0.0001',
            'notes'      => 'nullable|string|max:255',
        ]);

        ProductSalesManual::updateOrCreate(
            [
                'product_id' => $request->product_id,
                'year'       => $request->year,
                'month'      => $request->month,
            ],
            [
                'qty'   => $request->qty,
                'notes' => $request->notes,
            ]
        );

        return back()->with('success', 'Data penjualan manual disimpan.');
    }

    public function destroy(int $id)
    {
        ProductSalesManual::findOrFail($id)->delete();
        return back()->with('success', 'Data awal penjualan dihapus.');
    }

    /** Header kolom template/import — ramah, bisa round-trip. */
    private const IMPORT_HEADERS = ['SKU', 'Nama Produk', 'Tahun', 'Bulan', 'Qty Terjual', 'Catatan'];

    /**
     * Download template Excel untuk import data awal penjualan.
     */
    public function importTemplate()
    {
        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Data Awal Penjualan');

        $sheet->fromArray(self::IMPORT_HEADERS, null, 'A1');
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);
        $sheet->getStyle('A1:F1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('FEF3C7');

        // Contoh
        $sheet->fromArray([
            ['CONTOH-001', 'Contoh Produk (hapus baris ini)', 2026, 1, 120, 'Penjualan historis'],
            ['CONTOH-002', 'Boleh isi SKU saja, Nama diabaikan', 2026, 'Feb', 80, ''],
        ], null, 'A2');

        foreach (['A' => 18, 'B' => 42, 'C' => 10, 'D' => 10, 'E' => 14, 'F' => 30] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        // Petunjuk
        $sheet->setCellValue('A6', 'Petunjuk:');
        $sheet->setCellValue('A7',  '- SKU: WAJIB, harus cocok dengan produk yang sudah ada.');
        $sheet->setCellValue('A8',  '- Nama Produk: hanya pengingat, tidak dipakai untuk pencocokan.');
        $sheet->setCellValue('A9',  '- Tahun: 4 digit, mis. 2026.');
        $sheet->setCellValue('A10', '- Bulan: angka 1-12 atau nama (Jan, Feb, Mar, ...).');
        $sheet->setCellValue('A11', '- Qty Terjual: jumlah unit terjual pada periode itu.');
        $sheet->setCellValue('A12', '- Jika SKU + Tahun + Bulan sudah ada, datanya di-update otomatis.');
        $sheet->setCellValue('A13', '- Hapus baris CONTOH sebelum isi data riil.');
        $sheet->getStyle('A6')->getFont()->setBold(true);
        $sheet->getStyle('A7:A13')->getFont()->setItalic(true);

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'template-data-awal-penjualan.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Parse Excel & upsert data awal penjualan. Match produk via SKU.
     */
    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv']);

        try {
            $ss = \PhpOffice\PhpSpreadsheet\IOFactory::load($request->file('file')->getRealPath());
            $rows = $ss->getActiveSheet()->toArray(null, true, false, false);
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal baca file: ' . $e->getMessage());
        }

        if (count($rows) < 2) {
            return back()->with('error', 'File kosong atau cuma header (perlu minimal 1 baris data).');
        }

        // Header normalize — terima beberapa alias.
        $aliases = [
            'sku'   => ['sku', 'kode', 'kode produk'],
            'year'  => ['tahun', 'year'],
            'month' => ['bulan', 'month'],
            'qty'   => ['qty terjual', 'qty', 'jumlah', 'qty_terjual'],
            'notes' => ['catatan', 'notes', 'keterangan'],
        ];
        $header = array_map(fn($v) => strtolower(trim((string) $v)), $rows[0]);
        $idx = [];
        foreach ($aliases as $field => $list) {
            $idx[$field] = false;
            foreach ($list as $alias) {
                $found = array_search($alias, $header, true);
                if ($found !== false) { $idx[$field] = $found; break; }
            }
        }
        foreach (['sku', 'year', 'month', 'qty'] as $req) {
            if ($idx[$req] === false) {
                return back()->with('error', 'Kolom "' . ucfirst($req) . '" wajib ada di file Excel.');
            }
        }

        $monthMap = ['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'mei'=>5,'may'=>5,'jun'=>6,
                     'jul'=>7,'agu'=>8,'agt'=>8,'aug'=>8,'sep'=>9,'okt'=>10,'oct'=>10,'nov'=>11,'des'=>12,'dec'=>12];

        $saved = 0;
        $skipped = [];

        for ($i = 1; $i < count($rows); $i++) {
            $r = $rows[$i];
            $rowNum = $i + 1;

            $sku = trim((string) ($r[$idx['sku']] ?? ''));
            if ($sku === '') continue; // baris kosong

            $product = Product::where('sku', $sku)->first(['id']);
            if (!$product) { $skipped[] = "Baris $rowNum: SKU '$sku' tidak ditemukan"; continue; }

            $year = (int) clean_number($r[$idx['year']] ?? 0);
            if ($year < 2020 || $year > 2099) { $skipped[] = "Baris $rowNum: Tahun '{$r[$idx['year']]}' tidak valid"; continue; }

            $rawMonth = strtolower(trim((string) ($r[$idx['month']] ?? '')));
            $month = is_numeric($rawMonth) ? (int) $rawMonth : ($monthMap[substr($rawMonth, 0, 3)] ?? 0);
            if ($month < 1 || $month > 12) { $skipped[] = "Baris $rowNum: Bulan '{$rawMonth}' tidak valid"; continue; }

            $qty = (float) clean_number($r[$idx['qty']] ?? 0);
            if ($qty <= 0) { $skipped[] = "Baris $rowNum: Qty harus lebih dari 0"; continue; }

            $notes = $idx['notes'] !== false ? trim((string) ($r[$idx['notes']] ?? '')) : null;

            ProductSalesManual::updateOrCreate(
                ['product_id' => $product->id, 'year' => $year, 'month' => $month],
                ['qty' => $qty, 'notes' => $notes ?: null]
            );
            $saved++;
        }

        $msg = "$saved baris data awal penjualan tersimpan.";
        if ($skipped) {
            $msg .= ' ' . count($skipped) . ' baris dilewati: ' . implode('; ', array_slice($skipped, 0, 8));
            if (count($skipped) > 8) $msg .= ' …';
        }

        return back()->with($saved > 0 ? 'success' : 'error', $msg);
    }
}
