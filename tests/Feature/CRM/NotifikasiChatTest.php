<?php

namespace Tests\Feature\CRM;

use App\Models\PushSubscription;
use App\Models\User;
use App\Models\UserMenuPermission;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Services\IncomingWebhookService;
use App\Modules\Notifications\Services\WebPushNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Notifikasi chat: pesan masuk & operan.
 *
 * Yang dijaga di sini adalah SIAPA yang dikabari, bukan apakah pushnya sampai
 * (itu urusan vendor). Salah sasaran di fitur ini tidak menimbulkan galat apa
 * pun — ia cuma membuat orang menerima kabar yang bukan urusannya sampai ia
 * mematikan notifikasi, dan sejak itu kabar yang penting pun ikut hilang.
 */
class NotifikasiChatTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{subs: \Illuminate\Support\Collection, title: string, body: string, opts: array}> */
    private array $terkirim = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['crm.dry_run' => true]);
        app(ChatManager::class)->fake()->reset();

        /*
         * Pengirimnya dimata-matai di lapis PALING BAWAH (sendToSubscriptions),
         * supaya seluruh logika penargetan di atasnya benar-benar dijalankan —
         * memalsukan notifyUser/notifyMenuAccess justru melewati hal yang diuji.
         */
        $mata = new class extends WebPushNotifier {
            public array $log = [];

            public function __construct()
            {
            }

            public function enabled(): bool
            {
                return true;
            }

            public function sendToSubscriptions($subs, string $title, string $body, array $opts = []): int
            {
                $this->log[] = [
                    'user_ids' => collect($subs)->pluck('user_id')->sort()->values()->all(),
                    'title'    => $title,
                    'body'     => $body,
                    'opts'     => $opts,
                ];

                return count($subs);
            }
        };

        $this->app->instance(WebPushNotifier::class, $mata);
    }

    private function mata(): WebPushNotifier
    {
        return app(WebPushNotifier::class);
    }

    /** @return array<int, array> */
    private function log(): array
    {
        return $this->mata()->log;
    }

    private function pengguna(string $role, ?string $menu = null): User
    {
        $u = User::factory()->create(['role' => $role, 'is_active' => true]);

        if ($menu) {
            UserMenuPermission::create(['user_id' => $u->id, 'menu_key' => $menu]);
        }

        // Tanpa langganan, tidak ada yang bisa dikirimi.
        PushSubscription::create([
            'user_id'    => $u->id,
            'endpoint'   => 'https://contoh.test/' . $u->id,
            'public_key' => 'p256dh-' . $u->id,
            'auth_token' => 'auth-' . $u->id,
        ]);

        return $u;
    }

    private function percakapan(array $attrs = []): CrmConversation
    {
        $p = CrmConversation::findOrCreateFor('628998844666');
        $p->forceFill($attrs + ['display_name' => 'Fahri'])->save();

        return $p;
    }

    private function pesanMasuk(string $teks = 'halo kak, frame mahar ready?'): void
    {
        app(IncomingWebhookService::class)->tangani([
            'event_type' => 'message.received',
            'event_id'   => 'evt-' . uniqid(),
            'timestamp'  => now()->toIso8601String(),
            'data'       => [
                // Nama field mengikuti payload sungguhan: `customer_phone` untuk
                // nomor, dan `text` untuk isinya (lihat isi() di servicenya —
                // `message` BUKAN salah satu alias yang dibaca).
                'customer_phone' => '628998844666',
                'text'           => $teks,
                'raw'            => ['id' => 'wamid.' . uniqid()],
            ],
        ]);
    }

    /* --------------------------------------------------------- pesan masuk */

    /**
     * Chat TANPA pemilik disiarkan — tidak ada yang merasa bertanggung jawab
     * atas chat tanpa pemilik, jadi kalau tidak disiarkan ia tidak diangkat
     * siapa pun.
     */
    public function test_pesan_masuk_tanpa_pemilik_disiarkan_ke_orang_crm(): void
    {
        $agen = $this->pengguna('user', 'crm.inbox');
        $this->percakapan(['owner_user_id' => null]);

        $this->pesanMasuk();

        $this->assertCount(1, $this->log());
        $this->assertSame([$agen->id], $this->log()[0]['user_ids']);
    }

    /**
     * Chat yang SUDAH dioper hanya mengabari pemiliknya. Menyiarkannya membuat
     * kepemilikan tak ada artinya, dan tiap orang belajar mengabaikan kabar
     * yang biasanya bukan urusannya.
     */
    public function test_pesan_masuk_berpemilik_hanya_ke_pemiliknya(): void
    {
        $pemilik = $this->pengguna('user', 'crm.inbox');
        $lain    = $this->pengguna('user', 'crm.inbox');

        $this->percakapan(['owner_user_id' => $pemilik->id]);
        $this->pesanMasuk();

        $this->assertSame([$pemilik->id], $this->log()[0]['user_ids']);
        $this->assertNotContains($lain->id, $this->log()[0]['user_ids']);
    }

    /**
     * Akun CS chat-saja memegang flag `pwa_crm` TANPA izin menu apa pun — ia
     * memang tidak boleh masuk ERP. Dulu ia jatuh di luar jaring "orang CRM"
     * dan tidak pernah dikabari chat baru; gejalanya menipu, karena terasa
     * seperti notifikasi yang "cuma jalan kalau aplikasinya dibuka" padahal
     * tidak pernah dikirim sama sekali.
     */
    public function test_akun_cs_chat_saja_ikut_dikabari(): void
    {
        $cs = $this->pengguna('user');
        $cs->forceFill(['pwa_crm' => true])->save();

        $this->percakapan(['owner_user_id' => null]);
        $this->pesanMasuk();

        $this->assertSame([$cs->id], $this->log()[0]['user_ids']);
    }

    /**
     * Tautannya menunjuk Inbox desktop karena notifikasi dibuat sekali untuk
     * semua perangkat. Yang menerjemahkannya ke layar PWA adalah sisi penerima
     * (UrlPwa & service worker), jadi bentuk aslinya harus tetap utuh di sini —
     * kalau berubah, aturan penerjemahan di dua tempat itu berhenti cocok.
     */
    public function test_url_notifikasi_menunjuk_percakapan_dan_bisa_dipetakan_ke_pwa(): void
    {
        $this->pengguna('user', 'crm.inbox');
        $percakapan = $this->percakapan(['owner_user_id' => null]);

        $this->pesanMasuk();

        $url = $this->log()[0]['opts']['url'] ?? '';

        $this->assertStringEndsWith('/erp/crm/' . $percakapan->id, $url);
        $this->assertSame('/cs/' . $percakapan->id, \App\Modules\CRM\Support\UrlPwa::dariErp($url));
    }

    /** Yang tidak mengurus CRM tidak ikut terganggu. */
    public function test_yang_tak_punya_akses_crm_tidak_dikabari(): void
    {
        $luar = $this->pengguna('user', 'purchasing.orders');
        $this->percakapan(['owner_user_id' => null]);

        $this->pesanMasuk();

        $this->assertSame([], $this->log()[0]['user_ids'] ?? []);
        $this->assertNotContains($luar->id, $this->log()[0]['user_ids'] ?? []);
    }

    /** Admin & super_admin berakses penuh — aturannya disalin dari EnsureMenuAccess. */
    public function test_admin_ikut_dikabari_tanpa_izin_menu_eksplisit(): void
    {
        $admin = $this->pengguna('admin');
        $this->percakapan(['owner_user_id' => null]);

        $this->pesanMasuk();

        $this->assertContains($admin->id, $this->log()[0]['user_ids']);
    }

    /** Karyawan (PWA /me) tidak pernah ikut notifikasi ERP. */
    public function test_karyawan_tidak_ikut(): void
    {
        $karyawan = $this->pengguna('karyawan');
        $this->percakapan(['owner_user_id' => null]);

        $this->pesanMasuk();

        $this->assertNotContains($karyawan->id, $this->log()[0]['user_ids'] ?? []);
    }

    /**
     * Penanda notifikasi memakai id PERCAKAPAN, bukan id pesan: pelanggan yang
     * mengirim lima pesan beruntun harus menghasilkan satu notifikasi yang
     * diperbarui, bukan lima yang menumpuk sampai orang mematikannya.
     */
    public function test_pesan_beruntun_memakai_penanda_yang_sama(): void
    {
        $this->pengguna('admin');
        $p = $this->percakapan(['owner_user_id' => null]);

        $this->pesanMasuk('pesan satu');
        $this->pesanMasuk('pesan dua');

        $this->assertCount(2, $this->log());
        $this->assertSame('crm-chat-' . $p->id, $this->log()[0]['opts']['tag']);
        $this->assertSame('crm-chat-' . $p->id, $this->log()[1]['opts']['tag']);
    }

    public function test_isi_notifikasi_membawa_nama_dan_cuplikan_pesan(): void
    {
        $this->pengguna('admin');
        $this->percakapan(['owner_user_id' => null]);

        $this->pesanMasuk('frame mahar 30x30 ready kak?');

        $this->assertStringContainsString('Fahri', $this->log()[0]['title']);
        $this->assertStringContainsString('frame mahar 30x30', $this->log()[0]['body']);
    }

    /** Pesan tetap tersimpan walau pengabaran meledak. */
    public function test_notifikasi_gagal_tidak_menggagalkan_penyimpanan_pesan(): void
    {
        $this->app->instance(WebPushNotifier::class, new class extends WebPushNotifier {
            public function __construct()
            {
            }

            public function sendToSubscriptions($subs, string $title, string $body, array $opts = []): int
            {
                throw new \RuntimeException('vendor push meledak');
            }
        });

        $this->pengguna('admin');
        $this->percakapan(['owner_user_id' => null]);

        $this->pesanMasuk('halo');

        $this->assertSame(1, CrmMessage::where('direction', CrmMessage::MASUK)->count());
    }

    /* -------------------------------------------------------------- operan */

    public function test_operan_mengabari_penerimanya(): void
    {
        $pengoper = $this->pengguna('admin');
        $penerima = $this->pengguna('user', 'crm.inbox');
        $p        = $this->percakapan(['owner_user_id' => null]);

        $this->actingAs($pengoper)
            ->post(route('crm.inbox.oper', $p->id), ['owner_user_id' => $penerima->id])
            ->assertRedirect();

        $this->assertCount(1, $this->log());
        $this->assertSame([$penerima->id], $this->log()[0]['user_ids']);
        $this->assertStringContainsString('dioper', $this->log()[0]['title']);
        $this->assertSame('crm-oper-' . $p->id, $this->log()[0]['opts']['tag']);
    }

    /** Mengambil chat untuk diri sendiri bukan operan — tidak perlu dikabari. */
    public function test_mengambil_untuk_diri_sendiri_tidak_mengabari(): void
    {
        $aku = $this->pengguna('admin');
        $p   = $this->percakapan(['owner_user_id' => null]);

        $this->actingAs($aku)
            ->post(route('crm.inbox.oper', $p->id), ['owner_user_id' => $aku->id])
            ->assertRedirect();

        $this->assertSame([], $this->log());
    }

    /** Melepas kepemilikan tidak mengabari siapa pun. */
    public function test_melepas_kepemilikan_tidak_mengabari(): void
    {
        $aku = $this->pengguna('admin');
        $p   = $this->percakapan(['owner_user_id' => $aku->id]);

        $this->actingAs($aku)
            ->post(route('crm.inbox.oper', $p->id), ['owner_user_id' => null])
            ->assertRedirect();

        $this->assertSame([], $this->log());
    }

    /* ------------------------------------------------------ akses langganan */

    /**
     * Agen CRM berperan `user` HARUS bisa menyalakan notifikasinya sendiri.
     * Dulu rutenya duduk di bawah `pos.*` dan ia selalu ditolak — gejalanya
     * cuma tombol yang diam, tanpa pesan galat apa pun.
     */
    public function test_agen_crm_bisa_berlangganan_push(): void
    {
        $agen = User::factory()->create(['role' => 'user', 'is_active' => true]);
        UserMenuPermission::create(['user_id' => $agen->id, 'menu_key' => 'crm.inbox']);

        $this->actingAs($agen)
            ->postJson(route('push.subscribe'), [
                'endpoint' => 'https://contoh.test/agen-crm',
                'keys'     => ['p256dh' => 'kunci', 'auth' => 'rahasia'],
            ])
            ->assertOk();

        $this->assertDatabaseHas('push_subscriptions', [
            'user_id'  => $agen->id,
            'endpoint' => 'https://contoh.test/agen-crm',
        ]);
    }

    public function test_langganan_push_butuh_login(): void
    {
        $this->postJson(route('push.subscribe'), [
            'endpoint' => 'https://contoh.test/x',
            'keys'     => ['p256dh' => 'a', 'auth' => 'b'],
        ])->assertUnauthorized();
    }
}
