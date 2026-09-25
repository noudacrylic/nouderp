<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Support\BelumDibaca;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lencana chat belum dibaca di menu CRM (sidebar).
 *
 * Yang dijaga bukan angkanya melainkan CAKUPANNYA. Lencana yang menghitung
 * lebih banyak dari yang muncul setelah diklik adalah cara tercepat membuat
 * orang berhenti mempercayainya: ia menjanjikan pekerjaan yang tidak ada di
 * layar yang ia buka, dan sesudah dua kali begitu angkanya diabaikan
 * selamanya — termasuk saat ia benar.
 */
class LencanaBelumDibacaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function agen(): User
    {
        return User::factory()->create(['role' => 'user', 'is_active' => true]);
    }

    private function chat(int $belum, ?int $pemilik = null, string $status = CrmConversation::STATUS_AKTIF): CrmConversation
    {
        static $urut = 0;
        $urut++;

        $p = CrmConversation::findOrCreateFor('62812340' . str_pad((string) $urut, 5, '0', STR_PAD_LEFT));
        $p->forceFill(['unread_count' => $belum, 'owner_user_id' => $pemilik, 'status' => $status])->save();

        return $p;
    }

    /** Yang dihitung CHAT, bukan pesan — supaya sama dengan chip "Belum dibaca". */
    public function test_menghitung_chat_bukan_jumlah_pesan(): void
    {
        $admin = $this->admin();

        $this->chat(7);
        $this->chat(3);
        $this->chat(0);

        $this->assertSame(2, BelumDibaca::untuk($admin));
    }

    /**
     * Agen biasa hanya menghitung chat yang IA pegang.
     *
     * Cakupan ini menirukan daftar bawaan yang terbuka saat menu CRM ditekan.
     * Kalau lencananya menghitung seluruh inbox, agen melihat angka 12 lalu
     * membuka layar yang cuma berisi 2 — dan tidak ada apa pun di layar itu
     * yang menjelaskan ke mana sepuluh sisanya pergi.
     */
    public function test_agen_hanya_menghitung_chat_miliknya(): void
    {
        $agen = $this->agen();
        $lain = $this->agen();

        $this->chat(1, $agen->id);
        $this->chat(1, $lain->id);
        $this->chat(1, null);

        $this->assertSame(1, BelumDibaca::untuk($agen));
    }

    /** Super admin memang melihat semuanya, termasuk yang belum dioper. */
    public function test_super_admin_menghitung_seluruh_inbox(): void
    {
        $admin = $this->admin();
        $agen  = $this->agen();

        $this->chat(1, $agen->id);
        $this->chat(1, null);

        $this->assertSame(2, BelumDibaca::untuk($admin));
    }

    /** Chat yang sudah diarsipkan bukan pekerjaan yang menunggu. */
    public function test_arsip_tidak_ikut_dihitung(): void
    {
        $admin = $this->admin();

        $this->chat(5, null, CrmConversation::STATUS_ARSIP);

        $this->assertSame(0, BelumDibaca::untuk($admin));
    }

    /** Tamu tidak punya inbox — dan tidak boleh memicu kueri yang gagal. */
    public function test_tanpa_pengguna_menjawab_nol(): void
    {
        $this->assertSame(0, BelumDibaca::untuk(null));
    }

    /* ------------------------------------------------------------- tampilan */

    /** Lencananya benar-benar tergambar di sidebar, dengan angkanya. */
    public function test_lencana_tampil_di_sidebar(): void
    {
        $admin = $this->admin();
        $this->chat(4);

        /* Dicocokkan ATRIBUTNYA, bukan sekadar kata 'crm-lencana': kata itu
           juga muncul di aturan CSS yang selalu ikut terkirim, jadi pemeriksaan
           atas kata saja hijau bahkan ketika lencananya tidak pernah
           digambar. */
        $this->actingAs($admin)->get('/erp/crm')->assertOk()
            ->assertSee('class="crm-lencana"', false);
    }

    /**
     * Nol berarti TIDAK ADA lencana sama sekali — bukan lencana bertuliskan "0".
     *
     * Titik merah yang selalu menyala berhenti berarti dalam sehari, dan sejak
     * itu ia tidak bisa lagi dipakai membedakan "ada yang menunggu" dari
     * "semuanya beres".
     */
    public function test_tanpa_chat_belum_dibaca_lencana_tidak_digambar(): void
    {
        $admin = $this->admin();
        $this->chat(0);

        $this->actingAs($admin)->get('/erp/crm')->assertOk()
            ->assertDontSee('class="crm-lencana"', false);
    }

    /* --------------------------------------------- penanda di baris daftar */

    /**
     * Baris yang belum dibaca tampak BERBEDA, tidak cuma punya angka kecil.
     *
     * Di daftar sepanjang ratusan baris, satu titik pucat di pojok hilang
     * begitu saja — yang dicari mata saat menggulir cepat adalah baris yang
     * berbeda, bukan angka yang harus ditemukan lebih dulu.
     */
    public function test_baris_belum_dibaca_punya_penanda_sendiri(): void
    {
        $admin = $this->admin();
        $this->chat(3);

        $html = $this->actingAs($admin)->get('/erp/crm')->assertOk()->getContent();

        $this->assertStringContainsString('data-belum-dibaca', $html);
        $this->assertStringContainsString('font-bold text-gray-900', $html, 'nama chat dicetak tebal');
    }

    /** Chat yang sudah dibaca tidak boleh ikut ditandai. */
    public function test_baris_sudah_dibaca_tidak_ditandai(): void
    {
        $admin = $this->admin();
        $this->chat(0);

        $this->actingAs($admin)->get('/erp/crm')->assertOk()
            ->assertDontSee('data-belum-dibaca', false);
    }

    /* ------------------------------------------------------------ PWA `/cs` */

    /**
     * PWA CRM punya lencana yang SAMA di ikon Chat pada bilah bawah.
     *
     * Dan angkanya dari penghitung yang sama dengan sidebar ERP — dua
     * penghitung terpisah untuk pertanyaan yang sama pasti menyimpang, dan yang
     * menyimpang di sini adalah angka yang dipakai CS memutuskan perlu membuka
     * aplikasinya atau tidak.
     */
    public function test_pwa_menampilkan_lencana_chat_di_bilah_bawah(): void
    {
        $cs = User::factory()->create(['role' => 'user', 'is_active' => true, 'pwa_crm' => true]);

        $this->chat(2, $cs->id);
        $this->chat(1, $cs->id);

        $this->actingAs($cs)->get('/cs')->assertOk()
            // Ditunjuk lewat `data-lencana`: lencana lonceng memakai markup
            // yang sama persis, jadi memeriksa kelasnya saja akan hijau walau
            // yang tergambar lonceng — bukan chat.
            ->assertSee('data-lencana="chat"', false)
            ->assertDontSee('data-lencana="bell"', false);
    }

    /** Cakupan per-peran ikut berlaku di PWA, bukan cuma di sidebar ERP. */
    public function test_pwa_tidak_menghitung_chat_agen_lain(): void
    {
        $cs   = User::factory()->create(['role' => 'user', 'is_active' => true, 'pwa_crm' => true]);
        $lain = $this->agen();

        $this->chat(9, $lain->id);

        $this->assertSame(0, BelumDibaca::untuk($cs));
    }

    /** Angka besar dipendekkan supaya tidak menggeser ikonnya. */
    public function test_angka_besar_dipendekkan(): void
    {
        $admin = $this->admin();

        for ($i = 0; $i < 101; $i++) {
            $this->chat(1);
        }

        $this->assertSame(101, BelumDibaca::untuk($admin));

        $this->actingAs($admin)->get('/erp/crm')->assertOk()->assertSee('99+');
    }
}
