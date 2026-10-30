<?php

namespace Tests\Feature\Marketplace\Shopee;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Providers\ShopeeChatProvider;
use App\Modules\Marketplace\Shopee\Models\ShopeeChat;
use App\Modules\Marketplace\Shopee\Models\ShopeeSetting;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeChatService;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Chat Shopee → CRM. Tahap A: AI-saja disembunyikan, pesan disalin dengan sumber yang benar,
 * SO tertaut dari kartu pesanan, tak satu pun kiriman lewat jalur WhatsApp.
 * Tahap B: balas teks/gambar lewat send_message + tandai dibaca, tanpa salinan ganda.
 */
class ShopeeChatTest extends TestCase
{
    use RefreshDatabase;

    private const TOKO = 555;

    private ShopeeShop $shop;
    private User $saiful;

    /** conversation_id => daftar pesan (terbaru dulu, seperti API) */
    private array $pesan = [];
    private array $percakapan = [];

    /** Panggilan tulis ke Shopee: [path, body|null] */
    private array $kiriman = [];
    private int $nomorKirim = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shopee' => ['partner_id' => '1000001', 'partner_key' => 'kunci-rahasia-uji', 'env' => 'test']]);
        $this->shop = ShopeeShop::create([
            'shop_id' => self::TOKO, 'env' => 'test', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addHours(3), 'refresh_expires_at' => now()->addDays(20), 'terhubung_at' => now(),
        ]);
        $this->saiful = User::factory()->create(['role' => 'admin', 'is_active' => true, 'name' => 'Saiful']);
        ShopeeSetting::singleton()->forceFill(['chat_owner_user_id' => $this->saiful->id])->save();

