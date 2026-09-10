<?php

namespace Tests\Feature\CRM;

use App\Models\ErpNotification;
use App\Models\User;
use App\Models\UserMenuPermission;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Services\IncomingWebhookService;
use App\Modules\Notifications\Services\WebPushNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lonceng notifikasi ERP.
 *
 * Ia ada karena web push punya dua lubang: butuh izin peramban, dan sekali
 * lewat ia hilang. Yang dijaga tes ini terutama dua hal yang kalau salah tidak
 * bergejala sampai terlambat — barisnya benar-benar ditulis untuk penerima yang
 * tepat, dan notifikasi milik orang lain tidak bisa dibaca atau ditandai.
 */
class LoncengNotifikasiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['crm.dry_run' => true]);
        app(ChatManager::class)->fake()->reset();

        // Push dimatikan: yang diuji di sini barisnya, bukan pengirimannya.
        $this->app->instance(WebPushNotifier::class, new class extends WebPushNotifier {
            public function __construct()
            {
            }

            public function enabled(): bool
            {
                return false;
            }
        });
    }

    private function pengguna(string $role = 'user', ?string $menu = 'crm.inbox'): User
    {
        $u = User::factory()->create(['role' => $role, 'is_active' => true]);

        if ($menu) {
            UserMenuPermission::create(['user_id' => $u->id, 'menu_key' => $menu]);
        }

        return $u;
    }

    private function pesanMasuk(string $teks = 'halo kak'): void
    {
        app(IncomingWebhookService::class)->tangani([
            'event_type' => 'message.received',
            'event_id'   => 'evt-' . uniqid(),
            'timestamp'  => now()->toIso8601String(),
            'data'       => [
                'customer_phone' => '628998844666',
                'text'           => $teks,
                'raw'            => ['id' => 'wamid.' . uniqid()],
            ],
        ]);
    }

    private function percakapan(array $attrs = []): CrmConversation
    {
        $p = CrmConversation::findOrCreateFor('628998844666');
        $p->forceFill($attrs + ['display_name' => 'Fahri'])->save();

        return $p;
    }

    /* --------------------------------------------------------- baris ditulis */

    public function test_pesan_masuk_menulis_baris_untuk_yang_berakses(): void
    {
        $agen = $this->pengguna();
        $this->percakapan(['owner_user_id' => null]);

        $this->pesanMasuk('frame mahar ready?');

        $baris = ErpNotification::milik($agen->id)->first();

        $this->assertNotNull($baris);
        $this->assertSame(ErpNotification::CHAT_MASUK, $baris->jenis);
        $this->assertStringContainsString('Fahri', $baris->judul);
        $this->assertStringContainsString('frame mahar ready?', $baris->isi);
        $this->assertNull($baris->read_at);
    }

    public function test_yang_tak_berakses_crm_tidak_dapat_baris(): void
    {
        $luar = $this->pengguna('user', 'purchasing.orders');
        $this->percakapan(['owner_user_id' => null]);

        $this->pesanMasuk();

        $this->assertSame(0, ErpNotification::milik($luar->id)->count());
    }

    /** Akun nonaktif tidak perlu menerima pekerjaan. */
    public function test_akun_nonaktif_dilewati(): void
    {
        $mati = $this->pengguna();
        $mati->forceFill(['is_active' => false])->save();

        $this->percakapan(['owner_user_id' => null]);
        $this->pesanMasuk();

        $this->assertSame(0, ErpNotification::milik($mati->id)->count());
    }

    /**
     * Pesan beruntun MERINGKAS jadi satu baris. Tanpa ini lonceng terisi lima
     * kabar dari satu orang dan mendorong kabar lain keluar dari layar.
     */
    public function test_pesan_beruntun_meringkas_jadi_satu_baris(): void
    {
        $agen = $this->pengguna();
        $this->percakapan(['owner_user_id' => null]);

        $this->pesanMasuk('pesan satu');
        $this->pesanMasuk('pesan dua');
        $this->pesanMasuk('pesan tiga');

        $this->assertSame(1, ErpNotification::milik($agen->id)->count());
        $this->assertStringContainsString('pesan tiga', ErpNotification::milik($agen->id)->first()->isi);
    }

    /**
     * Tapi yang SUDAH dibaca tidak ikut diperbarui — itu peristiwa baru, dan
     * menandainya belum-dibaca lagi memang benar.
     */
    public function test_yang_sudah_dibaca_tidak_ditumpangi(): void
    {
        $agen = $this->pengguna();
        $this->percakapan(['owner_user_id' => null]);

        $this->pesanMasuk('pesan lama');
        ErpNotification::milik($agen->id)->update(['read_at' => now()]);

        $this->pesanMasuk('pesan baru');

        $this->assertSame(2, ErpNotification::milik($agen->id)->count());
        $this->assertSame(1, ErpNotification::milik($agen->id)->belumDibaca()->count());
    }

    public function test_operan_menulis_baris_untuk_penerimanya(): void
    {
        $pengoper = $this->pengguna('admin', null);
        $penerima = $this->pengguna();
        $p        = $this->percakapan(['owner_user_id' => null]);

        $this->actingAs($pengoper)
            ->post(route('crm.inbox.oper', $p->id), ['owner_user_id' => $penerima->id])
            ->assertRedirect();

        $baris = ErpNotification::milik($penerima->id)->first();

        $this->assertNotNull($baris);
        $this->assertSame(ErpNotification::CHAT_DIOPER, $baris->jenis);
        $this->assertSame(0, ErpNotification::milik($pengoper->id)->count(), 'yang mengoper tidak dikabari');
    }

    /* -------------------------------------------------------------- layarnya */

    public function test_daftar_hanya_memuat_milik_sendiri(): void
    {
        $aku  = $this->pengguna();
        $lain = $this->pengguna();

        ErpNotification::create(['user_id' => $aku->id,  'jenis' => 'x', 'judul' => 'Punyaku', 'isi' => 'a']);
        ErpNotification::create(['user_id' => $lain->id, 'jenis' => 'x', 'judul' => 'Punya orang', 'isi' => 'b']);

        $this->actingAs($aku)
            ->getJson(route('notifikasi.index'))
            ->assertOk()
            ->assertJsonPath('belum_dibaca', 1)
            ->assertJsonCount(1, 'daftar')
            ->assertJsonPath('daftar.0.judul', 'Punyaku');
    }

    public function test_menandai_terbaca(): void
    {
        $aku = $this->pengguna();
        $n   = ErpNotification::create(['user_id' => $aku->id, 'jenis' => 'x', 'judul' => 'A', 'isi' => 'b']);

        $this->actingAs($aku)->postJson(route('notifikasi.baca', $n->id))->assertOk();

        $this->assertNotNull($n->fresh()->read_at);
    }

    /**
     * Notifikasi memuat cuplikan chat pelanggan. Id yang bisa diketik di URL
     * tidak boleh membuat isinya terbaca orang lain.
     */
    public function test_tidak_bisa_menandai_notifikasi_orang_lain(): void
    {
        $aku  = $this->pengguna();
        $lain = $this->pengguna();
        $n    = ErpNotification::create(['user_id' => $lain->id, 'jenis' => 'x', 'judul' => 'A', 'isi' => 'b']);

        $this->actingAs($aku)->postJson(route('notifikasi.baca', $n->id))->assertNotFound();

        $this->assertNull($n->fresh()->read_at);
    }

    public function test_tandai_semua_hanya_menyentuh_milik_sendiri(): void
    {
        $aku  = $this->pengguna();
        $lain = $this->pengguna();

        ErpNotification::create(['user_id' => $aku->id,  'jenis' => 'x', 'judul' => 'A', 'isi' => 'b']);
        ErpNotification::create(['user_id' => $lain->id, 'jenis' => 'x', 'judul' => 'B', 'isi' => 'c']);

        $this->actingAs($aku)->postJson(route('notifikasi.baca-semua'))->assertOk();

        $this->assertSame(0, ErpNotification::milik($aku->id)->belumDibaca()->count());
        $this->assertSame(1, ErpNotification::milik($lain->id)->belumDibaca()->count());
    }

    public function test_lonceng_butuh_login(): void
    {
        $this->getJson(route('notifikasi.index'))->assertUnauthorized();
    }

    /**
     * Staf yang menunya sedikit tetap harus bisa membaca notifikasinya sendiri.
     * Kalau rutenya diikat ke satu menu, ia terkunci dari kabarnya sendiri —
     * gejalanya lonceng yang selamanya kosong.
     */
    public function test_staf_dengan_satu_menu_tetap_bisa_membuka_loncengnya(): void
    {
        $staf = $this->pengguna('user', 'crm.inbox');

        $this->actingAs($staf)->getJson(route('notifikasi.index'))->assertOk();
    }

    /** Loncengnya benar-benar dirender di halaman ERP. */
    public function test_lonceng_tampil_di_layar_crm(): void
    {
        $this->actingAs($this->pengguna('admin', null))
            ->get(route('crm.inbox.index'))
            ->assertOk()
            ->assertSee('lonceng-tombol', false);
    }
}
