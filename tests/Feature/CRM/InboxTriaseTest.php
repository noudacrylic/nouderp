<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmAttachment;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Models\CrmSnippet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    /**
     * Balasan di mode aman TIDAK boleh mengaku "terkirim".
     *
     * Ini bukan soal kosmetik: gelembung yang mengaku terkirim membuat admin
     * mengira pelanggan sudah dijawab, lalu menunggu balasan yang tak akan
     * pernah datang — dan itu justru terjadi di masa uji coba, saat saklarnya
     * memang sengaja menyala.
     */
    public function test_balasan_mode_aman_ditandai_tidak_dikirim(): void
    {
        config(['crm.dry_run' => true]);

        $percakapan = $this->percakapan(['window_expires_at' => now()->addHours(5)]);

        $this->actingAs($this->admin())
            ->post(route('crm.inbox.balas', $percakapan), ['teks' => 'halo kak'])
            ->assertRedirect();

        $pesan = CrmMessage::where('direction', CrmMessage::KELUAR)->latest('id')->firstOrFail();

        $this->assertSame(CrmMessage::STATUS_TIDAK_DIKIRIM, $pesan->status);
        $this->assertNotSame('terkirim', $pesan->status);
    }

    /* ------------------------------------------------- tempel gambar ke chat */

    /**
     * Tempel tangkapan layar langsung ke kotak balasan.
     *
     * Yang dijaga bukan cuma "gambarnya terkirim", melainkan bahwa berkasnya
     * DISIMPAN DI DISK KITA juga. `media_id` vendor kedaluwarsa 30 hari,
     * sedangkan revisi desain justru yang paling sering dibuka lagi berbulan
     * kemudian ("yang kemarin itu versi mana?") — kalau hanya mengandalkan
     * media_id, riwayat desainnya lenyap tanpa ada yang sadar.
     */
    public function test_gambar_yang_ditempel_terkirim_dan_tersimpan_di_disk_sendiri(): void
    {
        $percakapan = $this->percakapan(['window_expires_at' => now()->addHours(5)]);

        $this->actingAs($this->admin())
            ->post(route('crm.inbox.balas', $percakapan), [
                'teks'   => 'ini acuan desainnya kak',
                'gambar' => [UploadedFile::fake()->image('tangkapan.png')],
            ])
            ->assertRedirect();

        $pesan = CrmMessage::where('direction', CrmMessage::KELUAR)->latest('id')->firstOrFail();

        $this->assertSame('image', $pesan->message_type);
        $this->assertSame('ini acuan desainnya kak', $pesan->content);

        $lampiran = $pesan->attachments()->firstOrFail();

        $this->assertTrue($lampiran->tersimpanAman());
        $this->assertSame('fake-media-1', $lampiran->provider_media_id);
        Storage::disk('local')->assertExists($lampiran->path);

        $fake = app(ChatManager::class)->fake();

        $this->assertCount(1, $fake->uploaded);
        $this->assertCount(1, $fake->sentOfKind('media'));
    }

    /**
     * Beberapa gambar sekaligus: caption hanya menempel di gambar PERTAMA.
     * Kalau diulang, pelanggan menerima kalimat yang sama berkali-kali.
     */
    public function test_caption_hanya_di_gambar_pertama(): void
    {
        $percakapan = $this->percakapan(['window_expires_at' => now()->addHours(5)]);

        $this->actingAs($this->admin())
            ->post(route('crm.inbox.balas', $percakapan), [
                'teks'   => 'dua acuan ini ya kak',
                'gambar' => [
                    UploadedFile::fake()->image('satu.png'),
                    UploadedFile::fake()->image('dua.png'),
                ],
            ])
            ->assertRedirect();

        $keluar = CrmMessage::where('direction', CrmMessage::KELUAR)->orderBy('id')->get();

        $this->assertCount(2, $keluar);
        $this->assertSame('dua acuan ini ya kak', $keluar[0]->content);
        $this->assertNull($keluar[1]->content);
    }

    /** Gambar tanpa satu kata pun tetap sah — admin sering begitu. */
    public function test_gambar_tanpa_teks_diterima(): void
    {
        $percakapan = $this->percakapan(['window_expires_at' => now()->addHours(5)]);

        $this->actingAs($this->admin())
            ->post(route('crm.inbox.balas', $percakapan), [
                'gambar' => [UploadedFile::fake()->image('polos.png')],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, CrmMessage::where('direction', CrmMessage::KELUAR)->count());
    }

    public function test_kirim_tanpa_teks_maupun_gambar_ditolak(): void
    {
        $percakapan = $this->percakapan(['window_expires_at' => now()->addHours(5)]);

        $this->actingAs($this->admin())
            ->post(route('crm.inbox.balas', $percakapan), [])
            ->assertSessionHasErrors('teks');

        $this->assertSame(0, CrmMessage::where('direction', CrmMessage::KELUAR)->count());
    }

    /**
     * Jendela tertutup menolak gambar juga, bukan cuma teks — kalau tidak,
     * admin menempel desain besar lalu ditolak API setelah berkasnya terunggah.
     */
    public function test_gambar_ditolak_saat_jendela_tertutup(): void
    {
        $percakapan = $this->percakapan(['window_expires_at' => now()->subHour()]);

        $this->actingAs($this->admin())
            ->post(route('crm.inbox.balas', $percakapan), [
                'gambar' => [UploadedFile::fake()->image('telat.png')],
            ])
            ->assertRedirect();

        $this->assertSame(0, CrmMessage::where('direction', CrmMessage::KELUAR)->count());
        $this->assertEmpty(app(ChatManager::class)->fake()->uploaded);
    }

    /* ----------------------------------------------------- cakupan per agen */

    private function agen(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    /**
     * Daftar bawaan agen biasa hanya berisi chat miliknya.
     * Ini penyaringan TAMPILAN, bukan penguncian — lihat dua tes berikutnya.
     */
    public function test_agen_biasa_hanya_melihat_chat_miliknya_secara_bawaan(): void
    {
        $munif = $this->agen();
        $lain  = $this->agen();

        $milikMunif = $this->percakapan(['owner_user_id' => $munif->id]);
        $milikLain  = CrmConversation::findOrCreateFor('628111222333');
        $milikLain->forceFill(['owner_user_id' => $lain->id, 'status' => CrmConversation::STATUS_AKTIF])->save();

        $this->actingAs($munif)
            ->get(route('crm.inbox.index'))
            ->assertOk()
            ->assertSee($milikMunif->contact_key)
            ->assertDontSee($milikLain->contact_key);
    }

    /** Super admin melihat semuanya tanpa perlu memilih apa pun. */
    public function test_super_admin_melihat_semua_agen(): void
    {
        $lain      = $this->agen();
        $milikLain = $this->percakapan(['owner_user_id' => $lain->id]);

        $this->actingAs($this->admin())
            ->get(route('crm.inbox.index'))
            ->assertOk()
            ->assertSee($milikLain->contact_key);
    }

    /**
     * Pembatas bawaan harus bisa dilepas. Saat satu orang berhalangan, chat
     * pelanggannya tidak boleh jadi tak terlihat siapa pun.
     */
    public function test_agen_biasa_bisa_melepas_pembatas_dengan_memilih_semua(): void
    {
        $munif     = $this->agen();
        $lain      = $this->agen();
        $milikLain = $this->percakapan(['owner_user_id' => $lain->id]);

        $this->actingAs($munif)
            ->get(route('crm.inbox.index', ['pemilik' => 'semua']))
            ->assertOk()
            ->assertSee($milikLain->contact_key);
    }

    /**
     * Percakapan baru lahir TANPA pemilik. Kalau ia juga tersembunyi dari
     * daftar bawaan, pelanggan baru menganggur tanpa ada yang tahu — karena
     * itu jumlahnya selalu ditampilkan sebagai tautan pintas.
     */
    public function test_chat_belum_dioper_selalu_terlihat_sebagai_pintasan(): void
    {
        $this->percakapan(['owner_user_id' => null]);

        $this->actingAs($this->agen())
            ->get(route('crm.inbox.index'))
            ->assertOk()
            // Yang dijaga keberadaan PINTASANNYA, bukan bunyi kalimatnya —
            // kolom kiri sempit, jadi teksnya memang diringkas.
            ->assertSee('pemilik=belum', false)
            ->assertSee('belum dioper');
    }

    /** Tautan langsung ke thread milik orang lain tetap boleh dibuka. */
    public function test_thread_milik_agen_lain_tetap_bisa_dibuka(): void
    {
        $lain      = $this->agen();
        $milikLain = $this->percakapan(['owner_user_id' => $lain->id]);

        $this->actingAs($this->agen())
            ->get(route('crm.inbox.show', $milikLain))
            ->assertOk();
    }

    /* ------------------------------------------------------ potongan balasan */

    /**
     * Potongan balasan ("template teks") — milik kita, gratis, hanya sah di
     * DALAM jendela 24 jam. Bukan template Meta; menyatukan keduanya di satu
     * daftar tanpa penanda membuat admin memilih yang salah tiap hari.
     */
    public function test_potongan_balasan_tersimpan_dan_tampil_di_rail(): void
    {
        $this->actingAs($this->admin())
            ->post(route('crm.snippet.store'), [
                'title'    => 'Sapaan awal',
                'category' => 'Sapaan',
                'body'     => 'Halo {nama}, terima kasih sudah menghubungi Noud Acrylic.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('crm_snippets', ['title' => 'Sapaan awal']);

        $this->actingAs($this->admin())
            ->get(route('crm.inbox.index'))
            ->assertOk()
            ->assertSee('Sapaan awal');
    }

    /**
     * Isian disusun saat disisipkan, dan yang tak punya jawaban DIKOSONGKAN —
     * kalimat yang bocor kurung kurawal ke pelanggan jauh lebih memalukan
     * daripada kalimat yang kehilangan satu keterangan.
     */
    public function test_isian_potongan_disusun_dan_yang_kosong_tidak_bocor(): void
    {
        $percakapan = $this->percakapan(['window_expires_at' => now()->addHours(5)]);

        $potongan = CrmSnippet::create([
            'title' => 'Tindak lanjut',
            'body'  => 'Halo {nama}, pesanan {pesanan} sedang kami proses. - {admin}',
        ]);

        $hasil = $potongan->render($percakapan, 'Munif');

        $this->assertStringContainsString('628998844666', $hasil);
        $this->assertStringContainsString('Munif', $hasil);
        $this->assertStringNotContainsString('{', $hasil);
    }

    /* ------------------------------------------------------------ thread hidup */

    /**
     * Kotak ketik mengirim lewat fetch, jadi jawabannya JSON berisi gelembung
     * siap tempel. Tanpa ini seluruh halaman dimuat ulang tiap kali mengirim —
     * posisi gulir hilang, thread berkedip, dan mengetik cepat jadi menyiksa.
     */
    public function test_kirim_lewat_json_mengembalikan_gelembung_siap_tempel(): void
    {
        $percakapan = $this->percakapan(['window_expires_at' => now()->addHours(5)]);
        $sebelum    = (int) CrmMessage::max('id');

        $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.balas', $percakapan), [
                'teks'  => 'baik kak, saya cek dulu',
                'after' => $sebelum,
            ])
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['success', 'html', 'last_id'])
            ->assertJsonPath('html', fn ($html) => str_contains($html, 'baik kak, saya cek dulu'));
    }

    public function test_kirim_lewat_json_yang_gagal_menjelaskan_sebabnya(): void
    {
        // Jendela tertutup: ditolak di server, bukan cuma disembunyikan di layar.
        $percakapan = $this->percakapan(['window_expires_at' => now()->subHour()]);

        $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.balas', $percakapan), ['teks' => 'halo'])
            ->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonPath('error', fn ($e) => str_contains($e, '24 jam'));

        $this->assertSame(0, CrmMessage::where('direction', CrmMessage::KELUAR)->count());
    }

    /**
     * Penarik berkala HANYA mengembalikan yang lebih baru dari id yang dikirim.
     * Kalau ia mengembalikan semuanya, tiap siklus akan menggandakan seluruh
     * thread di layar.
     */
    public function test_penarik_hanya_mengembalikan_pesan_setelah_id_tertentu(): void
    {
        $percakapan = $this->percakapan(['window_expires_at' => now()->addHours(5)]);

        $lama = CrmMessage::create([
            'conversation_id' => $percakapan->id,
            'direction'       => CrmMessage::MASUK,
            'message_type'    => 'text',
            'content'         => 'pesan lama',
            'sent_at'         => now()->subMinutes(10),
        ]);

        $baru = CrmMessage::create([
            'conversation_id' => $percakapan->id,
            'direction'       => CrmMessage::MASUK,
            'message_type'    => 'text',
            'content'         => 'pesan baru',
            'sent_at'         => now(),
        ]);

        $this->actingAs($this->admin())
            ->getJson(route('crm.inbox.pesan-baru', [$percakapan, 'after' => $lama->id]))
            ->assertOk()
            ->assertJsonPath('last_id', $baru->id)
            ->assertJsonPath('html', fn ($html) => str_contains($html, 'pesan baru')
                && ! str_contains($html, 'pesan lama'));
    }

    public function test_penarik_tanpa_pesan_baru_tidak_mengembalikan_apa_apa(): void
    {
        $percakapan = $this->percakapan();
        $terakhir   = (int) CrmMessage::max('id');

        $this->actingAs($this->admin())
            ->getJson(route('crm.inbox.pesan-baru', [$percakapan, 'after' => $terakhir]))
            ->assertOk()
            ->assertJsonPath('html', '');
    }
}
