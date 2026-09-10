<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmAttachment;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Models\CrmTemplate;
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
        Storage::disk('local')->assertExists($lampiran->path);

        $kirim = app(ChatManager::class)->fake()->sentOfKind('media');

        $this->assertCount(1, $kirim);

        /*
         * Yang dikirim ke vendor adalah TAUTAN bertanda tangan, bukan berkasnya
         * maupun media_id: api.co.id hanya menerima `media_url`, dan Meta yang
         * mengambil sendiri berkasnya dari alamat itu.
         */
        $this->assertStringContainsString('/crm/media/' . $lampiran->id, $kirim[0]['media']);
        $this->assertStringContainsString('signature=', $kirim[0]['media']);
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
            ->assertSee('Belum dioper');
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
    public function test_potongan_balasan_tersimpan_dan_tampil_di_kotak_ketik(): void
    {
        // Tempatnya di kotak ketik (pintasan "/"), bukan lagi di rail kanan —
        // jadi ia hanya ada saat sebuah percakapan dibuka DAN jendelanya masih
        // terbuka. Di luar jendela potongan gratis memang tidak sah dikirim.
        $percakapan = $this->percakapan();

        $this->actingAs($this->admin())
            ->post(route('crm.template.cepat.store'), [
                'title'    => 'Sapaan awal',
                'category' => 'Sapaan',
                'body'     => 'Halo {nama}, terima kasih sudah menghubungi Noud Acrylic.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('crm_templates', ['title' => 'Sapaan awal']);

        $this->actingAs($this->admin())
            ->get(route('crm.inbox.show', $percakapan->id))
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

        $potongan = CrmTemplate::create([
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

    /**
     * Centang ala WhatsApp harus IKUT polling, bukan sekali gambar saat halaman
     * dibuka.
     *
     * Status 'delivered'/'read' datang dari webhook bermenit setelah pesannya
     * keluar. Kalau penarik ini tidak membawanya, centangnya berhenti di satu
     * selamanya dan admin menyimpulkan pesannya tidak sampai — lalu mengirim
     * ulang lewat HP.
     */
    public function test_penarik_membawa_status_centang_pesan_keluar(): void
    {
        $percakapan = $this->percakapan();

        $terkirim = $this->pesanKeluar($percakapan, 'terkirim');
        $sampai   = $this->pesanKeluar($percakapan, 'delivered');
        $dibaca   = $this->pesanKeluar($percakapan, 'read');

        $this->actingAs($this->admin())
            ->getJson(route('crm.inbox.pesan-baru', [$percakapan, 'after' => (int) CrmMessage::max('id')]))
            ->assertOk()
            ->assertJsonPath('centang.' . $terkirim->id, 'terkirim')
            ->assertJsonPath('centang.' . $sampai->id, 'sampai')
            ->assertJsonPath('centang.' . $dibaca->id, 'dibaca');
    }

    /**
     * Pesan yang TIDAK PERNAH keluar tidak boleh punya centang.
     *
     * Satu centang di sebelah balasan mode-aman berarti berbohong tentang hal
     * yang paling mahal salahnya: apakah pelanggan sudah dijawab. Lencana
     * bertulis yang sudah ada biar yang bicara.
     */
    public function test_pesan_gagal_dan_tidak_dikirim_tidak_punya_centang(): void
    {
        $percakapan = $this->percakapan();

        $gagal        = $this->pesanKeluar($percakapan, 'failed');
        $tidakDikirim = $this->pesanKeluar($percakapan, CrmMessage::STATUS_TIDAK_DIKIRIM);
        $masuk        = CrmMessage::create([
            'conversation_id' => $percakapan->id,
            'direction'       => CrmMessage::MASUK,
            'message_type'    => 'text',
            'content'         => 'halo',
            'sent_at'         => now(),
        ]);

        $centang = $this->actingAs($this->admin())
            ->getJson(route('crm.inbox.pesan-baru', [$percakapan, 'after' => (int) CrmMessage::max('id')]))
            ->assertOk()
            ->json('centang');

        $this->assertArrayNotHasKey($gagal->id, $centang);
        $this->assertArrayNotHasKey($tidakDikirim->id, $centang);
        $this->assertArrayNotHasKey($masuk->id, $centang);
    }

    /* --------------------------------------------------- zona jatuh lampiran */

    /**
     * Berkas boleh dilepas di MANA SAJA di kolom percakapan.
     *
     * Sebelumnya zonanya cuma form kotak ketik — strip setinggi 40px di dasar
     * layar. Yang dilihat orang saat menyeret berkas adalah percakapannya, jadi
     * melepas di situ tidak terjadi apa-apa dan fiturnya dianggap tidak ada.
     */
    public function test_zona_jatuh_berkas_menutupi_seluruh_kolom_percakapan(): void
    {
        $percakapan = $this->percakapan(['window_expires_at' => now()->addHours(5)]);

        $this->actingAs($this->admin())
            ->get(route('crm.inbox.show', $percakapan))
            ->assertOk()
            ->assertSee('jatuhBerkas($event)', false)
            ->assertSee('Lepas untuk melampirkan');
    }

    /**
     * Jendela tertutup = kotak ketik tidak dirender sama sekali.
     *
     * Kalau zonanya tetap dipasang, berkas yang dilepas ditelan tanpa jejak:
     * tidak masuk ke mana pun, tidak ada pesan galat, dan admin mengira
     * lampirannya sudah siap dikirim.
     */
    public function test_zona_jatuh_tidak_dipasang_saat_jendela_tertutup(): void
    {
        $percakapan = $this->percakapan(['window_expires_at' => now()->subHour()]);

        $this->actingAs($this->admin())
            ->get(route('crm.inbox.show', $percakapan))
            ->assertOk()
            ->assertDontSee('jatuhBerkas($event)', false)
            ->assertDontSee('Lepas untuk melampirkan');
    }

    /* ------------------------------------------------- peringatan webhook sepi */

    /**
     * Webhook yang patah TIDAK boleh diam.
     *
     * Ini satu-satunya kerusakan CRM yang layarnya tetap terlihat sehat:
     * mengirim tetap berhasil, daftar tetap rapi, cuma tidak ada yang masuk —
     * sama persis dengan hari sepi. Kejadian yang gagal diantar tidak pernah
     * dikirim ulang vendor, jadi tiap jam diamnya adalah balasan pelanggan yang
     * hilang untuk selamanya.
     */
    public function test_peringatan_muncul_saat_pesan_keluar_tidak_pernah_dapat_kabar_balik(): void
    {
        config(['crm.dry_run' => false]);
        $percakapan = $this->percakapan();

        $pesan = $this->pesanKeluar($percakapan, 'terkirim');
        $pesan->forceFill(['sent_at' => now()->subHours(3)])->save();

        $this->actingAs($this->admin())
            ->get(route('crm.inbox.index'))
            ->assertOk()
            ->assertSee('Pesan masuk kemungkinan tidak sampai');
    }

    /** Ada kabar SESUDAH kiriman terakhir = jalurnya hidup; jangan berisik. */
    public function test_peringatan_diam_saat_kabar_balik_datang_setelah_kiriman(): void
    {
        config(['crm.dry_run' => false]);
        $percakapan = $this->percakapan();

        $pesan = $this->pesanKeluar($percakapan, 'terkirim');
        $pesan->forceFill(['sent_at' => now()->subHours(3)])->save();

        \App\Modules\CRM\Models\CrmWebhookEvent::create([
            'idempotency_key' => 'uji-kabar-1',
            'event_type'      => 'message.sent',
            'payload'         => [],
            'created_at'      => now()->subHours(2),
            'updated_at'      => now()->subHours(2),
        ]);

        $this->actingAs($this->admin())
            ->get(route('crm.inbox.index'))
            ->assertOk()
            ->assertDontSee('Pesan masuk kemungkinan tidak sampai');
    }

    /**
     * Mode aman tidak memancing kabar balik apa pun, jadi pemeriksanya dilewati.
     * Pita yang menyala terus berhenti dipercaya justru saat betulan rusak.
     */
    public function test_peringatan_diam_saat_mode_aman_menyala(): void
    {
        config(['crm.dry_run' => true]);
        $percakapan = $this->percakapan();

        $pesan = $this->pesanKeluar($percakapan, 'terkirim');
        $pesan->forceFill(['sent_at' => now()->subHours(3)])->save();

        $this->actingAs($this->admin())
            ->get(route('crm.inbox.index'))
            ->assertOk()
            ->assertDontSee('Pesan masuk kemungkinan tidak sampai');
    }

    /** Kiriman yang baru saja keluar belum layak dicurigai. */
    public function test_peringatan_diam_untuk_kiriman_yang_masih_baru(): void
    {
        config(['crm.dry_run' => false]);
        $percakapan = $this->percakapan();

        $this->pesanKeluar($percakapan, 'terkirim');

        $this->actingAs($this->admin())
            ->get(route('crm.inbox.index'))
            ->assertOk()
            ->assertDontSee('Pesan masuk kemungkinan tidak sampai');
    }

    /**
     * Cetakan centang WAJIB ada di halaman thread.
     *
     * Gelembung sementara mengklonnya lewat id ini. Kalau id-nya hilang atau
     * berganti nama, yang rusak tidak kelihatan di mana pun kecuali saat
     * mengirim: gelembungnya muncul TANPA tanda apa pun, dan admin kembali
     * menebak-nebak apakah pesannya sudah keluar — persis keadaan yang mau
     * dihapus fitur ini.
     */
    public function test_layar_thread_menyediakan_cetakan_centang_dan_status_pesan(): void
    {
        $percakapan = $this->percakapan();
        $this->pesanKeluar($percakapan, 'delivered');

        $this->actingAs($this->admin())
            ->get(route('crm.inbox.show', $percakapan))
            ->assertOk()
            ->assertSee('id="crm-centang-cetak"', false)
            ->assertSee('data-status="sampai"', false);
    }

    /**
     * "Coba lagi" di gelembung yang gagal tidak boleh melahirkan pesan kedua.
     *
     * Kegagalan yang paling sering bukan penolakan vendor, melainkan jawaban
     * yang hilang di jalan — pesannya sendiri sudah keluar. Tanpa penjaga ini
     * satu klik berarti pelanggan menerima kalimat yang sama dua kali, dan
     * pesan yang sudah terkirim tidak bisa ditarik kembali.
     */
    public function test_kirim_ulang_dengan_kunci_sama_tidak_melahirkan_pesan_kedua(): void
    {
        $percakapan = $this->percakapan();
        $admin      = $this->admin();
        $muatan     = ['teks' => 'Baik kak, kami buatkan.', 'kirim_key' => 'kunci-abc'];

        $this->actingAs($admin)->postJson(route('crm.inbox.balas', $percakapan), $muatan)->assertOk();
        $this->actingAs($admin)->postJson(route('crm.inbox.balas', $percakapan), $muatan)->assertOk();

        $this->assertSame(1, CrmMessage::keluar()->where('conversation_id', $percakapan->id)->count());
    }

    /** Kunci berbeda = pesan berbeda; penjaganya tidak boleh menelan balasan sah. */
    public function test_kunci_berbeda_tetap_melahirkan_dua_pesan(): void
    {
        $percakapan = $this->percakapan();
        $admin      = $this->admin();

        $this->actingAs($admin)->postJson(route('crm.inbox.balas', $percakapan),
            ['teks' => 'satu', 'kirim_key' => 'kunci-1'])->assertOk();
        $this->actingAs($admin)->postJson(route('crm.inbox.balas', $percakapan),
            ['teks' => 'dua', 'kirim_key' => 'kunci-2'])->assertOk();

        $this->assertSame(2, CrmMessage::keluar()->where('conversation_id', $percakapan->id)->count());
    }

    private function pesanKeluar(CrmConversation $percakapan, string $status): CrmMessage
    {
        return CrmMessage::create([
            'conversation_id' => $percakapan->id,
            'direction'       => CrmMessage::KELUAR,
            'message_type'    => 'text',
            'content'         => 'balasan ' . $status,
            'status'          => $status,
            'sent_at'         => now(),
        ]);
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

    /**
     * Rute publik lampiran HANYA melayani arah KELUAR.
     *
     * Ini batas yang memisahkan "berkas yang memang sedang kami kirim" dari
     * "seluruh isi lampiran pelanggan". Tanda tangan yang sah sekalipun tidak
     * boleh membuka kiriman pelanggan ke internet.
     */
    public function test_tautan_publik_menolak_lampiran_masuk(): void
    {
        $percakapan = $this->percakapan();

        $pesanMasuk = CrmMessage::create([
            'conversation_id' => $percakapan->id,
            'direction'       => CrmMessage::MASUK,
            'message_type'    => 'image',
            'sent_at'         => now(),
        ]);

        $lampiran = \App\Modules\CRM\Models\CrmAttachment::create([
            'message_id'    => $pesanMasuk->id,
            'disk'          => 'local',
            'path'          => 'crm/lampiran/uji.png',
            'original_name' => 'uji.png',
            'mime'          => 'image/png',
            'downloaded_at' => now(),
        ]);

        Storage::disk('local')->put('crm/lampiran/uji.png', 'isi');

        $this->get(\Illuminate\Support\Facades\URL::temporarySignedRoute(
            'crm.media', now()->addMinutes(10), ['attachment' => $lampiran->id]
        ))->assertNotFound();
    }

    public function test_tautan_publik_tanpa_tanda_tangan_ditolak(): void
    {
        $percakapan = $this->percakapan(['window_expires_at' => now()->addHours(5)]);

        $this->actingAs($this->admin())
            ->post(route('crm.inbox.balas', $percakapan), [
                'gambar' => [UploadedFile::fake()->image('desain.png')],
            ])->assertRedirect();

        $lampiran = \App\Modules\CRM\Models\CrmAttachment::firstOrFail();

        // Tanpa tanda tangan: 403, bukan berkasnya.
        $this->get(url('/crm/media/' . $lampiran->id))->assertForbidden();
    }
}