        Http::fake(function (Request $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            if (str_contains($r->url(), 'get_conversation_list')) {
                return Http::response(['response' => ['conversations' => array_values($this->percakapan), 'page_result' => ['more' => false]]]);
            }
            if (str_contains($r->url(), 'get_message')) {
                return Http::response(['response' => ['messages' => $this->pesan[$q['conversation_id']] ?? [], 'page_result' => ['next_offset' => '']]]);
            }
            if (str_contains($r->url(), 'upload_image')) {
                $this->kiriman[] = ['upload_image', null];

                return Http::response(['response' => ['url' => 'https://cf.shopee.co.id/file/gbr1', 'thumbnail' => 'x']]);
            }
            if (str_contains($r->url(), 'send_message')) {
                $this->kiriman[] = ['send_message', $r->data()];

                return Http::response(['response' => [
                    'message_id' => 'kirim' . (++$this->nomorKirim), 'to_id' => $r->data()['to_id'],
                    'message_type' => $r->data()['message_type'], 'content' => $r->data()['content'], 'conversation_id' => '7',
                ]]);
            }
            if (str_contains($r->url(), 'read_conversation')) {
                $this->kiriman[] = ['read_conversation', $r->data()];

                return Http::response(['response' => []]);
            }

            return Http::response(['error' => 'tak_terduga'], 400);
        });
    }

    private function cv(int $id, string $nama, int $detik, string $terakhir): void
    {
        $this->percakapan[$id] = [
            'conversation_id' => $id, 'to_id' => $id * 10, 'to_name' => $nama,
            'last_message_timestamp' => $detik . '000000000', 'latest_message_id' => $terakhir,
        ];
    }

    private function m(string $id, string $tipe, array $isi, bool $toko = false, string $sumber = 'android', ?int $detik = null): array
    {
        return [
            'message_id' => $id, 'message_type' => $tipe, 'content' => $isi,
            'from_shop_id' => $toko ? self::TOKO : 999, 'source' => $sumber,
            'created_timestamp' => $detik ?? now()->timestamp,
        ];
    }

    public function test_ai_saja_disembunyikan_dan_chat_penjual_disalin_lengkap(): void
    {
        $t = now()->subMinutes(10)->timestamp;
        $gudang = Warehouse::create(['name' => 'Gudang', 'is_sellable' => true, 'is_active' => true])->id;
        $mp = Customer::create(['code' => 'MP', 'name' => 'Shopee', 'is_marketplace' => true, 'is_active' => true])->id;
        $so = SalesOrder::create(['order_number' => 'SP-261027PDYVRSV7', 'customer_po_number' => 'SP-261027PDYVRSV7', 'customer_id' => $mp,
            'warehouse_id' => $gudang, 'order_date' => now()->toDateString(), 'status' => 'confirmed', 'subtotal' => 0, 'grand_total' => 0]);

        // Percakapan 1: hanya AI Shopee + sambutan otomatis toko → disembunyikan.
        $this->cv(1, 'ai_saja', $t, 'a2');
        $this->pesan[1] = [
            $this->m('a2', 'text', ['text' => 'Selamat datang'], toko: true, sumber: 'server', detik: $t),
            $this->m('a1', 'bundle_message', ['messages' => ['x', 'y']], detik: $t - 5),
        ];

        // Percakapan 2: dialihkan AI → pembeli tanya + kartu pesanan → admin balas → pembeli tanya lagi.
        $this->cv(2, 'fafastoreo', $t, 'b6');
        $this->pesan[2] = [
            $this->m('b6', 'text', ['text' => 'kapan dikirim kak?'], detik: $t),
            $this->m('b5', 'text', ['text' => 'Hi! Pesanmu telah kami terima'], toko: true, sumber: 'server', detik: $t - 10),
            $this->m('b4', 'text', ['text' => 'baik kak'], toko: true, sumber: 'new_webchat', detik: $t - 20),
            $this->m('b3', 'order', ['order_sn' => '261027PDYVRSV7', 'shop_id' => self::TOKO], detik: $t - 30),
            $this->m('b2', 'bot_summary_bundle_msg', ['transfer_reason' => ['text' => 'Pembeli tanyakan ukuran'], 'summary' => ['text' => 'Ringkas'], 'suggested_solution' => ['text' => 'Konfirmasi ukuran']], detik: $t - 40),
            $this->m('b1', 'bundle_message', ['messages' => ['p', 'q']], detik: $t - 50),
        ];

        $hasil = app(ShopeeChatService::class)->tarikBaru($this->shop);
        $this->assertSame(['diperiksa' => 2, 'tampil' => 1, 'tersembunyi' => 1, 'pesan' => 5], $hasil);

        $this->assertFalse(ShopeeChat::where('conversation_id', 1)->value('tampil'));
        $this->assertSame(1, CrmConversation::count());

        $crm = CrmConversation::first();
        $this->assertSame(CrmConversation::KANAL_SHOPEE, $crm->channel);
        $this->assertSame('fafastoreo', $crm->display_name);
        $this->assertSame($this->saiful->id, $crm->owner_user_id);
        $this->assertSame(CrmConversation::STATUS_AKTIF, $crm->status);
        $this->assertSame(1, $crm->unread_count, 'hanya pertanyaan sesudah balasan admin yang belum dibaca; balasan otomatis tidak dihitung');

        $pesan = CrmMessage::where('conversation_id', $crm->id)->orderBy('sent_at')->get();
        $this->assertSame(date('Y-m-d H:i', $t), $pesan[4]->sent_at->format('Y-m-d H:i'), 'jam pesan = jam WIB, bukan UTC');
        $this->assertCount(5, $pesan, 'penanda obrolan AI tidak disalin');
        $this->assertStringContainsString('Dialihkan dari AI Shopee', $pesan[0]->content);
        $this->assertStringContainsString('Alasan: Pembeli tanyakan ukuran', $pesan[0]->content);
        $this->assertSame('🧾 Pesanan Shopee 261027PDYVRSV7', $pesan[1]->content);
        $this->assertSame(CrmMessage::SOURCE_SELLER_CENTRE, $pesan[2]->source);
        $this->assertSame(CrmMessage::SOURCE_SHOPEE_OTOMATIS, $pesan[3]->source);
        $this->assertSame(CrmMessage::MASUK, $pesan[4]->direction);

        $this->assertSame($crm->id, (int) DB::table('crm_pesanan_chat')->where('sales_order_id', $so->id)->value('conversation_id'));
    }

    public function test_sinkron_ulang_hanya_menyalin_pesan_baru_dan_ai_jadi_tampil_saat_pembeli_minta_penjual(): void
    {
        $t = now()->subMinutes(5)->timestamp;
        $this->cv(3, 'budi', $t, 'c1');
        $this->pesan[3] = [$this->m('c1', 'bundle_message', ['messages' => ['z']], detik: $t)];

        $svc = app(ShopeeChatService::class);
        $svc->tarikBaru($this->shop);
        $this->assertSame(0, CrmConversation::count());

        // Tidak berubah → dilewati.
        $this->assertSame(0, $svc->tarikBaru($this->shop)['diperiksa']);

        // Pembeli menekan "Chat dengan Penjual" lalu bertanya.
        $this->cv(3, 'budi', $t + 60, 'c3');
        array_unshift($this->pesan[3],
            $this->m('c3', 'text', ['text' => 'ukuran 20x30 ada?'], detik: $t + 60),
            $this->m('c2', 'faq_liveagent', ['text' => 'Chat dengan Penjual'], detik: $t + 30));
        $this->shop->refresh();
        $h = $svc->tarikBaru($this->shop);

        $this->assertSame(1, $h['tampil']);
        $crm = CrmConversation::firstOrFail();
        $this->assertSame(2, CrmMessage::where('conversation_id', $crm->id)->count());
        $this->assertSame(2, $crm->unread_count);

        // Admin membalas dari Seller Centre → belum dibaca jadi 0, pesan lama tidak dobel.
        $this->cv(3, 'budi', $t + 120, 'c4');
        array_unshift($this->pesan[3], $this->m('c4', 'text', ['text' => 'ada kak'], toko: true, sumber: 'new_webchat', detik: $t + 120));
        $this->shop->refresh();
        $svc->tarikBaru($this->shop);

        $crm->refresh();
        $this->assertSame(3, CrmMessage::where('conversation_id', $crm->id)->count());
        $this->assertSame(0, $crm->unread_count);
    }

    public function test_riwayat_masuk_sebagai_arsip_tanpa_belum_dibaca(): void
    {
        $t = now()->subDays(40)->timestamp;
        $this->cv(4, 'lama', $t, 'd1');
        $this->pesan[4] = [$this->m('d1', 'image', ['url' => 'https://cf.shopee.co.id/file/abc'], detik: $t)];

        $h = app(ShopeeChatService::class)->tarikRiwayat($this->shop);

        $this->assertTrue($h['selesai']);
        $crm = CrmConversation::firstOrFail();
        $this->assertSame(CrmConversation::STATUS_ARSIP, $crm->status);
        $this->assertSame(0, $crm->unread_count);
        $lampiran = CrmMessage::firstOrFail()->attachments()->firstOrFail();
        $this->assertSame('https://cf.shopee.co.id/file/abc', $lampiran->source_url);
        $this->assertNotNull($lampiran->download_error, 'riwayat tidak ikut antrean unduh otomatis');
        $this->assertNotNull($this->shop->fresh()->chat_riwayat_selesai_at);
    }

    public function test_chat_shopee_tidak_pernah_dikirim_lewat_whatsapp(): void
    {
        $crm = CrmConversation::findOrCreateFor('9990', CrmConversation::KANAL_SHOPEE, (string) self::TOKO);

        config(['crm.dry_run' => false]);
        $jalur = app(ChatManager::class)->untuk($crm);
        $this->assertInstanceOf(ShopeeChatProvider::class, $jalur);
        $this->assertTrue(app(ChatManager::class)->siapUntuk($crm));
        $this->assertFalse($jalur->sendTemplate(['to' => '9990', 'template' => 'x'])['success']);

        // Mode aman: driver palsu — tak ada yang berangkat ke Shopee maupun WhatsApp.
        config(['crm.dry_run' => true]);
        $this->assertNotInstanceOf(ShopeeChatProvider::class, app(ChatManager::class)->untuk($crm));
        $this->assertTrue($crm->tanpaJendela());
    }

    /** Percakapan tersalin dari Shopee (conversation_id 7, pembeli to_id 70) dengan satu pesan masuk. */
    private function chatSiapDibalas(): CrmConversation
    {
        $t = now()->subMinutes(3)->timestamp;
        $this->cv(7, 'pembeli_tujuh', $t, 'g1');
        $this->pesan[7] = [$this->m('g1', 'text', ['text' => 'ready kak?'], detik: $t)];
        app(ShopeeChatService::class)->tarikBaru($this->shop);

        return CrmConversation::where('channel', CrmConversation::KANAL_SHOPEE)->firstOrFail();
    }

    public function test_inbox_chat_shopee_menampilkan_kotak_ketik_tanpa_tombol_mulai_chat(): void
    {
        config(['crm.dry_run' => false]);
        $crm = $this->chatSiapDibalas();

        $this->actingAs($this->saiful)->get(route('crm.inbox.show', $crm))
            ->assertOk()
            ->assertSee('Shopee · pembeli_tujuh')
            ->assertSee('Tulis balasan')
            ->assertSee('Tandai dibaca di Shopee')
            ->assertDontSee('Pesan bebas tidak bisa dikirim')
            ->assertDontSee('belum bisa dibalas dari ERP');

        // Toko terputus → kotak ketik diganti petunjuk Seller Centre.
        $this->shop->forceFill(['refresh_expires_at' => now()->subDay()])->save();
        $this->get(route('crm.inbox.show', $crm))
            ->assertOk()
            ->assertSee('belum bisa dibalas dari ERP')
            ->assertDontSee('Tulis balasan');
    }

    public function test_balas_teks_lewat_send_message_lalu_tandai_dibaca_tanpa_salinan_ganda(): void
    {
        config(['crm.dry_run' => false]);
        $crm = $this->chatSiapDibalas();

        $this->actingAs($this->saiful)
            ->postJson(route('crm.inbox.balas', $crm), ['teks' => 'Ready kak, silakan diorder'])
            ->assertOk();

        $this->assertSame(['send_message', ['to_id' => 70, 'message_type' => 'text', 'content' => ['text' => 'Ready kak, silakan diorder']]], $this->kiriman[0]);
        $this->assertSame(['read_conversation', ['conversation_id' => 7, 'last_read_message_id' => 'g1']], $this->kiriman[1]);

        $keluar = CrmMessage::where('direction', CrmMessage::KELUAR)->sole();
        $this->assertSame('shopee:kirim1', $keluar->provider_message_id);
        $this->assertSame(CrmMessage::SOURCE_ERP, $keluar->source);
        $this->assertSame($this->saiful->id, $keluar->sent_by_user_id);

        // Sinkron berikutnya melihat balasan itu (source openapi) — tidak boleh menggandakannya.
        $this->cv(7, 'pembeli_tujuh', now()->timestamp, 'kirim1');
        array_unshift($this->pesan[7], $this->m('kirim1', 'text', ['text' => 'Ready kak, silakan diorder'], toko: true, sumber: 'openapi'));
        app(ShopeeChatService::class)->tarikBaru($this->shop->fresh());

        $this->assertSame(1, CrmMessage::where('direction', CrmMessage::KELUAR)->count());
        $this->assertSame(0, $crm->fresh()->unread_count);
    }

    public function test_gambar_dan_teks_jadi_dua_pesan_karena_shopee_tanpa_caption(): void
    {
        config(['crm.dry_run' => false]);
        $crm = $this->chatSiapDibalas();

        $this->actingAs($this->saiful)
            ->post(route('crm.inbox.balas', $crm), [
                'teks'   => 'ini desainnya kak',
                'gambar' => [UploadedFile::fake()->image('desain.jpg', 200, 200)],
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertSame(['upload_image', null], $this->kiriman[0]);
        $kirim = array_values(array_filter($this->kiriman, fn ($k) => $k[0] === 'send_message'));
        $this->assertSame(['to_id' => 70, 'message_type' => 'image', 'content' => ['image_url' => 'https://cf.shopee.co.id/file/gbr1']], $kirim[0][1]);
        $this->assertSame(['to_id' => 70, 'message_type' => 'text', 'content' => ['text' => 'ini desainnya kak']], $kirim[1][1]);

        $keluar = CrmMessage::where('direction', CrmMessage::KELUAR)->orderBy('id')->get();
        $this->assertSame(['image', 'text'], $keluar->pluck('message_type')->all());
        $this->assertNull($keluar[0]->content);
        $this->assertSame(['shopee:kirim1', 'shopee:kirim2'], $keluar->pluck('provider_message_id')->all());
        $this->assertCount(1, $keluar[0]->attachments);
    }

    public function test_berkas_bukan_gambar_ditolak_tanpa_menyentuh_shopee(): void
    {
        config(['crm.dry_run' => false]);
        $crm = $this->chatSiapDibalas();

        $this->actingAs($this->saiful)
            ->post(route('crm.inbox.balas', $crm), [
                'gambar' => [UploadedFile::fake()->create('desain.pdf', 50, 'application/pdf')],
            ], ['Accept' => 'application/json'])
            ->assertJson(['success' => false]);

        $this->assertSame([], $this->kiriman);
        $this->assertSame(0, CrmMessage::where('direction', CrmMessage::KELUAR)->count());
    }

    public function test_mode_aman_mencatat_tanpa_mengirim_ke_shopee(): void
    {
        config(['crm.dry_run' => true]);
        $crm = $this->chatSiapDibalas();

        $this->actingAs($this->saiful)
            ->postJson(route('crm.inbox.balas', $crm), ['teks' => 'halo'])
            ->assertOk();

        $this->assertSame([], $this->kiriman);
        $this->assertSame(CrmMessage::STATUS_TIDAK_DIKIRIM, CrmMessage::where('direction', CrmMessage::KELUAR)->sole()->status);
    }

    public function test_tombol_tandai_dibaca_di_shopee(): void
    {
        config(['crm.dry_run' => false]);
        $crm = $this->chatSiapDibalas();

        $this->actingAs($this->saiful)
            ->post(route('crm.inbox.tandai-dibaca', $crm))
            ->assertSessionHas('success');

        $this->assertSame([['read_conversation', ['conversation_id' => 7, 'last_read_message_id' => 'g1']]], $this->kiriman);
    }

    public function test_saringan_saluran_memisahkan_chat_whatsapp_dan_shopee(): void
    {
        $wa = CrmConversation::findOrCreateFor('628111222333', 'whatsapp', null, ['display_name' => 'Pak Wahyu', 'owner_user_id' => $this->saiful->id]);
        $sp = CrmConversation::findOrCreateFor('777', CrmConversation::KANAL_SHOPEE, (string) self::TOKO, ['display_name' => 'pembeli_sp', 'owner_user_id' => $this->saiful->id]);

        $this->actingAs($this->saiful);
        $this->get(route('crm.inbox.index', ['pemilik' => 'semua', 'kanal' => 'shopee']))
            ->assertOk()->assertSee('pembeli_sp')->assertDontSee('Pak Wahyu');
        $this->get(route('crm.inbox.index', ['pemilik' => 'semua', 'kanal' => 'whatsapp']))
            ->assertOk()->assertSee('Pak Wahyu')->assertDontSee('pembeli_sp');
        $this->get(route('crm.inbox.index', ['pemilik' => 'semua']))
            ->assertOk()->assertSee('Pak Wahyu')->assertSee('pembeli_sp')->assertSee('Unduh chat');
    }

    /** SO Shopee + faktur, ditautkan ke chat. */
    private function pesananTertaut(CrmConversation $crm, string $statusFaktur = 'posted'): SalesOrder
    {
        $gudang = Warehouse::firstOrCreate(['name' => 'Gudang Uji']);
        $mp = Customer::create(['code' => 'MP-SP', 'name' => 'Shopee', 'is_marketplace' => true, 'is_active' => true]);
        $so = SalesOrder::create([
            'order_number' => 'SO-NOTA-1', 'customer_id' => $mp->id, 'warehouse_id' => $gudang->id,
            'order_date' => now()->toDateString(), 'global_discount_type' => 'nominal', 'status' => 'confirmed',
            'subtotal' => 69950, 'grand_total' => 69950, 'customer_po_number' => 'SP-261029V2AY0XWK',
        ]);
        \App\Models\SalesInvoice::create([
            'invoice_number' => 'SP-261029V2AY0XWK', 'sales_order_id' => $so->id, 'customer_id' => $mp->id,
            'warehouse_id' => $gudang->id, 'invoice_date' => now()->toDateString(), 'status' => $statusFaktur,
            'subtotal' => 69950, 'grand_total' => 69950,
        ]);
        app(\App\Modules\CRM\Services\LabelPesananService::class)->tautkan($crm, $so);

        return $so;
    }

    /**
     * Nota dikirim sebagai GAMBAR lewat upload_image Shopee, dan untuk chat Shopee
     * dicetak TANPA kontak toko (nomor & alamat web diblokir Shopee).
     */
    public function test_kirim_nota_ke_chat_shopee_sebagai_gambar_tanpa_kontak(): void
    {
        config(['crm.dry_run' => false]);
        $crm = $this->chatSiapDibalas();
        $so  = $this->pesananTertaut($crm);

        $panggilan = [];
        $this->app->instance(\App\Modules\CRM\Services\NotaGambarService::class, new class($panggilan) extends \App\Modules\CRM\Services\NotaGambarService {
            public function __construct(private array &$catat) {}
            public function render(\App\Models\SalesInvoice $invoice, bool $tanpaKontak, ?string $atasNama = null): UploadedFile
            {
                $this->catat[] = [$invoice->invoice_number, $tanpaKontak, $atasNama];
                return UploadedFile::fake()->image('Nota_' . $invoice->invoice_number . '.png', 300, 200);
            }
        });

        $this->actingAs($this->saiful)
            ->postJson(route('crm.inbox.pesanan.nota', [$crm, $so]), ['nama' => 'Bu Evi'])
            ->assertOk()->assertJson(['success' => true, 'nomor' => 'SP-261029V2AY0XWK']);

        $this->assertSame([['SP-261029V2AY0XWK', true, 'Bu Evi']], $panggilan);
        $this->assertSame('upload_image', $this->kiriman[0][0]);
        $keluar = CrmMessage::where('direction', CrmMessage::KELUAR)->get();
        $this->assertSame(['image'], $keluar->pluck('message_type')->all());
        $this->assertCount(1, $keluar[0]->attachments);

        // Kartu pesanan di panel menawarkan tombolnya.
        $this->get(route('crm.inbox.show', $crm))->assertOk()->assertSee('Kirim nota');
    }

    public function test_nota_ditolak_bila_faktur_belum_posted(): void
    {
        config(['crm.dry_run' => false]);
        $crm = $this->chatSiapDibalas();
        $so  = $this->pesananTertaut($crm, 'draft');

        $this->actingAs($this->saiful)
            ->postJson(route('crm.inbox.pesanan.nota', [$crm, $so]))
            ->assertStatus(422)->assertJson(['success' => false]);
        $this->assertSame(0, CrmMessage::where('direction', CrmMessage::KELUAR)->count());
    }

    /** Pesanan yang tak tertaut & bukan milik pelanggan chat ini tidak boleh dikirim. */
    public function test_nota_pesanan_orang_lain_ditolak(): void
    {
        $crm = $this->chatSiapDibalas();
        $so  = $this->pesananTertaut($crm);
        DB::table('crm_pesanan_chat')->delete();

        $this->actingAs($this->saiful)
            ->postJson(route('crm.inbox.pesanan.nota', [$crm, $so]))
            ->assertNotFound();
    }

    /** Halaman cetak faktur: ?tanpa_kontak=1 membuang baris telepon/email toko. */
    public function test_cetak_faktur_tanpa_kontak(): void
    {
        $crm = $this->chatSiapDibalas();
        $so  = $this->pesananTertaut($crm);
        $faktur = $so->invoices()->first();
        \App\Models\BusinessProfile::instance()->forceFill(['phone' => '08998844666', 'email' => 'noudacrylic@gmail.com'])->save();
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($admin)->get(route('sales.invoices.print', $faktur->id))
            ->assertOk()->assertSee('08998844666')->assertSee('Tampilkan kontak toko');
        $this->actingAs($admin)->get(route('sales.invoices.print', [$faktur->id, 'tanpa_kontak' => 1]))
            ->assertOk()->assertDontSee('08998844666')->assertDontSee('noudacrylic@gmail.com');
    }
}
