<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Finance\Controllers\CashDisbursementController;
use App\Modules\Finance\Controllers\CashReceiptController;
use App\Modules\Finance\Controllers\BankTransferController;
use App\Modules\Finance\Controllers\BankReconciliationController;
use App\Modules\Finance\Controllers\MarketplaceSettlementController;
use App\Modules\Finance\Controllers\SalaryPaymentController;

Route::prefix('erp/finance/cash-bank')->name('finance.cash-bank.')->group(function () {

    // Pembayaran (3 sub-index per tipe: general / freight / refund)
    Route::prefix('disbursements')->name('disbursements.')->group(function () {
        // Landing redirect (backward compat untuk link lama yang masih pakai .index)
        Route::get('/', fn() => redirect()->route('finance.cash-bank.disbursements.general'))->name('index');

        // 3 index terpisah per tipe
        Route::get('/general', [CashDisbursementController::class, 'indexGeneral'])->name('general');
        Route::get('/freight', [CashDisbursementController::class, 'indexFreight'])->name('freight');
        Route::get('/refund',  [CashDisbursementController::class, 'indexRefund'])->name('refund');

        // Shared CRUD (form, store, update, dst tetap 1 controller method)
        Route::get('/create', [CashDisbursementController::class, 'create'])->name('create');
        Route::post('/', [CashDisbursementController::class, 'store'])->name('store');
        Route::post('/quick-store', [CashDisbursementController::class, 'quickStore'])->name('quick-store');
        Route::get('/freight-invoices', [CashDisbursementController::class, 'freightInvoices'])->name('freight-invoices');
        Route::post('/freight-import', [CashDisbursementController::class, 'freightImport'])->name('freight-import');
        Route::get('/freight-import-template', [CashDisbursementController::class, 'freightImportTemplate'])->name('freight-import-template');
        // Biaya API Biteship (upload Excel bulanan dari export "Transaksi API").
        Route::get('/api-cost', [CashDisbursementController::class, 'apiCostIndex'])->name('api-cost');
        Route::post('/api-cost/import', [CashDisbursementController::class, 'apiCostImport'])->name('api-cost-import');
        Route::get('/customer-overpay-balance/{customerId}', [CashDisbursementController::class, 'customerOverpayBalance'])->name('customer-overpay-balance');
        Route::get('/{id}/edit', [CashDisbursementController::class, 'edit'])->whereNumber('id')->name('edit');
        Route::put('/{id}', [CashDisbursementController::class, 'update'])->whereNumber('id')->name('update');
        Route::delete('/{id}', [CashDisbursementController::class, 'destroy'])->whereNumber('id')->name('destroy');
        Route::post('/{id}/post', [CashDisbursementController::class, 'post'])->whereNumber('id')->name('post');
        Route::post('/{id}/void', [CashDisbursementController::class, 'void'])->whereNumber('id')->name('void');
        Route::get('/{id}/print', [CashDisbursementController::class, 'print'])->whereNumber('id')->name('print');
        Route::get('/{id}', [CashDisbursementController::class, 'show'])->whereNumber('id')->name('show');
    });

    // Bayar Gaji (Payroll Payment)
    Route::prefix('salary-payments')->name('salary-payments.')->group(function () {
        Route::get('/',                 [SalaryPaymentController::class, 'index'])->name('index');
        Route::get('/create',           [SalaryPaymentController::class, 'create'])->name('create');
        Route::post('/',                [SalaryPaymentController::class, 'store'])->name('store');
        Route::post('/preview',         [SalaryPaymentController::class, 'preview'])->name('preview');
        Route::post('/bulk-from-slips', [SalaryPaymentController::class, 'bulkFromSlips'])->name('bulk-from-slips');
        Route::post('/quick-pay',       [SalaryPaymentController::class, 'quickPay'])->name('quick-pay');
        Route::get('/{id}/edit',        [SalaryPaymentController::class, 'edit'])->whereNumber('id')->name('edit');
        Route::put('/{id}',             [SalaryPaymentController::class, 'update'])->whereNumber('id')->name('update');
        Route::delete('/{id}',          [SalaryPaymentController::class, 'destroy'])->whereNumber('id')->name('destroy');
        Route::post('/{id}/post',       [SalaryPaymentController::class, 'post'])->whereNumber('id')->name('post');
        Route::post('/{id}/void',       [SalaryPaymentController::class, 'void'])->whereNumber('id')->name('void');
        Route::get('/{id}',             [SalaryPaymentController::class, 'show'])->whereNumber('id')->name('show');
    });

    // Penerimaan (2 sub-index per tipe: general / supplier_refund)
    Route::prefix('receipts')->name('receipts.')->group(function () {
        // Landing redirect (backward compat untuk link lama)
        Route::get('/', fn() => redirect()->route('finance.cash-bank.receipts.general'))->name('index');

        // 2 index terpisah per tipe
        Route::get('/general', [CashReceiptController::class, 'indexGeneral'])->name('general');
        Route::get('/refund',  [CashReceiptController::class, 'indexRefund'])->name('refund');

        // Shared CRUD
        Route::get('/create', [CashReceiptController::class, 'create'])->name('create');
        Route::post('/', [CashReceiptController::class, 'store'])->name('store');
        Route::post('/quick-store', [CashReceiptController::class, 'quickStore'])->name('quick-store');
        Route::get('/supplier-overpay-balance/{supplierId}', [CashReceiptController::class, 'supplierOverpayBalance'])->name('supplier-overpay-balance');
        Route::get('/{id}/edit', [CashReceiptController::class, 'edit'])->whereNumber('id')->name('edit');
        Route::put('/{id}', [CashReceiptController::class, 'update'])->whereNumber('id')->name('update');
        Route::delete('/{id}', [CashReceiptController::class, 'destroy'])->whereNumber('id')->name('destroy');
        Route::post('/{id}/post', [CashReceiptController::class, 'post'])->whereNumber('id')->name('post');
        Route::post('/{id}/void', [CashReceiptController::class, 'void'])->whereNumber('id')->name('void');
        Route::get('/{id}/print', [CashReceiptController::class, 'print'])->whereNumber('id')->name('print');
        Route::get('/{id}', [CashReceiptController::class, 'show'])->whereNumber('id')->name('show');
    });

    // Bank Transfer
    Route::prefix('transfers')->name('transfers.')->group(function () {
        Route::get('/', [BankTransferController::class, 'index'])->name('index');
        Route::get('/create', [BankTransferController::class, 'create'])->name('create');
        Route::post('/', [BankTransferController::class, 'store'])->name('store');
        Route::get('/{id}/edit', [BankTransferController::class, 'edit'])->whereNumber('id')->name('edit');
        Route::put('/{id}', [BankTransferController::class, 'update'])->whereNumber('id')->name('update');
        Route::delete('/{id}', [BankTransferController::class, 'destroy'])->whereNumber('id')->name('destroy');
        Route::post('/{id}/post', [BankTransferController::class, 'post'])->whereNumber('id')->name('post');
        Route::post('/{id}/void', [BankTransferController::class, 'void'])->whereNumber('id')->name('void');
        Route::get('/{id}/print', [BankTransferController::class, 'print'])->whereNumber('id')->name('print');
        Route::get('/{id}', [BankTransferController::class, 'show'])->whereNumber('id')->name('show');
    });

    // Bank Reconciliation
    Route::prefix('reconciliations')->name('reconciliations.')->group(function () {
        Route::get('/', [BankReconciliationController::class, 'index'])->name('index');
        Route::get('/create', [BankReconciliationController::class, 'create'])->name('create');
        Route::post('/', [BankReconciliationController::class, 'store'])->name('store');
        Route::get('/{id}/edit', [BankReconciliationController::class, 'edit'])->whereNumber('id')->name('edit');
        Route::put('/{id}', [BankReconciliationController::class, 'update'])->whereNumber('id')->name('update');
        Route::delete('/{id}', [BankReconciliationController::class, 'destroy'])->whereNumber('id')->name('destroy');
        Route::post('/{id}/void', [BankReconciliationController::class, 'void'])->whereNumber('id')->name('void');
        Route::get('/{id}', [BankReconciliationController::class, 'show'])->whereNumber('id')->name('show');
    });

    // Marketplace Settlement
    Route::prefix('settlements')->name('settlements.')->group(function () {
        Route::get('/', [MarketplaceSettlementController::class, 'index'])->name('index');
        Route::get('/create', [MarketplaceSettlementController::class, 'create'])->name('create');
        Route::post('/', [MarketplaceSettlementController::class, 'store'])->name('store');
        Route::delete('/{id}', [MarketplaceSettlementController::class, 'destroy'])->whereNumber('id')->name('destroy');
        Route::post('/{id}/post', [MarketplaceSettlementController::class, 'post'])->whereNumber('id')->name('post');
        Route::post('/{id}/retry-match', [MarketplaceSettlementController::class, 'retryMatch'])->whereNumber('id')->name('retry-match');
        Route::post('/{id}/delete-unmatched', [MarketplaceSettlementController::class, 'deleteUnmatched'])->whereNumber('id')->name('delete-unmatched');
        Route::post('/{id}/void', [MarketplaceSettlementController::class, 'void'])->whereNumber('id')->name('void');
        Route::get('/{id}', [MarketplaceSettlementController::class, 'show'])->whereNumber('id')->name('show');
    });
});
