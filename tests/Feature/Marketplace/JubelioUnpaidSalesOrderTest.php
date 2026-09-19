<?php

namespace Tests\Feature\Marketplace;

use App\Core\Accounting\Account;
use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\StockLayer;
use App\Core\Inventory\StockReservation;
use App\Core\Inventory\Warehouse;
use App\Core\Period\AccountingPeriod;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\MarketplaceConfig;
use App\Models\SalesInvoice;
use App\Modules\Marketplace\Jubelio\Models\JubelioSetting;
use App\Modules\Marketplace\Jubelio\Services\JubelioClient;
use App\Modules\Marketplace\Jubelio\Services\JubelioOrderSyncService;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesDeliveryItem;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Pesanan marketplace BELUM DIBAYAR harus langsung jadi SO (reservasi stok ERP = Jubelio)
 * TANPA DP, lalu di-void otomatis dengan alasan "Belum dibayar" bila dibatalkan/kedaluwarsa
 * di marketplace. Tujuannya: stok "Dipesan" ERP sejajar dgn reservasi Jubelio supaya selisih
 * stok mudah dilacak.
 *
 * JubelioClient di-mock (binding container) agar deterministik tanpa HTTP.
 */
class JubelioUnpaidSalesOrderTest extends TestCase
{
    use RefreshDatabase;

    private const SO_ID = 777;

    private int $customerId;
    private int $warehouseId;
    private int $holdAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $warehouse = Warehouse::firstOrCreate(['name' => 'Gudang Test']);
        $this->warehouseId = $warehouse->id;

        $customer = Customer::create([
            'code' => 'CUST-MP', 'name' => 'Shopee Official', 'is_marketplace' => true, 'is_active' => true,
        ]);
        $this->customerId = $customer->id;

        // Produk dgn jubelio_item_id agar resolveProduct cocok langsung (tanpa panggil getItem).
        Product::create([
            'sku' => 'AKR-001', 'name' => 'Akrilik A4', 'sale_type' => 'ready', 'base_unit' => 'pcs',
            'jubelio_item_id' => 2001, 'base_price' => 25000, 'is_active' => true, 'is_sellable' => true,
        ]);

