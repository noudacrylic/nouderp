<?php

namespace Tests\Feature\CRM;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Menyusun Sales Order langsung dari layar chat.
 *
 * Yang dijaga di sini adalah hal-hal yang membuat pesanan dari chat bisa
 * dipercaya: hasilnya WAJIB draft (bukan pesanan yang terlanjur mengikat),
 * hitungannya harus sama persis dengan SO lewat form biasa, dan chat dari
 * nomor asing — keadaan yang paling sering — harus tetap bisa jadi pesanan.
 */
class BuatSoDariChatTest extends TestCase
{
    use RefreshDatabase;

    private int $warehouseId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['crm.dry_run' => true]);
        app(ChatManager::class)->fake()->reset();
        Storage::fake('local');

        $this->warehouseId = Warehouse::create([
            'name' => 'Gudang Jual', 'is_sellable' => true, 'is_active' => true,
        ])->id;

        $this->productId = Product::create([
            'sku' => 'AKR-01', 'name' => 'Meja Akrilik', 'sale_type' => 'ready',
            'base_unit' => 'pcs', 'base_price' => 100000, 'is_active' => true, 'is_sellable' => true,
        ])->id;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function percakapan(): CrmConversation
    {
        $p = CrmConversation::findOrCreateFor('628998844666');

        $p->forceFill([
            'window_expires_at' => now()->addHours(5),
            'last_message_at'   => now(),
        ])->save();

        return $p;
    }

    private function muatan(array $ganti = []): array
    {
        return array_replace_recursive([
            'warehouse_id'  => $this->warehouseId,
            'customer_name' => 'Pak Budi',
            'items' => [[
                'product_id'     => $this->productId,
                'qty'            => 2,
                'unit_price'     => 100000,
                'discount_type'  => 'nominal',
                'discount_value' => 0,
            ]],
        ], $ganti);
    }

    public function test_pesanan_dari_chat_lahir_sebagai_draft(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.buat-so', $this->percakapan()), $this->muatan())
            ->assertOk()
            ->assertJsonPath('success', true);

        $so = SalesOrder::first();

        $this->assertSame(
            'draft',
            $so->status,
            'SO dari chat harus draft — pesanan yang lahir dari percakapan masih berubah, '
            . 'dan yang terlanjur di-post cuma bisa dibatalkan lewat void.'
        );
        $this->assertEquals(200000, $so->grand_total);
    }

    /**
     * Chat dari nomor asing (Lead) adalah keadaan NORMAL — kebanyakan pesanan
     * lahir dari situ. Pelanggan barunya dibuat dan langsung ditautkan, supaya
     * chat berikutnya dari nomor itu mendarat pada riwayat yang sama.
     */
    public function test_lead_melahirkan_pelanggan_baru_dan_chatnya_ikut_tertaut(): void
    {
        $percakapan = $this->percakapan();
        $this->assertNull($percakapan->customer_id);

        $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.buat-so', $percakapan), $this->muatan())
            ->assertOk();

        $pelanggan = Customer::where('name', 'Pak Budi')->first();

        $this->assertNotNull($pelanggan);
        $this->assertSame('628998844666', $pelanggan->phone);
        $this->assertSame($pelanggan->id, $percakapan->fresh()->customer_id);
    }

    /** Tanpa nama & tanpa pelanggan terpilih, pesanan ditolak — bukan dibuat diam-diam. */
    public function test_tanpa_pelanggan_ditolak_dengan_alasan_yang_terbaca(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.buat-so', $this->percakapan()), $this->muatan(['customer_name' => '']))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', fn ($e) => str_contains((string) $e, 'pelanggan'));

        $this->assertSame(0, SalesOrder::count());
        $this->assertSame(0, Customer::count());
    }

    /**
     * Diskon nominal per baris = potongan PER UNIT, sama seperti form SO biasa.
     *
     * Kalau di sini ia diperlakukan sebagai potongan per baris, nota yang sama
     * menghasilkan total berbeda tergantung dibuat dari chat atau dari menu
     * Sales — dan selisihnya baru ketahuan saat pelanggan protes.
     */
    public function test_diskon_nominal_dihitung_per_unit_seperti_form_biasa(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.buat-so', $this->percakapan()), $this->muatan([
                'items' => [['discount_value' => 5000]],
            ]))
            ->assertOk();

        // 2 × 100.000 = 200.000, diskon 5.000/unit × 2 = 10.000.
        $this->assertEquals(190000, SalesOrder::first()->grand_total);
    }

    /** Ongkir titipan dari tab Ongkir ikut tersimpan lengkap dengan kode kurirnya. */
    public function test_ongkir_dari_tab_ongkir_terbawa_beserta_kode_kurir(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.buat-so', $this->percakapan()), $this->muatan([
                'shipping_gross'          => 22000,
                'shipping_discount_type'  => 'nominal',
                'shipping_discount_value' => 5000,
                'courier_name'            => 'JNE · REG',
                'shipping_provider'       => 'jubelio_shipment',
                'shipping_courier_code'   => '12',
                'shipping_service_code'   => '34',
            ]))
            ->assertOk();

        $so = SalesOrder::first();

        $this->assertEquals(22000, $so->shipping_gross);
        $this->assertEquals(17000, $so->shipping_cost);
        // Kode provider & layanan WAJIB tersimpan: itulah yang dibaca saat resi
        // dipesan nanti. Tanpa itu booking jatuh ke provider yang salah.
        $this->assertSame('jubelio_shipment', $so->shipping_provider);
        $this->assertSame('34', $so->shipping_service_code);
        $this->assertEquals(217000, $so->grand_total);
    }

    /** Batas DP ikut tersimpan — bawaannya 50% di layar, tapi tetap bisa diubah. */
    public function test_minimal_dp_tersimpan_di_so(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.buat-so', $this->percakapan()), $this->muatan([
                'min_dp_percent' => 30,
            ]))
            ->assertOk();

        $this->assertEquals(30, SalesOrder::first()->min_dp_percent);
    }

    public function test_diskon_belanja_mengurangi_total(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.buat-so', $this->percakapan()), $this->muatan([
                'global_discount_type'  => 'percent',
                'global_discount_value' => 10,
            ]))
            ->assertOk();

        $this->assertEquals(180000, SalesOrder::first()->grand_total);
    }

    /* ------------------------------------------------------ draft keranjang */

    /**
     * Alamat & ongkir yang sudah dicari WAJIB selamat dari muat ulang.
     *
     * Mencari kecamatan lalu menghitung ongkir adalah pekerjaan; sebelum ini
     * semuanya cuma hidup di memori browser, jadi satu kali refresh — atau
     * sekadar membuka chat lain lalu kembali — menghapusnya diam-diam dan
     * operator mengulang dari nol.
     */
    public function test_draft_ongkir_tersimpan_dan_muncul_lagi_di_layar(): void
    {
        $percakapan = $this->percakapan();

        $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.draft-pesanan', $percakapan), [
                'bagian' => 'ongkir',
                'data'   => [
                    'berat'  => 890,
                    'tujuan' => ['label' => 'Banyumanik, Semarang', 'provider' => 'jubelio_shipment'],
                ],
            ])
            ->assertOk();

        $this->assertSame(890, $percakapan->fresh()->order_draft['ongkir']['berat']);

        $this->actingAs($this->admin())
            ->get(route('crm.inbox.show', $percakapan))
            ->assertOk()
            ->assertSee('Banyumanik, Semarang');
    }

    /**
     * Dua tab menyimpan sendiri-sendiri, jadi yang datang belakangan tidak boleh
     * menghapus pekerjaan tab sebelahnya.
     */
    public function test_menyimpan_satu_bagian_tidak_menghapus_bagian_lain(): void
    {
        $percakapan = $this->percakapan();
        $admin      = $this->admin();

        $this->actingAs($admin)->postJson(route('crm.inbox.draft-pesanan', $percakapan), [
            'bagian' => 'ongkir', 'data' => ['berat' => 890],
        ])->assertOk();

        $this->actingAs($admin)->postJson(route('crm.inbox.draft-pesanan', $percakapan), [
            'bagian' => 'pesanan', 'data' => ['catatan' => 'warna hitam'],
        ])->assertOk();

        $draft = $percakapan->fresh()->order_draft;

        $this->assertSame(890, $draft['ongkir']['berat']);
        $this->assertSame('warna hitam', $draft['pesanan']['catatan']);
    }

    public function test_bagian_draft_yang_tidak_dikenal_ditolak(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.draft-pesanan', $this->percakapan()), [
                'bagian' => 'entah', 'data' => ['x' => 1],
            ])
            ->assertStatus(422);
    }

    public function test_tab_pesanan_muncul_dengan_segmen_lengkap(): void
    {
        $this->actingAs($this->admin())
            ->get(route('crm.inbox.show', $this->percakapan()))
            ->assertOk()
            ->assertSee('Buat Pesanan')
            ->assertSee('1 · Pelanggan', false)
            ->assertSee('2 · Produk', false)
            ->assertSee('3 · Ongkir', false)
            ->assertSee('4 · Catatan Pembeli', false)
            ->assertSee('5 · Lain-lain', false)
            ->assertSee('6 · Ringkasan', false)
            ->assertSee('Buat SO Draft');
    }

    /**
     * Kesepakatan dagang ikut tersimpan.
     *
     * Keep stock & tempo bukan hiasan: keduanya melonggarkan gerbang yang
     * menahan pesanan (bayar walau stok kurang, kirim sebelum dibayar). Kalau
     * disetujui lewat chat tapi tidak ikut tersimpan, pesanannya macet di
     * Pemrosesan Pesanan tanpa alasan yang terlihat.
     */
    public function test_keep_stock_dan_tempo_tersimpan_di_so(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.buat-so', $this->percakapan()), $this->muatan([
                'allow_backorder' => true,
                'is_tempo'        => true,
                'tempo_days'      => 14,
            ]))
            ->assertOk();

        $so = SalesOrder::first();

        $this->assertTrue((bool) $so->allow_backorder);
        $this->assertTrue((bool) $so->is_tempo);
        $this->assertSame(14, (int) $so->tempo_days);
    }

    /** Tempo tanpa termin tetap sah — yang hilang cuma peringatan jatuh temponya. */
    public function test_tempo_boleh_tanpa_termin(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.buat-so', $this->percakapan()), $this->muatan([
                'is_tempo' => true, 'tempo_days' => null,
            ]))
            ->assertOk();

        $so = SalesOrder::first();

        $this->assertTrue((bool) $so->is_tempo);
        $this->assertNull($so->tempo_days);
    }

    /* --------------------------------------------------------- daftar pesanan */

    /**
     * Yang pertama terlihat saat tab dibuka adalah pesanan yang SEDANG berjalan.
     *
     * Pertanyaan yang paling sering datang lewat chat bukan "saya mau pesan"
     * melainkan "pesanan saya sudah sampai mana" — dan menjawabnya tidak boleh
     * menuntut pindah menu.
     */
    public function test_daftar_pesanan_membawa_status_tahap_berjalan(): void
    {
        $percakapan = $this->percakapan();

        $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.buat-so', $percakapan), $this->muatan())
            ->assertOk();

        $daftar = $this->actingAs($this->admin())
            ->getJson(route('crm.inbox.pesanan', $percakapan))
            ->assertOk()
            ->json('pesanan');

        $this->assertCount(1, $daftar);
        $this->assertTrue($daftar[0]['draft']);
        // Draft belum jadi pesanan bagi pelanggan; menandainya "menunggu
        // pembayaran" membuat operator menagih sesuatu yang belum dikirimkan.
        $this->assertSame('Draft', $daftar[0]['status']);
        $this->assertEquals(200000, $daftar[0]['total']);
    }

    /**
     * SO yang sudah dikonfirmasi memakai status dari OrderProgressService —
     * sumber yang SAMA dengan halaman lacak yang dilihat pembeli. Kalau di sini
     * diturunkan sendiri, admin dan pembeli bisa membaca dua cerita berbeda
     * tentang pesanan yang sama.
     */
    public function test_pesanan_terkonfirmasi_memakai_status_dari_lacak_pesanan(): void
    {
        $percakapan = $this->percakapan();

        $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.buat-so', $percakapan), $this->muatan())
            ->assertOk();

        SalesOrder::first()->forceFill(['status' => 'confirmed'])->save();

        $daftar = $this->actingAs($this->admin())
            ->getJson(route('crm.inbox.pesanan', $percakapan))
            ->assertOk()
            ->json('pesanan');

        $this->assertFalse($daftar[0]['draft']);
        $this->assertSame('Pembayaran', $daftar[0]['status']);
        $this->assertSame('Menunggu pembayaran', $daftar[0]['catatan']);
    }

    /** Pesanan yang dibatalkan tidak ikut ditampilkan — bukan pekerjaan berjalan. */
    public function test_pesanan_void_tidak_muncul_di_daftar(): void
    {
        $percakapan = $this->percakapan();

        $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.buat-so', $percakapan), $this->muatan())
            ->assertOk();

        SalesOrder::first()->forceFill(['status' => 'void'])->save();

        $this->actingAs($this->admin())
            ->getJson(route('crm.inbox.pesanan', $percakapan))
            ->assertOk()
            ->assertJsonPath('pesanan', []);
    }

    /**
     * Kartu pesanan membawa BARANGNYA, gaya kartu marketplace.
     *
     * Pertanyaan lewat chat hampir selalu menyebut barangnya ("rak bolpoin saya
     * gimana"), bukan nomor SO. Kartu tanpa nama barang memaksa operator membuka
     * halaman SO satu per satu hanya untuk mencocokkan.
     */
    public function test_kartu_pesanan_membawa_daftar_barang_dan_ongkir(): void
    {
        $percakapan = $this->percakapan();

        $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.buat-so', $percakapan), $this->muatan([
                'shipping_gross'    => 22000,
                'courier_name'      => 'JNE · REG',
                'shipping_provider' => 'jubelio_shipment',
            ]))
            ->assertOk();

        $daftar = $this->actingAs($this->admin())
            ->getJson(route('crm.inbox.pesanan', $percakapan))
            ->assertOk()
            ->json('pesanan.0');

        $this->assertSame('Meja Akrilik', $daftar['items'][0]['nama']);
        // SKU ikut: nama produk sering mirip satu sama lain, dan SKU-lah yang
        // dipakai gudang & produksi untuk memastikan barangnya.
        $this->assertSame('AKR-01', $daftar['items'][0]['sku']);
        $this->assertSame('2', $daftar['items'][0]['qty']);
        $this->assertEquals(200000, $daftar['items'][0]['total']);
        $this->assertEquals(22000, $daftar['ongkir']);
        $this->assertFalse($daftar['ambil']);

        // Pintu ke halaman SO: di sanalah item, ongkir, dan kesepakatan diubah.
        // Panel chat sengaja tidak menduplikasi satu pun dari itu.
        $this->assertSame(route('sales.orders.show', SalesOrder::first()->id), $daftar['url']);
    }

    /* --------------------------------------------------------------- promo */

    /**
     * Promo dihitung oleh PromotionService — mesin yang SAMA dengan Kasir, form
     * SO, dan etalase web.
     *
     * Menghitungnya ulang khusus untuk chat akan melahirkan versi kedua yang
     * cepat berbeda aturannya, dan bedanya baru ketahuan dari pelanggan yang
     * membandingkan harga di web dengan yang ditawarkan lewat chat.
     */
    public function test_promo_produk_terbaca_dari_mesin_promo_yang_sama(): void
    {
        $promo = \App\Modules\Sales\Models\Promotion::create([
            'name' => 'Diskon Akrilik', 'type' => 'item', 'discount_type' => 'percent',
            'discount_value' => 10, 'applies_to_all' => true, 'is_active' => true, 'priority' => 1,
        ]);

        $d = $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.promo', $this->percakapan()), [
                'items'    => [['product_id' => $this->productId, 'qty' => 2, 'unit_price' => 100000]],
                'subtotal' => 200000,
            ])
            ->assertOk()
            ->json();

        $this->assertSame($promo->name, $d['item_discounts'][$this->productId]['promotion_name']);
        $this->assertSame('percent', $d['item_discounts'][$this->productId]['discount_type']);
        $this->assertEquals(10, $d['item_discounts'][$this->productId]['discount_value']);
    }

    /**
     * Promo ongkir bertingkat butuh TOTAL BELANJA sebagai syaratnya — itulah
     * alasan kotak potongan ongkir pindah ke segmen Ongkir di tab Pesanan, satu-
     * satunya layar yang tahu berapa belanjanya.
     */
    public function test_promo_ongkir_bertingkat_ikut_terhitung(): void
    {
        $promo = \App\Modules\Sales\Models\Promotion::create([
            'name' => 'Gratis Ongkir 300rb', 'type' => 'shipping', 'discount_type' => 'nominal',
            'discount_value' => 0, 'applies_to_all' => true, 'is_active' => true, 'priority' => 1,
        ]);
        $promo->tiers()->create(['min_spend' => 300000, 'discount_type' => 'nominal', 'discount_value' => 10000]);

        // Belanja di bawah ambang → belum dapat potongan.
        $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.promo', $this->percakapan()), [
                'items' => [['product_id' => $this->productId, 'qty' => 1, 'unit_price' => 100000]],
                'subtotal' => 100000, 'shipping_gross' => 22000,
            ])
            ->assertOk()
            ->assertJsonPath('shipping', null);

        // Belanja di atas ambang → potongan muncul.
        $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.promo', $this->percakapan()), [
                'items' => [['product_id' => $this->productId, 'qty' => 4, 'unit_price' => 100000]],
                'subtotal' => 400000, 'shipping_gross' => 22000,
            ])
            ->assertOk()
            ->assertJsonPath('shipping.promotion_name', 'Gratis Ongkir 300rb');
    }

    /**
     * Nama produk boleh ditimpa untuk pesanan custom, TAPI produknya tetap
     * produk yang sama — stok, HPP, dan laporan tidak ikut berubah hanya karena
     * namanya ditulis ulang di nota.
     */
    public function test_nama_produk_bisa_ditimpa_tanpa_menukar_produknya(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.buat-so', $this->percakapan()), $this->muatan([
                'items' => [['description' => 'Box Mahar 30×30 tutup emas']],
            ]))
            ->assertOk();

        $item = SalesOrder::first()->items()->first();

        $this->assertSame('Box Mahar 30×30 tutup emas', $item->description);
        $this->assertSame($this->productId, $item->product_id);
    }

    /**
     * Ambil di toko = tidak ada ongkir, walau tarifnya sempat dihitung.
     *
     * Tarif yang ikut tersimpan pada pesanan yang diambil sendiri akan menagih
     * pembeli untuk pengiriman yang tidak pernah terjadi — dan angkanya terlihat
     * sah di nota, jadi tidak ada yang mempertanyakannya.
     */
    public function test_ambil_di_toko_tidak_membawa_ongkir(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.buat-so', $this->percakapan()), $this->muatan([
                'delivery_method' => 'ambil_toko',
                'shipping_gross'  => 22000,
                'courier_name'    => 'JNE · REG',
            ]))
            ->assertOk();

        $so = SalesOrder::first();

        $this->assertSame('ambil_toko', $so->delivery_method);
        $this->assertEquals(0, $so->shipping_cost);
        $this->assertEquals(200000, $so->grand_total, 'Total tidak boleh ketambahan ongkir.');
    }

    /* ------------------------------------------------------ batas wewenang */

    /**
     * CRM hanya MEMBUAT pesanan, tidak pernah mengubah yang sudah ada.
     *
     * Inilah batas yang menjaga sistem ongkir di layar chat tetap masuk akal:
     * tarif yang dihitung di sini menempel pada pesanan yang sedang disusun,
     * dan begitu SO-nya lahir, satu-satunya jalan mengubah ongkirnya adalah
     * halaman SO — lengkap dengan aturannya (yang sudah di-post tidak bisa
     * diubah, void perlu alasan, dan seterusnya).
     *
     * Tanpa batas ini, pelanggan dengan dua pesanan berjalan punya dua tarif
     * yang bisa saling tertukar tanpa jejak.
     */
    public function test_pesanan_kedua_tidak_menyentuh_pesanan_pertama(): void
    {
        $percakapan = $this->percakapan();

        $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.buat-so', $percakapan), $this->muatan([
                'shipping_gross' => 22000,
                'courier_name'   => 'JNE · REG',
            ]))
            ->assertOk();

        $pertama = SalesOrder::first();

        // Pesanan kedua dengan ongkir & isi yang sama sekali berbeda.
        $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.buat-so', $percakapan), $this->muatan([
                'items'          => [['qty' => 5]],
                'shipping_gross' => 95000,
                'courier_name'   => 'GoSend Instant',
            ]))
            ->assertOk();

        $this->assertSame(2, SalesOrder::count(), 'Tiap kiriman melahirkan SO baru, bukan menimpa yang lama.');

        $pertama->refresh();
        $this->assertEquals(22000, $pertama->shipping_gross);
        $this->assertSame('JNE · REG', $pertama->shipping_service_name);
        $this->assertEquals(222000, $pertama->grand_total);
    }

    /**
     * Batas itu harus TERBACA di layar, bukan cuma benar di kode.
     *
     * Operator yang punya dua pesanan berjalan akan mengira menghitung ulang di
     * panel chat memperbarui ongkir salah satunya. Kalimat ini yang mencegahnya.
     */
    public function test_batas_wewenang_ongkir_tertulis_di_panel(): void
    {
        $this->actingAs($this->admin())
            ->get(route('crm.inbox.show', $this->percakapan()))
            ->assertOk()
            ->assertSee('Ongkir di sini hanya untuk pesanan yang sedang disusun')
            ->assertSee('yang sudah di-post tidak bisa diubah');
    }

    /* -------------------------------------------------- rincian + link bayar */

    public function test_rincian_membawa_barang_ongkir_total_dan_tautan_bayar(): void
    {
        $percakapan = $this->percakapan();

        $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.buat-so', $percakapan), $this->muatan([
                'shipping_gross'       => 22000,
                'courier_name'         => 'JNE · REG',
                'shipping_provider'    => 'jubelio_shipment',
                'shipping_service_code' => 'REG',
            ]))
            ->assertOk();

        $so = SalesOrder::first();

        $d = $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.pesanan.rincian', [$percakapan, $so]))
            ->assertOk()
            ->json();

        $this->assertStringContainsString('Meja Akrilik', $d['teks']);
        $this->assertStringContainsString('Rp 222.000', $d['teks']);
        $this->assertStringContainsString('Pengiriman', $d['teks']);
        // Tautan bayarnya WAJIB ikut — itu inti tombolnya.
        $this->assertNotNull($d['url']);
        $this->assertStringContainsString($d['url'], $d['teks']);
    }

    /**
     * Pesanan milik pelanggan LAIN tidak boleh bocor lewat chat ini.
     *
     * Nomor SO ada di URL, jadi tanpa penjaga ini satu tebakan angka membuka
     * rincian harga dan tautan bayar pelanggan lain.
     */
    public function test_rincian_pesanan_pelanggan_lain_ditolak(): void
    {
        $percakapan = $this->percakapan();

        $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.buat-so', $percakapan), $this->muatan())
            ->assertOk();

        $lain = Customer::create(['code' => 'CUST-X', 'name' => 'Orang Lain', 'is_active' => true]);
        SalesOrder::first()->forceFill(['customer_id' => $lain->id])->save();

        $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.pesanan.rincian', [$percakapan, SalesOrder::first()]))
            ->assertNotFound();
    }

    /** Chat yang belum tertaut pelanggan tidak punya pesanan untuk ditampilkan. */
    public function test_lead_tanpa_pelanggan_memberi_daftar_kosong(): void
    {
        $this->actingAs($this->admin())
            ->getJson(route('crm.inbox.pesanan', $this->percakapan()))
            ->assertOk()
            ->assertJsonPath('pesanan', []);
    }
}
