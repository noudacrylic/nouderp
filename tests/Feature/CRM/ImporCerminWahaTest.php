<?php

namespace Tests\Feature\CRM;

use App\Models\CrmSetting;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Support\CrmRuntimeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Impor riwayat chat nomor utama dari WAHA ke cermin.
 *
 * Yang dijaga di sini berpusat pada satu sifat: perintah ini AMAN DIULANG.
 * Impor riwayat adalah proses panjang yang wajar terputus di tengah — koneksi,
 * Ctrl-C, satu chat yang timeout — dan kalau mengulanginya menggandakan
 * pesan, satu-satunya jalan pulih adalah membersihkan basis data dengan
 * tangan. Idempotensinya bukan bonus; ia syarat.
 */
class ImporCerminWahaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CrmRuntimeConfig::forget();
        Http::preventStrayRequests();

        CrmSetting::for('waha')->forceFill([
            'is_enabled' => true,
            'api_key'    => 'kunci-waha',
            'base_url'   => 'http://127.0.0.1:3000',
            'config'     => ['sesi' => [
                'utama'      => ['session' => 'utama'],
                'notifikasi' => ['session' => 'notifikasi'],
            ]],
        ])->save();
    }

    /**
     * Palsukan WAHA: sesi WORKING, daftar chat, dan riwayat per chat.
     *
     * @param  array<string, array>  $riwayat  id chat => daftar pesan
     */
    private function wahaPalsu(array $daftarChat, array $riwayat): void
    {
        $pola = [
            'http://127.0.0.1:3000/api/sessions/utama' => Http::response(['name' => 'utama', 'status' => 'WORKING']),
            'http://127.0.0.1:3000/api/utama/chats*'   => Http::response($daftarChat),
        ];

        foreach ($riwayat as $chatId => $pesan) {
            $pola['http://127.0.0.1:3000/api/utama/chats/' . rawurlencode($chatId) . '/messages*']
                = Http::response($pesan);
        }

        // Urutannya penting: pola chats/* harus didaftarkan SEBELUM chats*,
        // kalau tidak daftar chat menelan permintaan riwayat.
        Http::fake(array_reverse($pola, true));
    }

    private function pesan(string $id, string $isi, bool $dariKita = false, int $waktu = 1758700000): array
    {
        return [
            'id'        => $id,
            'timestamp' => $waktu,
            'fromMe'    => $dariKita,
            'body'      => $isi,
            'hasMedia'  => false,
        ];
    }

    public function test_riwayat_ditarik_jadi_percakapan_cermin(): void
    {
        $this->wahaPalsu(
            [['id' => '628123456789@s.whatsapp.net', 'conversationTimestamp' => 1758700000]],
            ['628123456789@s.whatsapp.net' => [
                $this->pesan('A1', 'Kak box 60x80 berapa?', false, 1758600000),
                $this->pesan('A2', 'Rp 250.000 kak', true, 1758700000),
            ]]
        );

        $this->artisan('crm:impor-cermin-waha')->assertSuccessful();

        $percakapan = CrmConversation::sole();

        $this->assertSame(CrmConversation::KANAL_CERMIN, $percakapan->channel);
        $this->assertSame('628123456789', $percakapan->contact_key);
        $this->assertSame(2, $percakapan->messages()->count());

        // Arahnya terbaca dari fromMe, bukan ditebak dari urutan.
        $this->assertSame(CrmMessage::MASUK, CrmMessage::where('provider_message_id', 'waha:A1')->sole()->direction);
        $this->assertSame(CrmMessage::KELUAR, CrmMessage::where('provider_message_id', 'waha:A2')->sole()->direction);
    }

    /**
     * SYARAT, bukan bonus. Impor riwayat wajar terputus di tengah; kalau
     * mengulanginya menggandakan pesan, satu-satunya jalan pulih adalah
     * membersihkan basis data dengan tangan.
     */
    public function test_diulang_tidak_menggandakan(): void
    {
        $this->wahaPalsu(
            [['id' => '628123456789@s.whatsapp.net', 'conversationTimestamp' => 1758700000]],
            ['628123456789@s.whatsapp.net' => [$this->pesan('A1', 'halo')]]
        );

        $this->artisan('crm:impor-cermin-waha')->assertSuccessful();
        $this->artisan('crm:impor-cermin-waha')->assertSuccessful();

        $this->assertSame(1, CrmMessage::count());
        $this->assertSame(1, CrmConversation::count());
    }

    /**
     * Urutan daftar = terbaru dulu, dan `last_message_at` hanya MAJU.
     *
     * Tanpa penjaga "hanya maju", menyusuri pesan lama akan melempar chat yang
     * baru aktif kemarin ke dasar daftar Inbox dengan tanggal pesan tertuanya.
     */
    public function test_last_message_at_memakai_pesan_terbaru(): void
    {
        $this->wahaPalsu(
            [['id' => '628123456789@s.whatsapp.net', 'conversationTimestamp' => 1758700000]],
            ['628123456789@s.whatsapp.net' => [
                $this->pesan('A2', 'terbaru', false, 1758700000),
                $this->pesan('A1', 'terlama', false, 1700000000),
            ]]
        );

        $this->artisan('crm:impor-cermin-waha')->assertSuccessful();

        $this->assertSame(
            1758700000,
            CrmConversation::sole()->last_message_at->timestamp
        );
    }

    /** Grup, newsletter, dan @lid tidak pernah ikut ditarik. */
    public function test_grup_dan_lid_tidak_ikut_diimpor(): void
    {
        $this->wahaPalsu(
            [
                ['id' => '628123456789-160000@g.us', 'conversationTimestamp' => 1758700000],
                ['id' => '197412345678901@lid', 'conversationTimestamp' => 1758700000],
                ['id' => 'status@broadcast', 'conversationTimestamp' => 1758700000],
            ],
            []
        );

        $this->artisan('crm:impor-cermin-waha')
            ->expectsOutputToContain('Tidak ada chat yang memenuhi saringan')
            ->assertSuccessful();

        $this->assertSame(0, CrmConversation::count());
    }

    /**
     * Sesi yang tidak WORKING mengembalikan daftar kosong atau separuh, dan
     * hasilnya terbaca seperti "riwayatnya memang cuma segitu" — kesimpulan
     * salah yang sulit diralat karena orang akan mengira impor sudah selesai.
     */
    public function test_sesi_tidak_working_membatalkan_impor(): void
    {
        Http::fake([
            'http://127.0.0.1:3000/api/sessions/utama' => Http::response(['name' => 'utama', 'status' => 'STOPPED']),
        ]);

        $this->artisan('crm:impor-cermin-waha')->assertFailed();

        $this->assertSame(0, CrmMessage::count());
    }

    public function test_dry_run_tidak_menulis_apa_pun(): void
    {
        $this->wahaPalsu(
            [['id' => '628123456789@s.whatsapp.net', 'conversationTimestamp' => 1758700000]],
            ['628123456789@s.whatsapp.net' => [$this->pesan('A1', 'halo')]]
        );

        $this->artisan('crm:impor-cermin-waha', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, CrmMessage::count());
        $this->assertSame(0, CrmConversation::count());
    }

    /**
     * Satu chat yang gagal tidak boleh menghentikan sisanya: 500 chat lain
     * ikut tak terimpor demi satu yang rewel.
     */
    public function test_satu_chat_gagal_tidak_menghentikan_sisanya(): void
    {
        Http::fake([
            'http://127.0.0.1:3000/api/utama/chats/' . rawurlencode('628111111111@s.whatsapp.net') . '/messages*'
                => Http::response(['message' => 'boom'], 500),
            'http://127.0.0.1:3000/api/utama/chats/' . rawurlencode('628123456789@s.whatsapp.net') . '/messages*'
                => Http::response([$this->pesan('A1', 'halo')]),
            'http://127.0.0.1:3000/api/utama/chats*' => Http::response([
                ['id' => '628111111111@s.whatsapp.net', 'conversationTimestamp' => 1758700001],
                ['id' => '628123456789@s.whatsapp.net', 'conversationTimestamp' => 1758700000],
            ]),
            'http://127.0.0.1:3000/api/sessions/utama' => Http::response(['name' => 'utama', 'status' => 'WORKING']),
        ]);

        $this->artisan('crm:impor-cermin-waha')->assertSuccessful();

        $this->assertSame(1, CrmMessage::count(), 'chat yang sehat tetap masuk');
        $this->assertSame(1, CrmConversation::count());
    }

    /**
     * Payload sungguhan membawa OBJEK di tempat yang dokumentasinya menyebut
     * string. Dibuktikan 24 Sep 2026: impor 10 chat pertama mati total dengan
     * "Array to string conversion" karena `media.error` ternyata objek.
     *
     * Satu field berbentuk tak terduga tidak boleh menjatuhkan impor 500 chat.
     * Keterangan galatnya tetap disimpan — justru itu yang dicari orang saat
     * menelusuri lampiran yang tak muncul.
     */
    public function test_payload_berbentuk_objek_tidak_menjatuhkan_impor(): void
    {
        $this->wahaPalsu(
            [['id' => '628123456789@s.whatsapp.net', 'conversationTimestamp' => 1758700000]],
            ['628123456789@s.whatsapp.net' => [[
                'id'        => ['_serialized' => 'false_628123@s.whatsapp.net_3EB0X', 'id' => '3EB0X'],
                'timestamp' => 1758700000,
                'fromMe'    => false,
                'body'      => 'ini desainnya',
                'hasMedia'  => true,
                'media'     => [
                    'url'      => null,
                    'mimetype' => 'image/jpeg',
                    'filename' => null,
                    'error'    => ['code' => 'MEDIA_EXPIRED', 'message' => 'media not available'],
                ],
            ]]]
        );

        $this->artisan('crm:impor-cermin-waha', ['--media' => true])->assertSuccessful();

        $pesan = CrmMessage::sole();

        // Objek pembungkus id dibuka lewat kunci yang dikenal, bukan diabaikan.
        $this->assertSame('waha:false_628123@s.whatsapp.net_3EB0X', $pesan->provider_message_id);
        $this->assertSame('ini desainnya', $pesan->content);
        $this->assertSame('image', $pesan->message_type);

        $lampiran = $pesan->attachments()->sole();

        $this->assertNull($lampiran->source_url);

        // Yang diambil adalah `message` yang terbaca manusia, bukan JSON
        // mentahnya: keterangan ini muncul di gelembung, dan CS yang membacanya
        // butuh "media not available", bukan struktur objek. Kode galatnya
        // tidak hilang — payload utuh tersimpan di kolom `raw`.
        $this->assertStringContainsString('media not available', (string) $lampiran->download_error);
        $this->assertSame('MEDIA_EXPIRED', data_get($pesan->raw, 'media.error.code'));
    }

    public function test_saringan_sejak_membuang_chat_lama(): void
    {
        // Stempel waktunya RELATIF terhadap sekarang, bukan angka tetap.
        // Angka tetap membuat tes ini lapuk diam-diam begitu tanggalnya
        // terlewati — gejalanya "saringan tiba-tiba membuang semuanya".
        $this->wahaPalsu(
            [
                ['id' => '628123456789@s.whatsapp.net', 'conversationTimestamp' => now()->subDays(3)->timestamp],
                ['id' => '628111111111@s.whatsapp.net', 'conversationTimestamp' => now()->subYears(5)->timestamp],
            ],
            [
                '628123456789@s.whatsapp.net' => [$this->pesan('A1', 'baru')],
                '628111111111@s.whatsapp.net' => [$this->pesan('B1', 'lama')],
            ]
        );

        $this->artisan('crm:impor-cermin-waha', ['--sejak' => now()->subYear()->format('Y-m-d')])
            ->assertSuccessful();

        $this->assertSame(1, CrmConversation::count());
        $this->assertSame('628123456789', CrmConversation::sole()->contact_key);
    }
}