        $s = JubelioSetting::find(1) ?: new JubelioSetting();
        $s->forceFill([
            'id'                   => 1,
            'username'             => 'a@b.com',
            'password'             => 'secret',
            'is_active'            => true,
            'base_url'             => JubelioSetting::DEFAULT_BASE_URL,
            'default_location_id'  => 1,
            'default_customer_id'  => $this->customerId,
            'default_warehouse_id' => $this->warehouseId,
        ])->save();
    }

    /** Detail pesanan Jubelio (2 pcs AKR-001) dengan flag pembayaran/pembatalan yang dapat diatur. */
    private function orderDetail(array $overrides = []): array
    {
        return array_merge([
            'salesorder_id'    => self::SO_ID,
            'salesorder_no'    => 'SP-UNPAID-1',
            'store_name'       => 'Shopee',
            'customer_name'    => 'Budi Pembeli',
            'transaction_date' => now()->toDateString(),
            'grand_total'      => 50000,
            'shipping_cost'    => 0,
            'is_paid'          => false,
            'items'            => [
                ['item_id' => 2001, 'item_code' => 'AKR-001', 'qty' => 2, 'price' => 25000, 'amount' => 50000],
            ],
        ], $overrides);
    }

    /**
     * Bind JubelioClient palsu yang membalas getOrder() dgn $details berurutan (satu per
     * pemanggilan), lalu kembalikan service yang memakai client itu.
     */
    private function syncServiceReturning(array ...$details): JubelioOrderSyncService
    {
        $client = Mockery::mock(JubelioClient::class);
        $client->shouldReceive('isReady')->andReturn(true);
        $responses = array_map(fn ($d) => ['success' => true, 'status' => 200, 'data' => $d, 'error' => null], $details);
        $client->shouldReceive('getOrder')->andReturnValues($responses);
        // Tahap D: begitu barang keluar, ERP menyuruh Jubelio menerbitkan fakturnya sendiri
        // supaya stok di sana ikut terpotong. Tak diuji di sini, cukup dijawab sukses.
        $client->shouldReceive('postCreateInvoice')->andReturn(['success' => true, 'data' => ['id' => 9001]]);
        $this->app->instance(JubelioClient::class, $client);

        return $this->app->make(JubelioOrderSyncService::class);
    }

    public function test_unpaid_order_creates_confirmed_so_with_reservation_but_no_dp(): void
    {
        $link = $this->syncServiceReturning($this->orderDetail())->syncOrderById(self::SO_ID);

        // SO dibuat & confirmed.
        $this->assertSame(1, SalesOrder::count());
        $so = SalesOrder::first();
        $this->assertSame('confirmed', $so->status);
        $this->assertNotNull($link->sales_order_id);
        $this->assertSame($so->id, (int) $link->sales_order_id);

        // Stok ter-reserve sebesar qty pesanan.
        $res = StockReservation::where('sales_order_id', $so->id)->where('status', 'active')->get();
        $this->assertCount(1, $res);
        $this->assertEqualsWithDelta(2.0, (float) $res->first()->qty, 0.001);

        // Belum dibayar → TIDAK ada DP & tidak ada pembayaran.
        $this->assertFalse((bool) $link->dp_posted);
        $this->assertNull($link->customer_payment_id);
        $this->assertSame(0, CustomerPayment::count());
    }

    public function test_unpaid_sync_is_idempotent_no_duplicate_so_or_reservation(): void
    {
        $svc = $this->syncServiceReturning($this->orderDetail(), $this->orderDetail());
        $svc->syncOrderById(self::SO_ID);
        $svc->syncOrderById(self::SO_ID);

        $this->assertSame(1, SalesOrder::count(), 'tidak boleh ada SO dobel');
        $this->assertSame(1, StockReservation::where('status', 'active')->count(), 'reservasi tidak boleh dobel');
    }

    public function test_canceled_unpaid_order_auto_voids_so_with_reason_belum_dibayar(): void
    {
        // 1) Belum dibayar → SO + reservasi.  2) Dibatalkan di marketplace → auto-void.
        $svc = $this->syncServiceReturning($this->orderDetail(), $this->orderDetail(['is_canceled' => true]));

        $link = $svc->syncOrderById(self::SO_ID);
        $soId = (int) $link->sales_order_id;
        $this->assertGreaterThan(0, $soId);

        $svc->syncOrderById(self::SO_ID);

        $so = SalesOrder::find($soId);
        $this->assertSame('void', $so->status, 'SO belum-bayar yang dibatalkan harus di-void otomatis');
        $this->assertSame(0, StockReservation::where('sales_order_id', $soId)->where('status', 'active')->count(),
            'reservasi stok harus dilepas saat void');

        $link->refresh();
        $this->assertSame('canceled', $link->last_status);
        $this->assertSame('Belum dibayar', $link->cancel_reason);
    }

    /**
     * Barang SUDAH keluar gudang lalu pesanan dibatalkan di marketplace (mis. paket hilang):
     * SO/Surat Jalan TIDAK boleh di-void — itu kasus retur. Mem-void-nya akan membalik omzet,
     * memasukkan kembali stok yang tak pernah kembali, dan membunuh faktur yang dibutuhkan
     * agar dana kompensasi marketplace bisa dicocokkan saat rekonsiliasi.
     */
    public function test_canceled_order_after_shipment_opens_return_case_instead_of_void(): void
    {
        $svc = $this->syncServiceReturning($this->orderDetail(), $this->orderDetail(['is_canceled' => true]));

        $link = $svc->syncOrderById(self::SO_ID);
        $soId = (int) $link->sales_order_id;
        $this->assertGreaterThan(0, $soId);

        $this->postDeliveryFor($soId, 2);

        $svc->syncOrderById(self::SO_ID);

        $so = SalesOrder::find($soId);
        $this->assertNotSame('void', $so->status, 'SO yang barangnya sudah keluar tidak boleh di-void');

        $this->assertSame('posted', SalesDelivery::where('sales_order_id', $soId)->first()->status,
            'Surat Jalan harus tetap posted supaya stok tetap tercatat keluar');

        $return = SalesReturn::where('sales_order_id', $soId)->first();
        $this->assertNotNull($return, 'kasus retur harus dibuka');
        $this->assertSame('baru', $return->stage);
        $this->assertSame('draft', $return->status, 'retur lahir sebagai draft: belum ada jurnal & stok');
        $this->assertEqualsWithDelta(2.0, (float) $return->items->first()->qty, 0.001,
            'qty retur mengikuti yang benar-benar terkirim');
        $this->assertSame('damaged', $return->items->first()->condition,
            'barang tidak di tangan kita → kondisi damaged agar tidak masuk stok saat di-post');

        // Jubelio tidak memberi tahu kasusnya apa (paket hilang? gagal kirim? pembeli batal?),
        // jadi retur lahir TANPA jenis dan menunggu didefinisikan admin di tab "Retur Baru".
        $this->assertNull($return->return_type, 'jenis retur wajib kosong — belum didefinisikan');
        $this->assertFalse($return->skipsReversal(), 'tanpa jenis & hasil klaim, retur membalik jurnal seperti biasa');

        $link->refresh();
        $this->assertSame('canceled', $link->last_status);
        $this->assertTrue((bool) $link->return_created);
    }

    /** Sinkron berulang atas pembatalan yang sama tak boleh melahirkan kasus retur dobel. */
    public function test_canceled_order_after_shipment_is_idempotent(): void
    {
        $svc = $this->syncServiceReturning(
            $this->orderDetail(),
            $this->orderDetail(['is_canceled' => true]),
            $this->orderDetail(['is_canceled' => true]),
        );

        $link = $svc->syncOrderById(self::SO_ID);
        $soId = (int) $link->sales_order_id;
        $this->postDeliveryFor($soId, 2);

        $svc->syncOrderById(self::SO_ID);
        $svc->syncOrderById(self::SO_ID);

        $this->assertSame(1, SalesReturn::where('sales_order_id', $soId)->count(),
            'kasus retur tidak boleh dobel');
    }

    /**
     * Aturan alur marketplace: biaya admin di SO hanya ESTIMASI, pemotongan sesungguhnya
     * terjadi di FAKTUR. Maka SO (dan karenanya DP) memakai nilai KOTOR — itulah yang
     * membuat retur menutup rapi, karena retur atas SO dijurnal sebesar nilai kotor.
     * Sebelum aturan ini, SO memakai grand_total Jubelio yang sudah bersih sehingga retur
     * meninggalkan selisih sebesar biaya admin menggantung di Uang Muka & Saldo Ditahan.
     */
    public function test_paid_order_stores_gross_on_so_and_pays_dp_gross(): void
    {
        // Jubelio melaporkan potongan: subtotal 50.000 − grand_total 44.000 = fee 6.000.
        $this->prepareMarketplaceAccounting();

        $link = $this->syncServiceReturning($this->orderDetail([
            'grand_total' => 44000,
            'is_paid'     => true,
        ]))->syncOrderById(self::SO_ID);

        $so = SalesOrder::find($link->sales_order_id);
        $this->assertEqualsWithDelta(50000.0, (float) $so->grand_total, 0.01,
            'grand_total SO wajib KOTOR (sebelum biaya admin)');
        $this->assertEqualsWithDelta(6000.0, (float) $so->marketplace_fee, 0.01,
            'biaya admin tetap dicatat di SO sebagai estimasi');

        // DP = grand_total SO = kotor, masuk ke akun Saldo Ditahan marketplace.
        $payment = CustomerPayment::first();
        $this->assertNotNull($payment, 'pesanan lunas wajib memicu DP');
        $this->assertEqualsWithDelta(50000.0, (float) $payment->amount, 0.01,
            'DP marketplace wajib sebesar nilai KOTOR');
        $this->assertSame($this->holdAccountId, (int) $payment->cash_account_id);
    }

    /** Akun + periode + config marketplace minimal supaya DP marketplace bisa diposting. */
    private function prepareMarketplaceAccounting(): void
    {
        AccountingPeriod::firstOrCreate(
            ['year' => (int) now()->year, 'month' => (int) now()->month],
            [
                'start_date' => now()->startOfMonth()->toDateString(),
                'end_date'   => now()->endOfMonth()->toDateString(),
                'status'     => 'open',
            ]
        );

        // firstOrCreate: sebagian akun ini mungkin sudah ada dari bagan akun bawaan.
        $akun = function (string $code, string $name, string $type, string $normal, string $cat) {
            return Account::firstOrCreate(['code' => $code], [
                'name' => $name, 'type' => $type,
                'normal_balance' => $normal, 'account_category' => $cat, 'is_active' => true,
            ])->id;
        };

        $akun('1101', 'Kas', 'asset', 'debit', 'cash');
        $akun('1120', 'Piutang Usaha', 'asset', 'debit', 'receivable');
        $akun('2105', 'Uang Muka Customer', 'liability', 'credit', 'payable');
        $akun('2106', 'Kelebihan Bayar Customer', 'liability', 'credit', 'payable');
        $akun('1130', 'Persediaan', 'asset', 'debit', 'inventory');
        $akun('1203', 'Titipan Ongkir', 'asset', 'debit', 'cash');
        $akun('4001', 'Penjualan', 'revenue', 'credit', 'revenue');
        $akun('4002', 'Pendapatan Tambahan', 'revenue', 'credit', 'revenue');
        $akun('4003', 'Diskon Penjualan', 'revenue', 'debit', 'revenue');
        $akun('5001', 'Harga Pokok Penjualan', 'expense', 'debit', 'expense');

        $this->holdAccountId = $akun('1202', 'Saldo Ditahan Shopee', 'asset', 'debit', 'cash');
        $walletId           = $akun('1111', 'Wallet Shopee', 'asset', 'debit', 'cash');
        $feeId              = $akun('6101', 'Beban Admin Marketplace', 'expense', 'debit', 'expense');

        // Stok + lapisan FIFO: tanpa ini Surat Jalan memakai shipDeferred (HPP belum
        // diketahui) dan posting faktur menolak dgn "COGS belum tersedia".
        $produkId = Product::where('sku', 'AKR-001')->value('id');
        ProductStock::create([
            'product_id' => $produkId, 'warehouse_id' => $this->warehouseId, 'qty_on_hand' => 10,
        ]);
        StockLayer::create([
            'product_id' => $produkId, 'warehouse_id' => $this->warehouseId,
            'qty_in' => 10, 'qty_remaining' => 10, 'unit_cost' => 9000,
            'source_type' => 'purchase', 'source_id' => 0,
        ]);

        MarketplaceConfig::create([
            'customer_id'                => $this->customerId,
            'admin_fee_percent'          => 0,
            'admin_fee_fixed'            => 0,
            'is_active'                  => true,
            'account_receivable_hold_id' => $this->holdAccountId,
            'account_fee_id'             => $feeId,
            'account_wallet_id'          => $walletId,
        ]);
    }

    /**
     * FAKTUR TERBIT SAAT PENGIRIMAN, bukan saat pesanan selesai.
     *
     * Pesanan marketplace yang berakhir retur / paket hilang tidak pernah berstatus "selesai",
     * jadi dulu fakturnya tak pernah terbit: omzet tak diakui dan HPP tak pernah masuk buku
     * besar (Surat Jalan tidak menjurnal apa pun). Sekarang faktur menyusul barang keluar.
     *
     * Nilainya KOTOR & tanpa biaya admin — potongan marketplace belum ada saat barang keluar.
     */
    public function test_faktur_terbit_saat_pengiriman_dengan_nilai_kotor_tanpa_biaya_admin(): void
    {
        $this->prepareMarketplaceAccounting();

        // Jubelio melaporkan potongan 6.000 (50.000 → 44.000) & pesanan sudah dibayar + berresi.
        $link = $this->syncServiceReturning($this->orderDetail([
            'grand_total'     => 44000,
            'is_paid'         => true,
            'tracking_number' => 'SPX123',
        ]))->syncOrderById(self::SO_ID);

        $this->assertTrue((bool) $link->sj_created, 'Surat Jalan harus terbit');
        $this->assertTrue((bool) $link->invoice_posted, 'faktur harus ikut terbit saat pengiriman');

        $inv = SalesInvoice::where('sales_order_id', $link->sales_order_id)->firstOrFail();
        $this->assertTrue((bool) $inv->fee_at_settlement, 'ditandai konvensi baru');
        $this->assertEqualsWithDelta(0.0, (float) $inv->marketplace_fee, 0.01,
            'biaya admin belum dibebankan saat barang keluar');
        $this->assertEqualsWithDelta(50000.0, (float) $inv->grand_total, 0.01,
            'nilai faktur = nilai KOTOR, sama dengan SO');

        // HPP & Persediaan masuk buku besar — inilah yang dulu tidak pernah terjadi.
        $jurnal = \App\Core\Journal\Journal::where('reference_type', 'sales_invoice')
            ->where('reference_id', $inv->id)->where('status', '!=', 'void')->firstOrFail();
        $hppId = (int) Account::where('code', '5001')->value('id');
        $hpp = (float) \Illuminate\Support\Facades\DB::table('journal_lines')
            ->where('journal_id', $jurnal->id)->where('account_id', $hppId)->sum('debit');
        $this->assertGreaterThan(0, $hpp, 'HPP harus terjurnal saat faktur terbit');

        // Settlement BELUM jalan — pesanannya belum selesai.
        $this->assertFalse((bool) $inv->marketplace_processed,
            'saldo ditahan baru dilepas saat pesanan selesai, bukan saat kirim');
    }

    /** Pesanan selesai menyusul → barulah biaya admin dibebankan & saldo ditahan dilepas. */
    public function test_settlement_menyusul_saat_pesanan_selesai(): void
    {
        $this->prepareMarketplaceAccounting();

        $kirim  = $this->orderDetail(['grand_total' => 44000, 'is_paid' => true, 'tracking_number' => 'SPX123']);
        $selesai = $this->orderDetail(['grand_total' => 44000, 'is_paid' => true, 'tracking_number' => 'SPX123',
            'marked_as_complete' => true]);

        $svc  = $this->syncServiceReturning($kirim, $selesai);
        $link = $svc->syncOrderById(self::SO_ID);
        $svc->syncOrderById(self::SO_ID);

        $inv = SalesInvoice::where('sales_order_id', $link->sales_order_id)->firstOrFail();
        $this->assertTrue((bool) $inv->marketplace_processed, 'settlement harus jalan setelah selesai');
        $this->assertEqualsWithDelta(6000.0, (float) $inv->marketplace_fee, 0.01,
            'biaya admin dibebankan di settlement, dicatat di faktur untuk rekonsiliasi');
        $this->assertEqualsWithDelta(50000.0, (float) $inv->grand_total, 0.01,
            'grand_total tetap kotor, tidak diturunkan oleh fee');
    }

    /**
     * Pesanan yang sudah berfaktur lalu dibatalkan → kasus retur menempel ke FAKTUR, bukan SO.
     *
     * Ini yang membuat jurnal returnya benar: retur atas faktur membalik Penjualan dan
     * memindahkan HPP yang memang sudah dibukukan faktur. Retur atas SO mengkredit HPP yang
     * belum tentu pernah ada — dulu itulah yang membuat HPP jadi minus.
     */
    public function test_retur_menempel_ke_faktur_bila_pesanan_sudah_berfaktur(): void
    {
        $this->prepareMarketplaceAccounting();

        $kirim = $this->orderDetail(['grand_total' => 44000, 'is_paid' => true, 'tracking_number' => 'SPX123']);
        $batal = $this->orderDetail(['grand_total' => 44000, 'is_paid' => true, 'tracking_number' => 'SPX123',
            'is_canceled' => true]);

        $svc  = $this->syncServiceReturning($kirim, $batal);
        $link = $svc->syncOrderById(self::SO_ID);

        $inv = SalesInvoice::where('sales_order_id', $link->sales_order_id)->firstOrFail();

        $svc->syncOrderById(self::SO_ID);

        $return = SalesReturn::where('customer_id', $inv->customer_id)->latest('id')->firstOrFail();
        $this->assertSame($inv->id, (int) $return->invoice_id, 'retur harus menempel ke faktur');
        $this->assertNull($return->sales_order_id, 'bukan ke SO');
        $this->assertSame('baru', $return->stage);
        $this->assertSame('draft', $return->status);

        $so = SalesOrder::find($link->sales_order_id);
        $this->assertNotSame('void', $so->status, 'barang sudah keluar → tidak boleh di-void');
    }

    /**
     * Tombol "Proses Pesanan" harus menghasilkan Surat Jalan DAN fakturnya sekaligus.
     *
     * Tanpa ini operator menekan Proses, melihat pesanan terproses, tapi fakturnya baru muncul
     * saat cron menyinkron pesanan itu lagi — omzet & HPP tertunda tanpa alasan.
     */
    public function test_tombol_proses_pesanan_menerbitkan_surat_jalan_sekaligus_faktur(): void
    {
        $this->prepareMarketplaceAccounting();

        // Belum ada resi → Surat Jalan belum terbit lewat sinkron biasa.
        $svc  = $this->syncServiceReturning($this->orderDetail(['grand_total' => 44000, 'is_paid' => true]));
        $link = $svc->syncOrderById(self::SO_ID);

        $this->assertFalse((bool) $link->sj_created, 'tanpa resi, sinkron belum membuat Surat Jalan');
        $this->assertSame(0, SalesInvoice::count(), 'dan belum ada faktur');

        $svc->createDeliveryOnProcess($link);

        $link->refresh();
        $this->assertTrue((bool) $link->sj_created, 'tombol Proses membuat Surat Jalan');
        $this->assertTrue((bool) $link->invoice_posted, 'sekaligus fakturnya');

        $inv = SalesInvoice::where('sales_order_id', $link->sales_order_id)->firstOrFail();
        $this->assertTrue((bool) $inv->fee_at_settlement);
        $this->assertEqualsWithDelta(50000.0, (float) $inv->grand_total, 0.01, 'nilai kotor');
        $this->assertEqualsWithDelta(0.0, (float) $inv->marketplace_fee, 0.01, 'fee menyusul saat selesai');
    }

    /**
     * Surat Jalan posted buatan langsung — cukup untuk menguji aturan pembatalan tanpa
     * menyeret seluruh rantai fulfillment (pick/resi/faktur) ke dalam tes ini.
     */
    private function postDeliveryFor(int $soId, float $qty): SalesDelivery
    {
        $so = SalesOrder::with('items')->find($soId);

        $delivery = SalesDelivery::create([
            'sales_order_id'  => $soId,
            'delivery_number' => 'DO-TEST-' . $soId,
            'warehouse_id'    => $this->warehouseId,
            'delivery_method' => 'kurir',
            'delivery_date'   => now()->toDateString(),
            'status'          => 'posted',
        ]);

        SalesDeliveryItem::create([
            'sales_delivery_id'    => $delivery->id,
            'sales_order_item_id'  => $so->items->first()->id,
            'product_id'           => $so->items->first()->product_id,
            'qty'                  => $qty,
        ]);

        return $delivery;
    }
}
