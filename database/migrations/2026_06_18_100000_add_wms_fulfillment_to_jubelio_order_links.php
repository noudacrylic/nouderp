<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flag & hasil rantai fulfillment WMS Jubelio (pick → pack → faktur → resi) yang
 * dijalankan dari "Pemrosesan Pesanan". Kolom ini TERPISAH dari flag sinkron
 * (dp_posted/sj_created/invoice_posted) supaya rantai WMS (dijalankan dari UI) dan
 * sinkron pesanan (cron/webhook) tidak saling menimpa. Tiap flag = penanda step
 * selesai → re-klik "Proses" melanjutkan dari step yang gagal, bukan mengulang.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('jubelio_order_links', function (Blueprint $table) {
            $table->boolean('j_ready_to_pick')->default(false)->after('return_created');
            $table->boolean('j_picklist_done')->default(false)->after('j_ready_to_pick');
            $table->boolean('j_packed')->default(false)->after('j_picklist_done');
            $table->boolean('j_invoice_done')->default(false)->after('j_packed');
            $table->unsignedBigInteger('j_invoice_id')->nullable()->after('j_invoice_done'); // hasil create-invoice (faktur Jubelio)
            $table->boolean('awb_requested')->default(false)->after('j_invoice_id');
            $table->string('tracking_no')->nullable()->after('awb_requested');               // resi/AWB dari kurir marketplace
            $table->string('shipper')->nullable()->after('tracking_no');                      // nama kurir
            $table->text('j_label_url')->nullable()->after('shipper');                        // cache URL label resi (report Jubelio)
            $table->text('j_faktur_url')->nullable()->after('j_label_url');                   // cache URL faktur
            $table->text('wms_last_error')->nullable()->after('j_faktur_url');                // error step terakhir (≠ last_error sinkron)
            $table->timestamp('wms_completed_at')->nullable()->after('wms_last_error');       // resi berhasil didapat
        });
    }

    public function down(): void
    {
        Schema::table('jubelio_order_links', function (Blueprint $table) {
            $table->dropColumn([
                'j_ready_to_pick', 'j_picklist_done', 'j_packed', 'j_invoice_done',
                'j_invoice_id', 'awb_requested', 'tracking_no', 'shipper',
                'j_label_url', 'j_faktur_url', 'wms_last_error', 'wms_completed_at',
            ]);
        });
    }
};
