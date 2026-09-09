<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\CRM\Models\CrmConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mengoper percakapan ke agen lain.
 *
 * Yang dijaga di sini satu hal yang kegagalannya tak bergejala: chat yang
 * dioper harus MENDARAT SEBAGAI PEKERJAAN BARU di daftar penerimanya. Tanpa
 * tanda "belum dibaca", ia datang dalam keadaan sudah terbaca — karena yang
 * mengoper baru saja membukanya — lalu tenggelam di antara chat lama. Tidak
 * ada gejala, tidak ada keluhan, sampai pelanggannya sendiri yang menagih.
 */
class OperChatTest extends TestCase
{
    use RefreshDatabase;

    private function agen(string $nama): User
    {
        return User::factory()->create(['name' => $nama, 'role' => 'admin', 'is_active' => true]);
    }

    private function chat(): CrmConversation
    {
        return CrmConversation::create([
            'channel'      => 'whatsapp',
            'contact_key'  => '628111111111',
            'display_name' => 'Fahri',
            'status'       => CrmConversation::STATUS_AKTIF,
            'unread_count' => 0,
        ]);
    }

    public function test_oper_ke_agen_lain_menandai_belum_dibaca(): void
    {
        $chat  = $this->chat();
        $burhan = $this->agen('Burhan');

        $this->actingAs($this->agen('Sari'))
            ->post(route('crm.inbox.oper', $chat), ['owner_user_id' => $burhan->id])
            ->assertRedirect();

        $chat->refresh();

        $this->assertSame($burhan->id, $chat->owner_user_id);
        $this->assertGreaterThan(0, $chat->unread_count);
    }

    /**
     * Dilempar ke daftar, bukan kembali ke chatnya. Kalau kembali, show()
     * langsung menghapus tanda yang baru saja dipasang — dan pekerjaannya
     * memang sudah pindah tangan.
     */
    public function test_setelah_dioper_kembali_ke_daftar_bukan_ke_chatnya(): void
    {
        $chat = $this->chat();

        $this->actingAs($this->agen('Sari'))
            ->post(route('crm.inbox.oper', $chat), ['owner_user_id' => $this->agen('Burhan')->id])
            ->assertRedirect(route('crm.inbox.index'));

        // Tandanya masih berdiri setelah pengalihan.
        $this->assertGreaterThan(0, $chat->fresh()->unread_count);
    }

    /** Melepas kepemilikan juga pekerjaan baru — bagi siapa pun yang memungutnya. */
    public function test_melepas_kepemilikan_juga_menandai_belum_dibaca(): void
    {
        $sari = $this->agen('Sari');
        $chat = $this->chat();
        $chat->forceFill(['owner_user_id' => $this->agen('Burhan')->id])->save();

        $this->actingAs($sari)
            ->post(route('crm.inbox.oper', $chat), ['owner_user_id' => ''])
            ->assertRedirect();

        $chat->refresh();

        $this->assertNull($chat->owner_user_id);
        $this->assertGreaterThan(0, $chat->unread_count);
    }

    /**
     * Mengambil alih untuk DIRI SENDIRI tidak menandai apa pun — orangnya
     * sedang membaca chat itu sekarang juga, dan tanda "belum dibaca" di
     * situ cuma kebohongan yang harus dibersihkan sendiri.
     */
    public function test_mengambil_alih_sendiri_tidak_menandai_belum_dibaca(): void
    {
        $sari = $this->agen('Sari');
        $chat = $this->chat();

        $this->actingAs($sari)
            ->post(route('crm.inbox.oper', $chat), ['owner_user_id' => $sari->id])
            ->assertRedirect();

        $chat->refresh();

        $this->assertSame($sari->id, $chat->owner_user_id);
        $this->assertSame(0, $chat->unread_count);
    }

    /** Menyimpan pemilik yang sama = bukan pengoperan; tak ada yang perlu disadarkan. */
    public function test_pemilik_yang_tidak_berubah_tidak_menandai_apa_pun(): void
    {
        $burhan = $this->agen('Burhan');
        $chat   = $this->chat();
        $chat->forceFill(['owner_user_id' => $burhan->id])->save();

        $this->actingAs($this->agen('Sari'))
            ->post(route('crm.inbox.oper', $chat), ['owner_user_id' => $burhan->id])
            ->assertRedirect();

        $this->assertSame(0, $chat->fresh()->unread_count);
    }

    /** Chat yang sudah punya pesan belum dibaca tidak dinaikkan hitungannya. */
    public function test_hitungan_belum_dibaca_yang_sudah_ada_tidak_bertambah(): void
    {
        $chat = $this->chat();
        $chat->forceFill(['unread_count' => 4])->save();

        $this->actingAs($this->agen('Sari'))
            ->post(route('crm.inbox.oper', $chat), ['owner_user_id' => $this->agen('Burhan')->id]);

        $this->assertSame(4, $chat->fresh()->unread_count);
    }
}
