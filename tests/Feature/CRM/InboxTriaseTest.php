<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmAttachment;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tahap 5 modul CRM: inbox, triase, kepemilikan.
 *
 * Yang diuji di sini adalah aturan yang menentukan apakah layar ini dipakai
 * atau ditinggalkan: penjaga jendela 24 jam (kalau lolos, admin mengetik lalu
 * ditolak API dan kembali ke HP), perpindahan antrean saat dibalas (kalau
 * tidak, daftar "Menunggu Kita" jadi sampah), dan lampiran yang tidak boleh
 * terbaca tanpa login.
 */
class InboxTriaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['crm.dry_run' => true]);
        app(ChatManager::class)->fake()->reset();
        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function percakapan(array $attrs = []): CrmConversation
    {
        $p = CrmConversation::findOrCreateFor('628998844666');

        $p->forceFill($attrs + [
            'window_expires_at' => now()->addHours(5),
            'last_message_at'   => now(),
            'queue_state'       => CrmConversation::QUEUE_KITA,
            'unread_count'      => 2,
        ])->save();

        return $p;
    }

    /* -------------------------------------------------------------------- akses */

    public function test_inbox_butuh_login(): void
    {
        $this->get(route('crm.inbox.index'))->assertRedirect(route('login'));
    }

    public function test_inbox_menampilkan_percakapan_dan_menyaring_per_antrean(): void
    {
        $this->percakapan();

        $lain = CrmConversation::findOrCreateFor('628111222333');
        $lain->forceFill(['queue_state' => CrmConversation::QUEUE_DINGIN])->save();

        $this->actingAs($this->admin())
            ->get(route('crm.inbox.index', ['antrean' => CrmConversation::QUEUE_KITA]))
            ->assertOk()
            ->assertSee('628998844666')
            ->assertDontSee('628111222333');
    }

    public function test_percakapan_tanpa_pelanggan_ditandai_lead(): void
    {
        $this->percakapan();

        $this->actingAs($this->admin())
            ->get(route('crm.inbox.index'))
            ->assertOk()
            ->assertSee('Lead');
    }

    /* --------------------------------------------------------------- buka thread */

    public function test_membuka_thread_menandai_terbaca_tapi_tidak_memindahkan_antrean(): void
    {
        $p = $this->percakapan();

        $this->actingAs($this->admin())->get(route('crm.inbox.show', $p->id))->assertOk();

        $p->refresh();
        $this->assertSame(0, $p->unread_count);
        $this->assertSame(
            CrmConversation::QUEUE_KITA,
            $p->queue_state,
            'Membaca bukan menjawab — pekerjaan yang belum dikerjakan tak boleh hilang dari daftar.'
        );
    }

    /* ------------------------------------------------------------------- balasan */

    public function test_balasan_tercatat_sebagai_pesan_erp_dan_memindahkan_bola(): void
    {
        $p     = $this->percakapan();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('crm.inbox.balas', $p->id), ['teks' => 'Baik kak, kami buatkan desainnya.'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $pesan = CrmMessage::keluar()->first();

        $this->assertSame(CrmMessage::SOURCE_ERP, $pesan->source);
        $this->assertSame($admin->id, $pesan->sent_by_user_id);
        $this->assertFalse($pesan->dibalasDariHp());

        $p->refresh();
        $this->assertSame(CrmConversation::QUEUE_PELANGGAN, $p->queue_state);
        $this->assertSame(0, $p->unread_count);
    }

    public function test_saklar_mode_aman_mencatat_balasan_tanpa_menyentuh_jaringan(): void
    {
        $p = $this->percakapan();

        $this->actingAs($this->admin())
            ->post(route('crm.inbox.balas', $p->id), ['teks' => 'halo'])
            ->assertRedirect();

        $terkirim = app(ChatManager::class)->fake()->sentOfKind('text');

        $this->assertCount(1, $terkirim);
        $this->assertSame('628998844666', $terkirim[0]['to']);
    }

    public function test_jendela_tertutup_menolak_pesan_bebas_di_server_bukan_hanya_di_layar(): void
    {
        $p = $this->percakapan(['window_expires_at' => now()->subHour()]);

        $this->actingAs($this->admin())
            ->post(route('crm.inbox.balas', $p->id), ['teks' => 'halo'])
            ->assertRedirect()
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Jendela 24 jam'));

        $this->assertSame(0, CrmMessage::count());
        $this->assertEmpty(app(ChatManager::class)->fake()->sent);
    }

    public function test_kegagalan_provider_tidak_meninggalkan_pesan_palsu_di_thread(): void
    {
        $p = $this->percakapan();

        app(ChatManager::class)->fake()->failWith = 'Saldo Meta habis';

        $this->actingAs($this->admin())
            ->post(route('crm.inbox.balas', $p->id), ['teks' => 'halo'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Saldo Meta habis'));

        $this->assertSame(0, CrmMessage::count());
    }

    /* -------------------------------------------------------------------- triase */

    public function test_percakapan_bisa_dioper_dan_dilepas(): void
    {
        $p     = $this->percakapan();
        $admin = $this->admin();
        $lain  = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)
            ->post(route('crm.inbox.oper', $p->id), ['owner_user_id' => $lain->id])
            ->assertSessionHas('success');

        $this->assertSame($lain->id, $p->fresh()->owner_user_id);

        $this->actingAs($admin)->post(route('crm.inbox.oper', $p->id), ['owner_user_id' => '']);

        $this->assertNull($p->fresh()->owner_user_id);
    }

    public function test_antrean_hanya_menerima_nilai_yang_dikenal(): void
    {
        $p = $this->percakapan();

        $this->actingAs($this->admin())
            ->post(route('crm.inbox.antrean', $p->id), ['queue_state' => 'entah'])
            ->assertSessionHasErrors('queue_state');

        $this->actingAs($this->admin())
            ->post(route('crm.inbox.antrean', $p->id), ['queue_state' => CrmConversation::QUEUE_DESAIN])
            ->assertSessionHas('success');

        $this->assertSame(CrmConversation::QUEUE_DESAIN, $p->fresh()->queue_state);
    }

    public function test_arsip_adalah_saklar_bolak_balik(): void
    {
        $p = $this->percakapan();

        $this->actingAs($this->admin())->post(route('crm.inbox.arsip', $p->id));
        $this->assertSame(CrmConversation::STATUS_ARSIP, $p->fresh()->status);

        $this->actingAs($this->admin())->post(route('crm.inbox.arsip', $p->id));
        $this->assertSame(CrmConversation::STATUS_AKTIF, $p->fresh()->status);
    }

    public function test_catatan_internal_tersimpan(): void
    {
        $p = $this->percakapan();

        $this->actingAs($this->admin())
            ->post(route('crm.inbox.catatan', $p->id), ['notes' => 'Minta logo file asli sebagai dokumen.'])
            ->assertSessionHas('success');

        $this->assertSame('Minta logo file asli sebagai dokumen.', $p->fresh()->notes);
    }

    /* ------------------------------------------------------------------ lampiran */

    public function test_lampiran_tidak_bisa_diambil_tanpa_login(): void
    {
        $lampiran = $this->lampiran();

        $this->get(route('crm.inbox.lampiran', $lampiran->id))->assertRedirect(route('login'));
    }

    public function test_lampiran_tersaji_untuk_pengguna_yang_login(): void
    {
        $lampiran = $this->lampiran();

        $this->actingAs($this->admin())
            ->get(route('crm.inbox.lampiran', $lampiran->id))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
    }

    public function test_lampiran_yang_sudah_disapu_menjelaskan_dirinya(): void
    {
        $lampiran = $this->lampiran();
        $lampiran->forceFill(['path' => null, 'disk' => null, 'downloaded_at' => null, 'purged_at' => now()])->save();

        $this->actingAs($this->admin())
            ->get(route('crm.inbox.lampiran', $lampiran->id))
            ->assertStatus(410);
    }

    private function lampiran(): CrmAttachment
    {
        $p = $this->percakapan();

        $pesan = CrmMessage::create([
            'conversation_id' => $p->id,
            'direction'       => CrmMessage::MASUK,
            'message_type'    => 'image',
            'sent_at'         => now(),
        ]);

        Storage::disk('local')->put('crm/lampiran/1.png', 'gambar-palsu');

        return CrmAttachment::create([
            'message_id'    => $pesan->id,
            'disk'          => 'local',
            'path'          => 'crm/lampiran/1.png',
            'original_name' => 'logo.png',
            'mime'          => 'image/png',
            'size_bytes'    => 12,
            'downloaded_at' => now(),
        ]);
    }
}
