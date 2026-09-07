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
 * Balas (kutipan) & teruskan pesan.
 *
 * Dua fitur ini hanya satu-satunya di antara empat kebiasaan WhatsApp yang
 * benar-benar SAMPAI ke pelanggan — Cloud API tidak punya endpoint sunting
 * maupun tarik pesan. Karena itu yang dijaga di sini justru batas-batasnya:
 *
 *  - kutipan dikirim memakai id VENDOR, bukan id baris kita (vendor menolak
 *    id yang tak dikenalnya, dan penolakannya tak terbaca admin);
 *  - teruskan MENYALIN berkasnya, tidak menumpang lampiran lama (penyapu masa
 *    simpan bekerja per percakapan asal, jadi yang menumpang akan kehilangan
 *    gambarnya di chat tujuan);
 *  - jendela 24 jam TUJUAN yang menentukan, bukan jendela chat asal.
 */
class BalasTeruskanTest extends TestCase
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

    private function percakapan(string $nomor = '628998844666', bool $terbuka = true): CrmConversation
    {
        $p = CrmConversation::findOrCreateFor($nomor);

        $p->forceFill([
            'window_expires_at' => $terbuka ? now()->addHours(5) : now()->subHour(),
            'last_message_at'   => now(),
            'queue_state'       => CrmConversation::QUEUE_KITA,
        ])->save();

        return $p;
    }

    private function pesanMasuk(CrmConversation $p, array $attrs = []): CrmMessage
    {
        return CrmMessage::create($attrs + [
            'conversation_id'     => $p->id,
            'direction'           => CrmMessage::MASUK,
            'message_type'        => 'text',
            'content'             => 'Ukuran kotak sarannya berapa ya?',
            'provider_message_id' => 'vendor-masuk-1',
            'wam_id'              => 'wamid.MASUK1',
            'sent_at'             => now()->subMinutes(5),
        ]);
    }

    private function pesanKeluar(CrmConversation $p, array $attrs = []): CrmMessage
    {
        return CrmMessage::create($attrs + [
            'conversation_id'     => $p->id,
            'direction'           => CrmMessage::KELUAR,
            'message_type'        => 'text',
            'content'             => 'Harganya Rp 96.950.',
            'provider_message_id' => 'vendor-keluar-1',
            // Wamid pesan keluar baru ada setelah SAMPAI; di sini pesannya
            // memang sudah terkirim & tersampaikan.
            'wam_id'              => 'wamid.KELUAR1',
            'status'              => 'terkirim',
            'sent_at'             => now()->subMinutes(3),
        ]);
    }

    /* ------------------------------------------------------------------ balas */

    public function test_balasan_mengutip_mengirim_wamid_dan_menyimpannya(): void
    {
        $percakapan = $this->percakapan();
        $dikutip    = $this->pesanMasuk($percakapan);

        $this->actingAs($this->admin())
            ->post(route('crm.inbox.balas', $percakapan->id), [
                'teks'     => '40 x 30 cm.',
                'reply_to' => $dikutip->idKutipan(),
            ])
            ->assertRedirect();

        $terkirim = app(ChatManager::class)->fake()->lastSent();

        /*
         * WAMID, bukan id vendor. Uji kirim 7 Sep 2026 membuktikan bedanya
         * menentukan: penanda balasan berisi id vendor diterima API dengan
         * jawaban `success`, pesannya terkirim, centangnya naik — lalu
         * kutipannya diabaikan diam-diam. Di ERP tergambar rapi, di HP
         * pelanggan datang polos. Tidak ada gejala apa pun yang menandainya,
         * jadi tes inilah satu-satunya yang menjaga.
         */
        $this->assertSame('wamid.MASUK1', $terkirim['reply_to'] ?? null);

        $balasan = CrmMessage::keluar()->latest('id')->first();
        $this->assertSame('wamid.MASUK1', $balasan->reply_to_wam_id);
    }

    public function test_pesan_keluar_yang_belum_sampai_belum_bisa_dikutip(): void
    {
        $percakapan = $this->percakapan();

        // Baru 'sent': wamid-nya menumpang webhook 'delivered', jadi belum ada.
        $baru = $this->pesanKeluar($percakapan, ['wam_id' => null]);

        $this->assertFalse($baru->bisaDikutip());
        $this->assertNull($baru->idKutipan());
    }

    public function test_wamid_pesan_keluar_dipungut_dari_webhook_delivered(): void
    {
        $percakapan = $this->percakapan();
        $keluar     = $this->pesanKeluar($percakapan, ['wam_id' => null, 'status' => 'terkirim']);

        /*
         * Inilah SATU-SATUNYA tempat wamid pesan keluar bisa didapat: jawaban
         * vendor saat mengirim hanya membawa id internalnya. Sebelum 7 Sep 2026
         * nilai ini dibuang, dan akibatnya pesan kita sendiri tidak pernah bisa
         * dikutip maupun diralat.
         */
        app(\App\Modules\CRM\Services\IncomingWebhookService::class)->tangani([
            'event_type' => 'message.delivered',
            'timestamp'  => now()->toIso8601String(),
            'data'       => [
                'message_id'     => 'vendor-keluar-1',
                'customer_phone' => $percakapan->contact_key,
                'direction'      => 'outbound',
                'raw'            => ['id' => 'wamid.DARIWEBHOOK'],
            ],
        ]);

        $keluar->refresh();

        $this->assertSame('wamid.DARIWEBHOOK', $keluar->wam_id);
        $this->assertSame('delivered', $keluar->status);
    }

    public function test_relasi_kutipan_menemukan_pesan_asalnya(): void
    {
        $percakapan = $this->percakapan();
        $dikutip    = $this->pesanMasuk($percakapan);

        $balasan = CrmMessage::create([
            'conversation_id' => $percakapan->id,
            'direction'       => CrmMessage::KELUAR,
            'message_type'    => 'text',
            'content'         => '40 x 30 cm.',
            'reply_to_wam_id' => 'wamid.MASUK1',
            'sent_at'         => now(),
        ]);

        $this->assertTrue($balasan->pesanDikutip()->is($dikutip));
        $this->assertSame('Ukuran kotak sarannya berapa ya?', $balasan->pesanDikutip()->ringkas());
    }

    public function test_kutipan_warisan_beridkan_vendor_tetap_ketemu(): void
    {
        $percakapan = $this->percakapan();
        $dikutip    = $this->pesanMasuk($percakapan);

        /*
         * Baris dari sebelum 7 Sep 2026 menyimpan id VENDOR di kolom yang sama.
         * Tetap harus ketemu — kalau tidak, kutipan di riwayat lama berubah jadi
         * "pesan tidak ada di riwayat ERP" padahal pesannya ada di layar.
         */
        $lama = CrmMessage::create([
            'conversation_id' => $percakapan->id,
            'direction'       => CrmMessage::KELUAR,
            'message_type'    => 'text',
            'content'         => 'balasan lama',
            'reply_to_wam_id' => 'vendor-masuk-1',
            'sent_at'         => now(),
        ]);

        $this->assertTrue($lama->pesanDikutip()->is($dikutip));
    }

    public function test_kutipan_ke_pesan_yang_tak_ada_tidak_merusak_thread(): void
    {
        $percakapan = $this->percakapan();

        /*
         * Terjadi sungguhan: pelanggan mengutip pesan dari sebelum ERP ini
         * dipakai. Gelembungnya tetap harus tergambar dengan keterangan, bukan
         * jadi kotak melompong yang terbaca sebagai tampilan rusak.
         */
        CrmMessage::create([
            'conversation_id' => $percakapan->id,
            'direction'       => CrmMessage::MASUK,
            'message_type'    => 'text',
            'content'         => 'Yang ini maksud saya',
            'reply_to_wam_id' => 'vendor-entah-apa',
            'sent_at'         => now(),
        ]);

        $this->actingAs($this->admin())
            ->get(route('crm.inbox.show', $percakapan->id))
            ->assertOk()
            ->assertSee('Pesan yang dikutip tidak ada di riwayat ERP.');
    }

    public function test_pesan_tanpa_teks_tetap_punya_bunyi_di_kutipan(): void
    {
        $percakapan = $this->percakapan();

        $foto = CrmMessage::create([
            'conversation_id' => $percakapan->id,
            'direction'       => CrmMessage::MASUK,
            'message_type'    => 'image',
            'content'         => null,
            'sent_at'         => now(),
        ]);

        $this->assertSame('📷 Foto', $foto->ringkas());
    }

    public function test_pesan_tanpa_wamid_tidak_bisa_dikutip(): void
    {
        $percakapan = $this->percakapan();

        // Pesan mode aman: tercatat, tapi tak pernah keluar — tak ada wamid,
        // jadi tak ada yang bisa dikutip.
        CrmMessage::create([
            'conversation_id'     => $percakapan->id,
            'direction'           => CrmMessage::KELUAR,
            'message_type'        => 'text',
            'content'             => 'Halo',
            'provider_message_id' => null,
            'wam_id'              => null,
            'status'              => CrmMessage::STATUS_TIDAK_DIKIRIM,
            'sent_at'             => now(),
        ]);

        $this->actingAs($this->admin())
            ->get(route('crm.inbox.show', $percakapan->id))
            ->assertOk()
            ->assertSee('data-bisa-kutip="0"', false);
    }

    /* --------------------------------------------------------------- teruskan */

    public function test_meneruskan_teks_membuat_pesan_baru_di_tujuan(): void
    {
        $asal   = $this->percakapan();
        $tujuan = $this->percakapan('628111222333');
        $pesan  = $this->pesanMasuk($asal);

        $this->actingAs($this->admin())
            ->postJson(route('crm.pesan.teruskan', $pesan->id), ['tujuan_id' => $tujuan->id])
            ->assertOk()
            ->assertJson(['success' => true]);

        $baru = $tujuan->messages()->latest('id')->first();

        $this->assertSame('Ukuran kotak sarannya berapa ya?', $baru->content);
        $this->assertSame(CrmMessage::KELUAR, $baru->direction);
        $this->assertSame($pesan->id, $baru->forwarded_from_message_id);

        // Bola pindah ke pelanggan tujuan — kalau tidak, chat yang barusan kita
        // kirimi tetap menumpuk di "Menunggu Kita".
        $this->assertSame(CrmConversation::QUEUE_PELANGGAN, $tujuan->fresh()->queue_state);
    }

    public function test_meneruskan_ditolak_bila_jendela_tujuan_tertutup(): void
    {
        $asal   = $this->percakapan();
        $tujuan = $this->percakapan('628111222333', terbuka: false);
        $pesan  = $this->pesanMasuk($asal);

        $this->actingAs($this->admin())
            ->postJson(route('crm.pesan.teruskan', $pesan->id), ['tujuan_id' => $tujuan->id])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, $tujuan->messages()->count());
    }

    public function test_tidak_bisa_meneruskan_ke_percakapan_yang_sama(): void
    {
        $percakapan = $this->percakapan();
        $pesan      = $this->pesanMasuk($percakapan);

        $this->actingAs($this->admin())
            ->postJson(route('crm.pesan.teruskan', $pesan->id), ['tujuan_id' => $percakapan->id])
            ->assertStatus(422);

        $this->assertSame(1, $percakapan->messages()->count());
    }

    public function test_lampiran_yang_diteruskan_disalin_jadi_berkas_sendiri(): void
    {
        $asal   = $this->percakapan();
        $tujuan = $this->percakapan('628111222333');
        $pesan  = $this->pesanMasuk($asal, ['message_type' => 'image', 'content' => 'Acuannya begini']);

        $lampiran = CrmAttachment::create([
            'message_id'    => $pesan->id,
            'original_name' => 'acuan.jpg',
            'mime'          => 'image/jpeg',
            'size_bytes'    => 12,
            'disk'          => 'local',
            'path'          => 'crm/lampiran/2026/09/1.jpg',
            'downloaded_at' => now(),
        ]);

        Storage::disk('local')->put($lampiran->path, 'isi-gambar');

        $this->actingAs($this->admin())
            ->postJson(route('crm.pesan.teruskan', $pesan->id), ['tujuan_id' => $tujuan->id])
            ->assertOk();

        $baru = $tujuan->messages()->latest('id')->first();
        $salinan = $baru->attachments()->first();

        $this->assertNotNull($salinan, 'Lampiran hasil teruskan tidak dibuat.');
        $this->assertNotSame($lampiran->path, $salinan->path, 'Salinan menumpang berkas lama; penyapu masa simpan chat asal akan mengosongkan chat tujuan.');
        $this->assertTrue(Storage::disk('local')->exists($salinan->path));
        $this->assertSame('acuan.jpg', $salinan->original_name);

        // Berkas asal tidak boleh ikut terusik.
        $this->assertTrue(Storage::disk('local')->exists($lampiran->path));
    }

    public function test_lampiran_yang_belum_terunduh_ditolak_dengan_alasan(): void
    {
        $asal   = $this->percakapan();
        $tujuan = $this->percakapan('628111222333');
        $pesan  = $this->pesanMasuk($asal, ['message_type' => 'image', 'content' => null]);

        CrmAttachment::create([
            'message_id'    => $pesan->id,
            'original_name' => 'acuan.jpg',
            'mime'          => 'image/jpeg',
            // Belum terunduh: satu-satunya salinan masih di server Meta.
            'downloaded_at' => null,
        ]);

        $this->actingAs($this->admin())
            ->postJson(route('crm.pesan.teruskan', $pesan->id), ['tujuan_id' => $tujuan->id])
            ->assertStatus(422)
            ->assertJsonPath('pesan', 'Lampirannya belum tersimpan di ERP, jadi belum bisa diteruskan. Unduh dulu lampirannya, lalu ulangi.');
    }

    public function test_daftar_tujuan_mengecualikan_percakapan_asal_dan_melaporkan_jendela(): void
    {
        $asal    = $this->percakapan();
        $terbuka = $this->percakapan('628111222333');
        $tutup   = $this->percakapan('628555666777', terbuka: false);

        $jawaban = $this->actingAs($this->admin())
            ->getJson(route('crm.percakapan.cari', ['kecuali' => $asal->id]))
            ->assertOk()
            ->json('hasil');

        $ids = array_column($jawaban, 'id');

        $this->assertNotContains($asal->id, $ids);
        $this->assertContains($terbuka->id, $ids);

        // Yang tertutup tetap DITAMPILKAN — kalau disembunyikan, admin mengira
        // kontaknya tidak ada lalu mencarinya berulang kali.
        $this->assertContains($tutup->id, $ids);

        $baris = collect($jawaban)->firstWhere('id', $tutup->id);
        $this->assertFalse($baris['terbuka']);
    }

    public function test_teruskan_butuh_login(): void
    {
        $percakapan = $this->percakapan();
        $pesan      = $this->pesanMasuk($percakapan);

        $this->post(route('crm.pesan.teruskan', $pesan->id), ['tujuan_id' => $percakapan->id])
            ->assertRedirect(route('login'));
    }
}
