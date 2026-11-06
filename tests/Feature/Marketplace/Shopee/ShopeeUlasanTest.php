<?php

namespace Tests\Feature\Marketplace\Shopee;

use App\Models\User;
use App\Modules\KaryawanVirtual\Contracts\Skill;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Models\ShopeeUlasan;
use App\Modules\Marketplace\Shopee\Services\ShopeeUlasanService;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Skill "Balas ulasan jelek Shopee": ulasan ⭐1–3 → kartu Meja Layanan dengan draf AI,
 * dibalas dari kartu, kartu tutup sendiri.
 */
class ShopeeUlasanTest extends TestCase
{
    use RefreshDatabase;

    private ShopeeShop $shop;

    /** @var array<int, array> baris get_comment */
    private array $ulasan = [];

    private array $tolakBalasan = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shopee' => ['partner_id' => '1000001', 'partner_key' => 'kunci-rahasia-uji', 'env' => 'test'],
                'services.anthropic.key' => 'KUNCI-UJI']);
        $this->shop = ShopeeShop::create([
            'shop_id' => 555, 'env' => 'test', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addHours(3), 'refresh_expires_at' => now()->addDays(20), 'terhubung_at' => now(),
        ]);

        $this->ulasan = [
            $this->baris(1, 1, 'Barang kurang 2 bagian, gak bisa dirakit', 'EDITABLE', ['image_url_list' => ['https://mms.img.susercontent.com/foto-a']]),
            $this->baris(2, 3, '', 'HAVE_EDITED_ONCE'),
            $this->baris(3, 5, 'Bagus', 'EDITABLE'),
        ];

        Http::fake(function (Request $r) {
            if (str_contains($r->url(), 'api.anthropic.com') && str_contains($r->data()['system'] ?? '', 'bintang bagus')) {
                $keluhan = str_contains(json_encode($r->data()['messages']), 'retak');

                return Http::response(['content' => [['type' => 'text', 'text' => json_encode(
                    ['keluhan' => $keluhan, 'balasan' => $keluhan ? '' : 'Terima kasih Kak, senang perakitannya mudah!'])]]]);
            }
            if (str_contains($r->url(), 'api.anthropic.com')) {
                return Http::response(['content' => [['type' => 'text', 'text' => '{"balasan": "Mohon maaf Kak, kami bantu lewat chat ya.", "chat": "Halo Kak, boleh ceritakan bagian yang kurang?"}']]]);
            }
            if (str_contains($r->url(), 'get_comment')) {
                return Http::response(['response' => ['item_comment_list' => $this->ulasan, 'more' => false, 'next_cursor' => '']]);
            }
            if (str_contains($r->url(), 'reply_comment')) {
                $id = $r->data()['comment_list'][0]['comment_id'];

                return Http::response(['response' => ['result_list' => [in_array($id, $this->tolakBalasan, true)
                    ? ['comment_id' => $id, 'fail_error' => 'error_param', 'fail_message' => 'reply window expired']
                    : ['comment_id' => $id]]]]);
            }

            return Http::response(['response' => []]);
        });
    }

    private function baris(int $id, int $bintang, string $isi, string $editable, array $media = []): array
    {
        return ['comment_id' => 1000 + $id, 'comment' => $isi, 'buyer_username' => "pembeli$id", 'order_sn' => "SN$id",
                'item_id' => 9, 'model_id' => 0, 'create_time' => now()->subDays($id)->getTimestamp(), 'rating_star' => $bintang,
                'editable' => $editable, 'hidden' => false, 'media' => $media];
    }

    private function svc(): ShopeeUlasanService
    {
        return app(ShopeeUlasanService::class);
    }

    private function nyalakan(): void
    {
        app(KaryawanVirtualService::class)->ubahMode('admin.ulasan-jelek', Skill::OTOMATIS, null);
    }

    public function test_tarik_lalu_kartu_hanya_untuk_ulasan_jelek_termasuk_yang_lama(): void
    {
        $this->assertSame(3, $this->svc()->tarik($this->shop));
        $u = ShopeeUlasan::where('comment_id', 1001)->first();
        $this->assertSame(['https://mms.img.susercontent.com/foto-a'], $u->fotoUrl());
        $this->assertTrue($u->bisaDiubahPembeli());
        $this->assertNull(ShopeeUlasan::where('comment_id', 1002)->value('comment'), 'ulasan tanpa tulisan disimpan null');

        $this->nyalakan();
        app(MejaKerjaService::class)->sinkron();

        $kartu = Task::where('skill', 'ulasan-jelek')->orderBy('id')->get();
        $this->assertCount(2, $kartu, 'bintang 5 tidak dibuatkan kartu');
        $this->assertSame('layanan', $kartu[0]->peran);
        $this->assertStringContainsString('SP-SN', $kartu[0]->title);
        $this->assertTrue($kartu->every(fn ($t) => $t->status === 'open'));
    }

    public function test_draf_ai_hanya_saat_skill_menyala_dan_chat_hanya_bila_bisa_diubah(): void
    {
        $this->artisan('shopee:tarik-ulasan')->assertSuccessful();
        $this->assertSame(0, ShopeeUlasan::whereNotNull('draf_at')->count(), 'skill Nonaktif → tanpa draf');

        $this->nyalakan();
        $this->artisan('shopee:tarik-ulasan')->assertSuccessful();

        $bisa = ShopeeUlasan::where('comment_id', 1001)->first();
        $this->assertSame('Mohon maaf Kak, kami bantu lewat chat ya.', $bisa->draf_balasan);
        $this->assertSame('Halo Kak, boleh ceritakan bagian yang kurang?', $bisa->draf_chat);
        $this->assertNull(ShopeeUlasan::where('comment_id', 1002)->value('draf_chat'), 'sudah pernah diubah → tanpa draf chat');
        $this->assertNull(ShopeeUlasan::where('comment_id', 1003)->value('draf_at'), 'bintang 5 tak didrafkan');

        // Foto ulasan ikut dikirim ke AI.
        $kiriman = Http::recorded(fn (Request $r) => str_contains($r->url(), 'anthropic'))->first()[0]->data();
        $this->assertSame('image', $kiriman['messages'][0]['content'][1]['type']);
    }

    public function test_kirim_balasan_dari_kartu_menutup_kartu(): void
    {
        $this->svc()->tarik($this->shop);
        $this->nyalakan();
        app(MejaKerjaService::class)->sinkron();
        $u = ShopeeUlasan::where('comment_id', 1001)->first();
        $t = Task::where('skill', 'ulasan-jelek')->where('taskable_id', $u->id)->first();

        $aksi = app(MejaKerjaService::class)->skill('ulasan-jelek')->aksi($t);
        $this->assertSame('Kirim balasan', $aksi[0]['label']);
        $this->assertSame(route('tasks.meja.ulasan.balas', $u->id), $aksi[0]['kirim']);
        $this->assertNotNull(collect($aksi)->firstWhere('label', 'Foto 1'));

        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin)->post(route('tasks.meja.ulasan.balas', $u->id), ['teks' => 'Mohon maaf Kak 🙏'])
            ->assertRedirect()->assertSessionHas('success');

        $u->refresh();
        $this->assertSame('Mohon maaf Kak 🙏', $u->balasan);
        $this->assertSame($admin->id, $u->dibalas_oleh);
        $this->assertSame('done', $t->refresh()->status);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'reply_comment') && $r->data()['comment_list'][0]['comment_id'] === 1001);

        // Tarik ulang (Shopee belum memantulkan balasan) tidak menghapus balasan yang terkirim.
        $this->svc()->tarik($this->shop);
        $this->assertSame('Mohon maaf Kak 🙏', $u->refresh()->balasan);

        // Sudah dibalas → tidak bisa dikirim lagi.
        $this->actingAs($admin)->post(route('tasks.meja.ulasan.balas', $u->id), ['teks' => 'lagi'])->assertSessionHas('error');
    }

    public function test_balasan_ditolak_shopee_ditampilkan_dan_kartu_tetap_terbuka(): void
    {
        $this->tolakBalasan = [1002];
        $this->svc()->tarik($this->shop);
        $this->nyalakan();
        app(MejaKerjaService::class)->sinkron();
        $u = ShopeeUlasan::where('comment_id', 1002)->first();

        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin)->post(route('tasks.meja.ulasan.balas', $u->id), ['teks' => 'Maaf Kak'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'reply window expired'));

        $this->assertNull($u->refresh()->balasan);
        $this->assertSame('open', Task::where('skill', 'ulasan-jelek')->where('taskable_id', $u->id)->value('status'));
    }

    public function test_balasan_dari_seller_centre_dan_bintang_naik_menutup_kartu(): void
    {
        $this->svc()->tarik($this->shop);
        $this->nyalakan();
        app(MejaKerjaService::class)->sinkron();

        $this->ulasan[0]['comment_reply'] = ['reply' => 'Dibalas dari Seller Centre', 'hidden' => false, 'create_time' => now()->getTimestamp()];
        $this->ulasan[1]['rating_star'] = 5;
        $this->svc()->tarik($this->shop);
        app(MejaKerjaService::class)->sinkron();

        $status = Task::where('skill', 'ulasan-jelek')->orderBy('taskable_id')->pluck('status')->all();
        $this->assertSame(['done', 'cancelled'], $status);
        $this->assertSame('Dibalas dari Seller Centre', ShopeeUlasan::where('comment_id', 1001)->value('balasan'));
    }
    public function test_ulasan_bagus_template_ai_dan_keluhan_ke_kartu(): void
    {
        $this->ulasan = [
            $this->baris(1, 5, '', 'EDITABLE'),                                   // template
            $this->baris(2, 4, '', 'EDITABLE'),                                   // template, variasi lain
            $this->baris(3, 5, 'Perakitan sangat mudah, kokoh', 'EDITABLE'),       // AI pujian
            $this->baris(4, 5, 'Bagus tapi bahan tipis jadi banyak yang retak', 'EDITABLE'), // keluhan
            $this->baris(9, 5, '', 'EDITABLE'),                                   // di luar jendela 7 hari
        ];
        $this->svc()->tarik($this->shop);
        app(KaryawanVirtualService::class)->ubahMode('admin.ulasan-bagus', Skill::OTOMATIS, null);
        $this->nyalakan();

        $this->artisan('shopee:tarik-ulasan')->assertSuccessful();

        $u = fn (int $id) => ShopeeUlasan::where('comment_id', 1000 + $id)->first();
        $this->assertSame('template', $u(1)->sumber_balasan);
        $this->assertSame('template', $u(2)->sumber_balasan);
        $this->assertNotSame($u(1)->balasan, $u(2)->balasan, 'template diputar per ulasan');
        $this->assertStringNotContainsString('{produk}', $u(1)->balasan);
        $this->assertSame('ai', $u(3)->sumber_balasan);
        $this->assertSame('Terima kasih Kak, senang perakitannya mudah!', $u(3)->balasan);
        $this->assertNull($u(9)->balasan, 'ulasan lama di luar jendela tidak dibalas');

        // Keluhan: tidak dibalas otomatis, jadi kartu admin + draf permintaan maaf.
        $k = $u(4);
        $this->assertTrue($k->keluhan);
        $this->assertNull($k->balasan);
        $this->assertSame('Mohon maaf Kak, kami bantu lewat chat ya.', $k->draf_balasan);
        app(MejaKerjaService::class)->sinkron();
        $t = Task::where('skill', 'ulasan-jelek')->where('taskable_id', $k->id)->first();
        $this->assertNotNull($t);
        $this->assertStringContainsString('ada keluhan', $t->title);
        $this->assertSame('open', $t->status);
    }

    public function test_ulasan_bagus_mode_bayangan_tidak_mengirim(): void
    {
        $this->ulasan = [$this->baris(1, 5, '', 'EDITABLE'), $this->baris(3, 5, 'Mantap', 'EDITABLE')];
        $this->svc()->tarik($this->shop);
        $kv = app(KaryawanVirtualService::class);
        $kv->ubahMode('admin.ulasan-bagus', Skill::BAYANGAN, null);

        $jalan = $kv->jalankan('admin.ulasan-bagus', KaryawanVirtualService::PEMICU_JADWAL);
        $this->assertCount(2, $jalan['hasil']);
        $this->assertSame(['akan', 'akan'], array_column($jalan['hasil'], 'status'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'reply_comment'));
        $this->assertSame(0, ShopeeUlasan::whereNotNull('balasan')->count());

        // Jalan berikutnya tidak mencatat ulang yang sama.
        $this->assertCount(0, $kv->jalankan('admin.ulasan-bagus', KaryawanVirtualService::PEMICU_JADWAL)['hasil']);
    }
}
