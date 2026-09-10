<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Layar PWA CRM (`/cs`): daftar chat & satu percakapan.
 *
 * Keduanya meng-include partial yang SAMA dengan Inbox desktop lewat dua
 * penanda (`$rutaChat`, `$modePwa`). Yang dijaga di sini justru dua sisinya
 * sekaligus: PWA menunjuk ke dirinya sendiri, dan desktop TIDAK ikut berubah —
 * bawaan penanda itu yang menahan seluruh Inbox lama tetap seperti semula.
 */
class CrmPwaLayarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['crm.dry_run' => true]);
        app(ChatManager::class)->fake()->reset();
    }

    private function cs(): User
    {
        return User::factory()->create([
            'role' => 'user', 'is_active' => true, 'pwa_crm' => true,
        ]);
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

    /**
     * Ikon PWA Chat WAJIB berbeda dari PWA Karyawan. Keduanya terpasang
     * berdampingan di layar HP yang sama, dan dua ikon kembar berarti CS
     * membuka aplikasi perizinan setiap kali buru-buru membalas pelanggan —
     * kekeliruan yang tak menimbulkan galat apa pun, cuma waktu terbuang.
     */
    public function test_ikon_pwa_chat_berbeda_dari_pwa_karyawan(): void
    {
        $chat     = json_decode(file_get_contents(public_path('cs.webmanifest')), true);
        $karyawan = json_decode(file_get_contents(public_path('karyawan.webmanifest')), true);

        $ikonChat     = array_column($chat['icons'], 'src');
        $ikonKaryawan = array_column($karyawan['icons'], 'src');

        $this->assertSame([], array_intersect($ikonChat, $ikonKaryawan));

        foreach ($ikonChat as $src) {
            $this->assertFileExists(public_path(ltrim($src, '/')));
        }
    }

    /**
     * Keyboard di HP. Bawaan Chrome Android (resizes-visual) TIDAK mengecilkan
     * layout viewport — halamannya cuma digeser ke atas, dan kepala chat, strip
     * Produk/Ongkir/Pesanan, serta percakapannya terdorong keluar layar
     * sementara 100dvh tetap merasa setinggi layar penuh. Satu penanda di meta
     * viewport yang menahannya, dan hilangnya tidak menimbulkan galat apa pun:
     * layarnya cuma jadi tak terpakai di HP, persis keadaan yang diperbaiki.
     */
    public function test_layar_menyusut_saat_keyboard_muncul(): void
    {
        $pengguna = $this->cs();
        $p = $this->percakapan(['owner_user_id' => $pengguna->id]);

        $layar = $this->actingAs($pengguna)->get(route('cs.thread', $p))->assertOk();

        $layar->assertSee('interactive-widget=resizes-content', false);
        // Nilai mundur untuk Safari iOS, yang belum mengenal penanda di atas.
        $layar->assertSee('--tinggi-app', false);
    }

    public function test_daftar_chat_menampilkan_percakapan_dan_menunjuk_ke_layar_pwa(): void
    {
        $pengguna = $this->cs();
        $p = $this->percakapan(['owner_user_id' => $pengguna->id]);

        $this->actingAs($pengguna)
            ->get('/cs')
            ->assertOk()
            ->assertSee('628998844666')
            // Menunjuk ke dirinya sendiri, BUKAN ke /erp/crm/{id} — kalau salah,
            // satu ketukan melempar CS keluar dari aplikasi ke layar desktop.
            ->assertSee('href="' . route('cs.thread', $p->id) . '"', false)
            ->assertDontSee('href="' . route('crm.inbox.show', $p->id) . '"', false);
    }

    public function test_layar_percakapan_terbuka_dengan_tombol_kembali_dan_strip_alat(): void
    {
        $pengguna = $this->cs();
        $p = $this->percakapan(['owner_user_id' => $pengguna->id]);

        $this->actingAs($pengguna)
            ->get('/cs/' . $p->id)
            ->assertOk()
            ->assertSee('628998844666')
            ->assertSee('href="' . route('cs.chat') . '"', false)
            // Alat yang dipakai berkali-kali per chat wajib berdiri sendiri,
            // bukan terkubur di dalam menu titik tiga.
            ->assertSee('Ongkir')
            ->assertSee('Pesanan');
    }

    public function test_membuka_percakapan_menandainya_terbaca(): void
    {
        $pengguna = $this->cs();
        $p = $this->percakapan(['owner_user_id' => $pengguna->id]);

        $this->actingAs($pengguna)->get('/cs/' . $p->id)->assertOk();

        $this->assertSame(0, $p->fresh()->unread_count);
    }

    public function test_notifikasi_dan_profil_tidak_ditangkap_sebagai_id_percakapan(): void
    {
        $this->actingAs($this->cs());

        // '/cs/{conversation}' berdiri paling bawah & dibatasi angka. Tanpa itu
        // dua layar ini hilang jadi 404 begitu rute percakapan ditambahkan.
        $this->get('/cs/notifikasi')->assertOk();
        $this->get('/cs/profil')->assertOk();
    }

    public function test_inbox_desktop_tidak_ikut_berubah(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $p = $this->percakapan();

        $this->actingAs($admin)
            ->get('/erp/crm')
            ->assertOk()
            ->assertSee('href="' . route('crm.inbox.show', $p->id) . '"', false)
            ->assertDontSee('href="' . route('cs.thread', $p->id) . '"', false);
    }

    public function test_thread_desktop_tidak_memakai_tombol_kembali_pwa(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $p = $this->percakapan();

        // Di desktop daftar chatnya masih terlihat di kolom sebelah; tombol
        // kembali di situ hanya membawa orang keluar dari ruang kerjanya.
        $this->actingAs($admin)
            ->get('/erp/crm/' . $p->id)
            ->assertOk()
            ->assertDontSee('href="' . route('cs.chat') . '"', false);
    }
}
